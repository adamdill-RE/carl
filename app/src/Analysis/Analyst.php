<?php

declare(strict_types=1);

namespace Carl\Analysis;

use Carl\Core\App;
use Carl\Core\Database;
use Carl\Core\HttpClient;
use Carl\Repo\UserRepository;
use Throwable;

/**
 * The analysis queue and the job that drains it (Phase 5 handoff Section 3.1).
 *
 * It is `Carl\Mail\Outbox` with a different third party, on purpose, because
 * the two rules are the same two:
 *
 *  1. **Nothing calls the API inline in a request.** A page calls request()
 *     and returns; the drain calls Anthropic; the answer appears on the next
 *     page load. This is the first third-party call in Carl that is neither
 *     weather nor mail, and Phase 3 handoff Section 5 is absolute about it.
 *  2. **Every run writes an `analysis_run` row**, success, failure or nothing
 *     to do -- because a cron that silently stops is otherwise invisible for
 *     months.
 *
 * With no API key configured -- the state until the owner puts one in
 * `config/local.php` -- requests queue and stay queued. They are not failed
 * and not dropped, exactly as mail behaves before the mailbox exists, so
 * request() is safe to call now and the backlog goes out the day the key
 * lands.
 *
 * One thing here that the outbox does not need: a **lease**. An analysis
 * takes tens of seconds, and the browser fallback for the cron runs under a
 * 30 s ceiling on a shared host that kills the process without leaving a PHP
 * error behind (hosting Section 4). A row marked 'sending' whose lease has
 * expired is therefore a row whose previous attempt died silently; the next
 * run counts it as a failed attempt and backs it off, rather than either
 * retrying it for ever or leaving it stuck.
 */
final class Analyst
{
    /** @deprecated Phase 6: `Scope::SEASON` is the same value with a grammar. */
    public const SCOPE_SEASON = Scope::SEASON;

    private Database $db;

    public function __construct(private App $app, private ?Provider $provider = null)
    {
        $this->db = $app->db();
    }

    /**
     * Queue one analysis for a user.
     *
     * @param string $today the gardener's OWN local day (handoff Section 6)
     * @return int the row id, or 0 when an identical request is already queued
     */
    public function request(
        int $userId,
        string $today,
        ?string $question = null,
        ?Scope $scope = null,
    ): int {
        $scope ??= Scope::season();
        $question = $question === null ? null : \trim($question);
        $question = ($question === null || $question === '') ? null : \substr($question, 0, 500);

        // One row per user per local day per question. Enforced by the
        // unique index rather than by reading first (hosting Section 7): a
        // double-tapped button races itself, and the loser learns that from
        // the duplicate-key error rather than from a check that was true a
        // millisecond ago.
        // The scope is part of the key: asking about the whole season and
        // asking about one bed on the same day are two different questions,
        // and one must not silently swallow the other.
        $dedupe = $scope->value() . ':' . $userId . ':' . $today . ':'
            . \substr(\hash('sha256', (string) $question), 0, 16);

        try {
            $this->db->run(
                'INSERT INTO `analysis`'
                . ' (user_id, scope, requested_on, question, status, attempts, next_attempt_at,'
                . '  created_at, dedupe_key)'
                . " VALUES (:user_id, :scope, :requested_on, :question, 'queued', 0,"
                . '  UTC_TIMESTAMP(), UTC_TIMESTAMP(), :dedupe_key)',
                [
                    'user_id'      => $userId,
                    'scope'        => $scope->value(),
                    'requested_on' => $today,
                    'question'     => $question,
                    'dedupe_key'   => $dedupe,
                ]
            );
        } catch (\PDOException $e) {
            // 23000 is the integrity-constraint class, and the only unique
            // index on this statement is the dedupe key -- so it means
            // "already asked", which is what the caller wanted.
            if ($e->getCode() === '23000') {
                return 0;
            }
            throw $e;
        }

        return $this->db->insertId();
    }

