<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\App;
use Carl\Core\Database;
use Carl\Core\HttpClient;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * National Weather Service active alerts (handoff Section 8.4), polled every
 * three hours by `bin/alerts_poll.php`.
 *
 * The table and the MOTD display were built in Phase 1; this is the part that
 * fills them. It goes through HttpClient so the bounded retry, the
 * certificate verification and the quota recognition come for free, and it
 * writes a `weather_sync_run` row with kind='alerts' every time, success or
 * failure, like the other two jobs -- a cron that silently stops is otherwise
 * invisible for months.
 *
 * Only the event classes Section 8.4 lists are stored. The service also
 * issues Air Quality Alerts, Special Weather Statements, Rip Current
 * Statements and a long tail of others; a gardener's MOTD that carried all of
 * them would be ignored within a week, and the ones that matter to a garden
 * are exactly the ones listed.
 */
final class AlertPoller
{
    /**
     * The event classes worth a gardener's attention (handoff Section 8.4),
     * lower-cased for matching.
     */
    private const KEPT = [
        'freeze warning',
        'frost advisory',
        'hard freeze warning',
        'hard freeze watch',
        'freeze watch',
        'heat advisory',
        'excessive heat warning',
        'excessive heat watch',
        'extreme heat warning',
        'extreme heat watch',
        'flood watch',
        'flood warning',
        'severe thunderstorm warning',
        'high wind warning',
    ];

    /** The classes that are about to hurt a plant tonight (Section 8.4). */
    private const URGENT_TO_A_GARDEN = [
        'freeze warning', 'frost advisory', 'hard freeze warning', 'hard freeze watch',
        'freeze watch', 'excessive heat warning', 'excessive heat watch',
        'extreme heat warning', 'extreme heat watch', 'heat advisory',
    ];

    private Database $db;
    private AlertProvider $client;

    /** @var list<string> */
    private array $log = [];