    /**
     * How many analyses this user has already asked for today.
     *
     * The cap this feeds is about money, not abuse: every row is a paid API
     * call, and a button that can be pressed fifty times is a button that
     * will be.
     */
    public function countToday(int $userId, string $today): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM `analysis`'
            . ' WHERE `user_id` = :user_id AND `requested_on` = :today',
            ['user_id' => $userId, 'today' => $today],
            0
        );
    }

    /**
     * Answer what is due, oldest first.
     *
     * @param float $budgetSeconds stop starting new requests past this. The
     *        browser twin runs under a 30 s ceiling; the CLI passes 0 for no
     *        budget at all.
     * @return array{model:string,considered:int,completed:int,failed:int,outcome:string,log:list<string>}
     */
    public function drain(?int $limit = null, float $budgetSeconds = 0.0): array
    {
        $startedAt = $this->app->clock()->utcStamp();
        $startedClock = \microtime(true);
        $limit ??= $this->app->config()->int('analysis.batch', 3);
        $log = [];

        $this->reclaimExpiredLeases($log);

        $provider = $this->provider ?? $this->driver();
        if ($provider === null) {
            $waiting = $this->queuedCount();
            $log[] = $waiting === 0
                ? 'no analysis API key configured, and nothing waiting'
                : 'no analysis API key configured; ' . $waiting . ' request'
                  . ($waiting === 1 ? '' : 's') . ' waiting for one';
            $this->recordRun($startedAt, '', 0, 0, 0, 'skipped',
                'analysis.api.key is empty -- see docs/deploy.md Section 7.6');
            return ['model' => '', 'considered' => 0, 'completed' => 0, 'failed' => 0,
                    'outcome' => 'skipped', 'log' => $log];
        }

        $rows = $this->due($limit);
        $completed = 0;
        $failed = 0;
        $considered = 0;

        foreach ($rows as $row) {
            if ($budgetSeconds > 0.0 && (\microtime(true) - $startedClock) >= $budgetSeconds) {
                $log[] = 'out of time; the rest wait for the next run';
                break;
            }

            $id = (int) $row['id'];
            $considered++;

            if (!$this->lease($id)) {
                // Another run took it between the read and here.
                $log[] = '#' . $id . ': taken by another run';
                continue;
            }

            try {
                $reply = $this->answer($provider, $row);
            } catch (Throwable $e) {
                // A fault in Carl's own document building, not the API's.
                // Permanent by default: the same rows will build the same
                // broken document on the next run.
                $reply = Reply::failed('Carl could not build the document: ' . $e->getMessage(), false);
            }

            if ($reply->ok) {
                $this->markDone($id, $reply);
                $completed++;
                $log[] = '#' . $id . ': answered by ' . $reply->model
                    . ' (' . $reply->inputTokens . ' in, ' . $reply->outputTokens . ' out)'
                    . ($reply->truncated ? ' -- CUT OFF at max_tokens' : '');
            } else {
                $exhausted = $this->markAttempt($id, (int) $row['attempts'] + 1, $reply);
                $failed++;
                $log[] = '#' . $id . ': ' . ($exhausted ? 'FAILED' : 'retry ' . ((int) $row['attempts'] + 1))
                    . ' -- ' . $reply->error;
            }
        }

        $this->prune();

        $outcome = match (true) {
            $failed === 0    => 'ok',
            $completed > 0   => 'partial',
            default          => 'failed',
        };

        $this->recordRun($startedAt, $provider->model(), $considered, $completed, $failed, $outcome);

        return ['model' => $provider->model(), 'considered' => $considered, 'completed' => $completed,
                'failed' => $failed, 'outcome' => $outcome, 'log' => $log];
    }

    /**
     * The configured provider, or null when there is no key.
     *
     * A blank key is the same as no provider, not a misconfiguration: it is
     * the state the install is in until the owner adds one, and treating it
     * as an error would fail every queued request five times over.
     */
    public function driver(): ?Provider
    {
        $config = $this->app->config();
        $key = $config->string('analysis.api.key');
        if ($key === '') {
            return null;
        }

        return new ClaudeClient(
            new HttpClient(
                $config->string('weather.user_agent'),
                $config->int('analysis.http_timeout', 120),
            ),
            $config->string('analysis.api.url'),
            $key,
            $config->string('analysis.model'),
            $config->int('analysis.max_tokens', 2000),
            $config->string('analysis.effort'),
        );
    }

    /** One line for /status and the Recommendations page. Never the key. */
    public function describeDriver(): string
    {
        $config = $this->app->config();
        if ($config->string('analysis.api.key') === '') {
            return 'no API key -- requests queue and wait (docs/deploy.md Section 7.6)';
        }
        return $config->string('analysis.model') . ' at ' . $config->string('analysis.api.url');
    }

    // -- One request -------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function answer(Provider $provider, array $row): Reply
    {
        $userRow = (new UserRepository($this->db))->find((int) $row['user_id']);
        if ($userRow === null) {
            return Reply::failed('That account no longer exists.', false);
        }
        $user = \Carl\Auth\User::fromRow($userRow);

        $scope = Scope::parse($row['scope'] === null ? null : (string) $row['scope']);
        $document = Document::forUser($this->app, $user);
        $built = $document->build($user, (string) $row['requested_on'], $scope);
        $json = $document->encode($built);

        // Recorded whether the call succeeds or not: the size question of
        // Phase 5 handoff Section 3.1 is answerable from the live data only
        // if the size is written down even on the runs that failed.
        $this->db->run(
            'UPDATE `analysis` SET `document_bytes` = :bytes WHERE `id` = :id',
            ['bytes' => \strlen($json), 'id' => (int) $row['id']]
        );

        $maxBytes = $this->app->config()->int('analysis.max_document_bytes', 1048576);
        if (\strlen($json) > $maxBytes) {
            // Not retryable: the same account will build the same document
            // next time. This is the tripwire for the bound in Document, not
            // an expected outcome -- if it ever fires, the summary is not
            // summarising something.
            return Reply::failed(
                'The document is ' . \strlen($json) . ' bytes, over the ' . $maxBytes
                . '-byte limit. Narrow analysis.days, or the summary has stopped summarising.',
                false
            );
        }

        return $provider->analyse(
            Prompt::system(),
            Prompt::user(
                $json,
                $row['question'] === null ? null : (string) $row['question'],
                $scope->isSeason() ? null : $scope->describe($this->subjectName($scope, $user->id))
            )
        );
    }

    /**
     * The name of a scope's subject, for the prompt and the page.
     *
     * One statement, and only for a scoped request -- a season analysis never
     * asks. Scoped to the owner, so a subject that is not theirs comes back
     * null and the prompt falls back to the id, which is what the document
     * will be empty about anyway.
     */
    public function subjectName(Scope $scope, int $userId): ?string
    {
        if ($scope->isSeason() || $scope->subjectId === null) {
            return null;
        }

        $name = $scope->kind === Scope::GARDEN
            ? $this->db->value(
                'SELECT `name` FROM `garden` WHERE `id` = :id AND `user_id` = :user_id',
                ['id' => $scope->subjectId, 'user_id' => $userId]
            )
            : $this->db->value(
                'SELECT COALESCE(NULLIF(p.label, \'\'), pt.type) FROM `planting` p'
                . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
                . ' WHERE p.id = :id AND p.user_id = :user_id',
                ['id' => $scope->subjectId, 'user_id' => $userId]
            );

        return \is_string($name) && $name !== '' ? $name : null;
    }

    // -- Rows ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function due(int $limit): array
    {
        return $this->db->all(
            "SELECT * FROM `analysis` WHERE `status` = 'queued'"
            . ' AND `next_attempt_at` <= UTC_TIMESTAMP()'
            . ' ORDER BY `id` LIMIT ' . (int) $limit
        );
    }

    public function queuedCount(): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM `analysis` WHERE `status` = 'queued'", [], 0
        );
    }

    /** The most recent answers for one user, newest first. @return list<array<string,mixed>> */
    public function forUser(int $userId, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT `id`, `scope`, `requested_on`, `question`, `status`, `attempts`, `model`,'
            . ' `document_bytes`, `input_tokens`, `output_tokens`, `answer`, `last_error`,'
            . ' `created_at`, `completed_at`'
            . ' FROM `analysis` WHERE `user_id` = :user_id'
            . ' ORDER BY `id` DESC LIMIT ' . (int) $limit,
            ['user_id' => $userId]
        );
    }

    /** @return array<string,mixed>|null one row, scoped to its owner */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->db->one(
            'SELECT * FROM `analysis` WHERE `id` = :id AND `user_id` = :user_id',
            ['id' => $id, 'user_id' => $userId]
        );
    }

    /** @return array{queued:int,done:int,failed:int,oldest_queued:?string} */
    public function health(): array
    {
        $row = $this->db->one(
            "SELECT SUM(`status` IN ('queued','sending')) AS queued,"
            . " SUM(`status` = 'done') AS done, SUM(`status` = 'failed') AS failed,"
            . " MIN(CASE WHEN `status` = 'queued' THEN `created_at` END) AS oldest_queued"
            . ' FROM `analysis`'
        );
        return [
            'queued'        => (int) ($row['queued'] ?? 0),
            'done'          => (int) ($row['done'] ?? 0),
            'failed'        => (int) ($row['failed'] ?? 0),
            'oldest_queued' => \is_string($row['oldest_queued'] ?? null) ? $row['oldest_queued'] : null,
        ];
    }

    /**
     * What the analyses have cost, by month and by model (Phase 6 handoff
     * Section 3.5: "input_tokens, output_tokens and document_bytes are all
     * stored per row and nothing displays them").
     *
     * One statement. The money is worked out in PHP from the rates in
     * `config/app.php`, because a price list belongs in configuration rather
     * than in a column: rates change, and re-pricing history at read time is
     * right where storing a dollar figure per row would freeze yesterday's
     * rate into the record for ever.
     *
     * A row whose model has no configured rate comes back with a null cost
     * rather than a zero. Zero would read as free.
     *
     * @return list<array{month:string,model:string,runs:int,failed:int,
     *                    input_tokens:int,output_tokens:int,document_bytes:int,cost:?float}>
     */
    public function costByMonth(int $months = 12): array
    {
        $rows = $this->db->all(
            "SELECT DATE_FORMAT(`created_at`, '%Y-%m') AS `month`,"
            . " COALESCE(NULLIF(`model`, ''), '(none)') AS `model`,"
            . ' COUNT(*) AS `runs`,'
            . " SUM(`status` = 'failed') AS `failed`,"
            . ' SUM(`input_tokens`) AS `input_tokens`,'
            . ' SUM(`output_tokens`) AS `output_tokens`,'
            . ' SUM(`document_bytes`) AS `document_bytes`'
            . ' FROM `analysis`'
            . ' WHERE `created_at` >= DATE_SUB(UTC_DATE(), INTERVAL :months MONTH)'
            . ' GROUP BY `month`, `model`'
            . ' ORDER BY `month` DESC, `model`',
            ['months' => \max(1, $months)]
        );

        /** @var array<string,array{input:float,output:float}> $prices */
        $prices = (array) $this->app->config()->get('analysis.prices', []);

        $out = [];
        foreach ($rows as $row) {
            $model = (string) $row['model'];
            $rate = $prices[$model] ?? null;
            $input = (int) $row['input_tokens'];
            $output = (int) $row['output_tokens'];

            $out[] = [
                'month'          => (string) $row['month'],
                'model'          => $model,
                'runs'           => (int) $row['runs'],
                'failed'         => (int) $row['failed'],
                'input_tokens'   => $input,
                'output_tokens'  => $output,
                'document_bytes' => (int) $row['document_bytes'],
                'cost'           => $rate === null
                    ? null
                    : $input / 1000000 * (float) $rate['input']
                        + $output / 1000000 * (float) $rate['output'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null the last drain, for /status */
    public function lastRun(): ?array
    {
        return $this->db->one('SELECT * FROM `analysis_run` ORDER BY `id` DESC LIMIT 1');
    }

    /**
     * Take the row, or find out somebody else did.
     *
     * A compare-and-swap on status, not a read-then-write: two crons
     * overlapping -- which is exactly what happens when one run is slow
     * enough that the next fires -- must not both pay for the same analysis.
     */
    private function lease(int $id): bool
    {
        $minutes = $this->app->config()->int('analysis.lease_minutes', 10);
        return $this->db->run(
            "UPDATE `analysis` SET `status` = 'sending',"
            . ' `leased_until` = (UTC_TIMESTAMP() + INTERVAL :minutes MINUTE)'
            . " WHERE `id` = :id AND `status` = 'queued'",
            ['minutes' => $minutes, 'id' => $id]
        )->rowCount() === 1;
    }

    /**
     * A row left in 'sending' by a process that died. It counts as an
     * attempt: a request that kills the process every time must eventually
     * stop being retried, and the alternative is a row that is retried for
     * ever and a cron that never finishes.
     *
     * @param list<string> $log
     */
    private function reclaimExpiredLeases(array &$log): void
    {
        $stale = $this->db->all(
            "SELECT `id`, `attempts` FROM `analysis` WHERE `status` = 'sending'"
            . ' AND `leased_until` IS NOT NULL AND `leased_until` < UTC_TIMESTAMP()'
        );
        foreach ($stale as $row) {
            $this->markAttempt(
                (int) $row['id'],
                (int) $row['attempts'] + 1,
                Reply::failed('The previous run did not finish -- the process was cut off.', true)
            );
            $log[] = '#' . $row['id'] . ': lease expired, counted as an attempt';
        }
    }

    private function markDone(int $id, Reply $reply): void
    {
        $this->db->run(
            "UPDATE `analysis` SET `status` = 'done', `attempts` = `attempts` + 1,"
            . ' `leased_until` = NULL, `model` = :model, `input_tokens` = :input,'
            . ' `output_tokens` = :output, `answer` = :answer, `last_error` = :error,'
            . ' `completed_at` = UTC_TIMESTAMP() WHERE `id` = :id',
            [
                'model'  => $reply->model,
                'input'  => $reply->inputTokens,
                'output' => $reply->outputTokens,
                'answer' => $reply->text,
                // Not an error, but the page has to be able to say the answer
                // stops mid-sentence, and this is the column that survives.
                'error'  => $reply->truncated
                    ? 'The answer reached the length limit and is cut off.' : null,
                'id'     => $id,
            ]
        );
    }

    /** @return bool whether this was the last attempt */
    private function markAttempt(int $id, int $attempts, Reply $reply): bool
    {
        $exhausted = !$reply->retryable
            || $attempts >= $this->app->config()->int('analysis.max_attempts', 4);

        $this->db->run(
            'UPDATE `analysis` SET `status` = :status, `attempts` = :attempts,'
            . ' `leased_until` = NULL, `last_error` = :error, `next_attempt_at` = :next,'
            . ' `completed_at` = :completed WHERE `id` = :id',
            [
                'status'    => $exhausted ? 'failed' : 'queued',
                'attempts'  => $attempts,
                'error'     => $reply->error,
                'next'      => $this->nextAttemptAt($attempts),
                'completed' => $exhausted ? $this->app->clock()->utcStamp() : null,
                'id'        => $id,
            ]
        );

        return $exhausted;
    }

    /** Backoff from config, holding at the last step once it runs out. */
    private function nextAttemptAt(int $attempts): string
    {
        $steps = $this->app->config()->get('analysis.retry_minutes');
        $steps = \is_array($steps) && $steps !== [] ? \array_values($steps) : [5, 30, 180];
        $minutes = (int) ($steps[\min($attempts - 1, \count($steps) - 1)] ?? 180);

        return $this->app->clock()->nowUtc()
            ->modify('+' . \max(0, $minutes) . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Old answers go; failures stay, because a failure nobody ever sees is
     * the thing the run table exists to prevent.
     */
    public function prune(): int
    {
        $days = $this->app->config()->int('analysis.retention_days', 365);
        $removed = $this->db->run(
            "DELETE FROM `analysis` WHERE `status` = 'done'"
            . ' AND `completed_at` < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $days]
        )->rowCount();

        $this->db->run(
            'DELETE FROM `analysis_run` WHERE `started_at` < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $this->app->config()->int('weather.run_retention_days', 90)]
        );

        return $removed;
    }

    private function recordRun(
        string $startedAt,
        string $model,
        int $considered,
        int $completed,
        int $failed,
        string $outcome,
        ?string $error = null,
    ): void {
        $this->db->run(
            'INSERT INTO `analysis_run`'
            . ' (started_at, finished_at, model, considered, completed, failed, outcome, error_text)'
            . ' VALUES (:started_at, UTC_TIMESTAMP(), :model, :considered, :completed, :failed,'
            . '  :outcome, :error)',
            [
                'started_at' => $startedAt,
                'model'      => \substr($model, 0, 64),
                'considered' => $considered,
                'completed'  => $completed,
                'failed'     => $failed,
                'outcome'    => $outcome,
                'error'      => $error,
            ]
        );
    }
}