    public function __construct(private App $app, ?AlertProvider $client = null)
    {
        $this->db = $app->db();
        $this->client = $client ?? new NwsClient(
            new HttpClient(
                $app->config()->string('weather.user_agent'),
                $app->config()->int('weather.http_timeout', 20),
            ),
            $app->config(),
        );
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    public static function isKept(string $event): bool
    {
        return \in_array(\strtolower(\trim($event)), self::KEPT, true);
    }

    /** Does this alert warrant telling someone before tomorrow's digest? */
    public static function isUrgentToAGarden(string $event): bool
    {
        return \in_array(\strtolower(\trim($event)), self::URGENT_TO_A_GARDEN, true);
    }

    /**
     * @return array{locations:int,stored:int,new:int,closed:int,failures:int,
     *               new_ids:list<int>,log:list<string>}
     */
    public function run(?int $onlyLocationId = null): array
    {
        $locations = $this->db->all(
            'SELECT * FROM `weather_location` WHERE `is_active` = 1'
            . ($onlyLocationId !== null ? ' AND `id` = :id' : '')
            . ' ORDER BY `id`',
            $onlyLocationId !== null ? ['id' => $onlyLocationId] : []
        );

        $stored = 0;
        $new = 0;
        $closed = 0;
        $failures = 0;
        $newIds = [];

        foreach ($locations as $location) {
            try {
                $result = $this->pollOne($location);
                $stored += $result['stored'];
                $new += \count($result['new_ids']);
                $closed += $result['closed'];
                foreach ($result['new_ids'] as $id) {
                    $newIds[] = $id;
                }
            } catch (Throwable $e) {
                // One location's failure must not stop the rest; the next
                // poll is three hours away and the state is rebuilt from the
                // service each time rather than from a cursor.
                $failures++;
                $this->note('location ' . $location['id'] . ' failed: ' . $e->getMessage());
                $this->recordRun((int) $location['id'], null, 0, 'failed',
                    \substr($e->getMessage(), 0, 500));
            }
        }

        return ['locations' => \count($locations), 'stored' => $stored, 'new' => $new,
                'closed' => $closed, 'failures' => $failures, 'new_ids' => $newIds,
                'log' => $this->log];
    }

    /**
     * @param array<string,mixed> $location
     * @return array{stored:int,new_ids:list<int>,closed:int}
     */
    private function pollOne(array $location): array
    {
        $locationId = (int) $location['id'];
        $startedAt = $this->app->clock()->utcStamp();

        $result = $this->client->activeAt((float) $location['latitude'], (float) $location['longitude']);

        if (!$result->ok() || $result->json === null) {
            $this->recordRun($locationId, $result->status, 0, 'failed', $result->errorText(), $startedAt);
            $this->note('alerts failed: ' . ($result->error ?? 'unknown'));
            return ['stored' => 0, 'new_ids' => [], 'closed' => 0];
        }

        $features = $result->json['features'] ?? null;
        if (!\is_array($features)) {
            $this->recordRun($locationId, $result->status, 0, 'failed',
                'Response had no features array.', $startedAt);
            return ['stored' => 0, 'new_ids' => [], 'closed' => 0];
        }

        $fetchedAt = $this->app->clock()->utcStamp();
        $seen = [];
        $newIds = [];
        $stored = 0;

        foreach ($features as $feature) {
            $alert = self::parse($feature);
            if ($alert === null) {
                continue;
            }

            $seen[] = $alert['nws_id'];

            // Insert-or-refresh on (location_id, nws_id). Whether this is the
            // first sighting decides whether anyone is told about it, and
            // rowCount tells us: 1 for an insert, 2 for an update that
            // changed something, 0 for an update that changed nothing.
            $existing = $this->db->value(
                'SELECT `id` FROM `weather_alert` WHERE `location_id` = :loc AND `nws_id` = :nws',
                ['loc' => $locationId, 'nws' => $alert['nws_id']]
            );

            $this->db->run(
                'INSERT INTO `weather_alert`'
                . ' (location_id, nws_id, event, severity, headline, onset, expires, fetched_at, is_active)'
                . ' VALUES (:loc, :nws, :event, :severity, :headline, :onset, :expires, :fetched, 1)'
                . ' ON DUPLICATE KEY UPDATE `event` = VALUES(`event`), `severity` = VALUES(`severity`),'
                . ' `headline` = VALUES(`headline`), `onset` = VALUES(`onset`),'
                . ' `expires` = VALUES(`expires`), `fetched_at` = VALUES(`fetched_at`),'
                . ' `is_active` = 1',
                [
                    'loc'      => $locationId,
                    'nws'      => $alert['nws_id'],
                    'event'    => $alert['event'],
                    'severity' => $alert['severity'],
                    'headline' => $alert['headline'],
                    'onset'    => $alert['onset'],
                    'expires'  => $alert['expires'],
                    'fetched'  => $fetchedAt,
                ]
            );
            $stored++;

            if ($existing === null) {
                $newIds[] = $this->db->insertId();
                $this->note(\sprintf('location %d: NEW %s', $locationId, $alert['event']));
            }
        }

        // Anything this location held that the service no longer lists has
        // been cancelled or has expired. The row stays -- it is history, and
        // the plant report will want it -- but it stops being active.
        $closed = $this->deactivateMissing($locationId, $seen);

        $this->recordRun($locationId, $result->status, $stored, 'ok', null, $startedAt);
        $this->note(\sprintf('location %d: %d active, %d new, %d closed, %d ms',
            $locationId, $stored, \count($newIds), $closed, $result->ms()));

        return ['stored' => $stored, 'new_ids' => $newIds, 'closed' => $closed];
    }

    /**
     * One GeoJSON feature, or null if it is not an event class worth keeping.
     *
     * @param mixed $feature
     * @return array{nws_id:string,event:string,severity:?string,headline:?string,
     *               onset:?string,expires:?string}|null
     */
    public static function parse(mixed $feature): ?array
    {
        if (!\is_array($feature)) {
            return null;
        }
        $properties = $feature['properties'] ?? null;
        if (!\is_array($properties)) {
            return null;
        }

        $event = $properties['event'] ?? null;
        if (!\is_string($event) || !self::isKept($event)) {
            return null;
        }

        // The feature's own id is the stable one; properties.id is the same
        // value on every response the service has ever returned, but the
        // top-level id is what the documentation calls canonical.
        $id = $feature['id'] ?? ($properties['id'] ?? null);
        if (!\is_string($id) || $id === '') {
            return null;
        }

        return [
            'nws_id'   => \substr($id, 0, 255),
            'event'    => \substr($event, 0, 120),
            'severity' => \is_string($properties['severity'] ?? null)
                ? \substr((string) $properties['severity'], 0, 32) : null,
            'headline' => \is_string($properties['headline'] ?? null)
                ? \substr((string) $properties['headline'], 0, 500) : null,
            'onset'    => self::toUtc($properties['onset'] ?? ($properties['effective'] ?? null)),
            // 'ends' is when the weather stops; 'expires' is when the
            // *message* goes stale and is often much sooner. A freeze warning
            // that expires at 03:00 is still a freeze warning at 04:00, so
            // the later of the two is what the MOTD should hide it by.
            'expires'  => self::laterOf(
                self::toUtc($properties['expires'] ?? null),
                self::toUtc($properties['ends'] ?? null),
            ),
        ];
    }

    /**
     * An ISO-8601 timestamp with an offset, as a UTC DATETIME string.
     *
     * The service sends local time with an offset ("2026-03-01T02:00:00-06:00")
     * and every DATETIME in this database is UTC (handoff Section 6). Storing
     * the string as it arrives would put a 02:00 freeze warning six hours out
     * of place, which is the difference between a warning and a post-mortem.
     */
    public static function toUtc(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function laterOf(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return $a >= $b ? $a : $b;
    }

    /** @param list<string> $stillActive */
    private function deactivateMissing(int $locationId, array $stillActive): int
    {
        $params = ['loc' => $locationId];
        $predicate = '';

        if ($stillActive !== []) {
            $names = [];
            foreach (\array_values($stillActive) as $i => $id) {
                $names[] = ':a' . $i;
                $params['a' . $i] = $id;
            }
            $predicate = ' AND `nws_id` NOT IN (' . \implode(', ', $names) . ')';
        }

        return $this->db->run(
            'UPDATE `weather_alert` SET `is_active` = 0'
            . ' WHERE `location_id` = :loc AND `is_active` = 1' . $predicate,
            $params
        )->rowCount();
    }

    private function recordRun(
        int $locationId,
        ?int $httpStatus,
        int $rows,
        string $outcome,
        ?string $error = null,
        ?string $startedAt = null,
    ): void {
        $this->db->run(
            'INSERT INTO `weather_sync_run`'
            . " (location_id, kind, started_at, finished_at, http_status, rows_upserted,"
            . '  outcome, error_text)'
            . " VALUES (:location_id, 'alerts', :started_at, UTC_TIMESTAMP(), :http_status,"
            . '  :rows, :outcome, :error)',
            [
                'location_id' => $locationId,
                'started_at'  => $startedAt ?? $this->app->clock()->utcStamp(),
                'http_status' => $httpStatus,
                'rows'        => $rows,
                'outcome'     => $outcome,
                'error'       => $error,
            ]
        );
    }

    private function note(string $message): void
    {
        $this->log[] = $message;
    }
}
