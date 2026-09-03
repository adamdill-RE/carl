<?php

declare(strict_types=1);

namespace Carl\Timers;

use Carl\Core\App;
use Carl\Core\Database;
use Carl\Core\HttpException;
use Carl\Domain\DripLine;
use Carl\Domain\EventType;
use Carl\Mail\Outbox;
use Carl\Push\Vapid;
use Carl\Push\WebPush;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\PushSubscriptionRepository;
use Carl\Repo\TimerRepository;
use Throwable;

/**
 * The watering timer (Phase 16; Phase 15 handoff Section 3.2).
 *
 * "Water Zone 1 for 60 minutes; ping me when it is done." Three halves:
 *
 *  - **start()**: a row with an end time. Nothing runs; nothing is held
 *    open (hosting Section 3). The page says when it ends and returns.
 *  - **fire()**: the per-minute cron. Claims each due row with a
 *    compare-and-swap, logs the watering if the timer asked for it -- the
 *    same recordGardenEvent() the garden actions form calls, fan-out and
 *    all -- and reaches the phone.
 *  - **notify()**: Web Push to every live subscription first; the mail
 *    outbox when there is none or none was reachable. Push is the one that
 *    arrives in seconds; mail is the one that cannot quietly stop existing.
 *
 * The cron writes no run row of its own. It fires every minute and most
 * minutes there is nothing due; 1,440 rows a day saying so would be the
 * noise the run tables exist to cut through. /status reads the timers
 * themselves instead: how many are counting, how many are late, and when
 * one last fired -- which is the line that says the cron entry exists.
 */
final class TimerService
{
    public const MAIL_KIND = 'timer';

    private Database $db;

    /** @var callable|null the push transport, for the suite */
    private $transport;

    /** @var list<string> */
    private array $log = [];

    /**
     * What went wrong on the push side of the last notify(), when the mail
     * went instead: "web.push.apple.com: HTTP 403 BadJwtToken". Kept on the
     * timer's row (Phase 17), because "you were told by email" with no word
     * about why the phone was not is the diagnosis nobody can make.
     */
    private ?string $pushProblem = null;

    public function __construct(private App $app, ?callable $transport = null)
    {
        $this->db = $app->db();
        $this->transport = $transport ?? $app->pushTransport();
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    // -- Starting one ---------------------------------------------------------

    /**
     * @return array{id:int,ends_at:string,minutes:int,zone_name:?string,garden_name:string}
     * @throws HttpException when the garden or zone is not this user's, or the minutes are silly
     */
    public function start(int $userId, int $gardenId, ?int $zoneId, int $minutes, bool $logWhenDone): array
    {
        $gardens = new GardenRepository($this->db, $userId);
        $garden = $gardens->findOrFail($gardenId);

        $max = $this->app->config()->int('timers.max_minutes', 720);
        if ($minutes < 1 || $minutes > $max) {
            throw HttpException::badRequest('A timer runs from 1 to ' . $max . ' minutes.');
        }

        $zoneName = null;
        if ($zoneId !== null) {
            foreach ($gardens->zones($gardenId) as $zone) {
                if ((int) $zone['id'] === $zoneId) {
                    $zoneName = (string) $zone['name'];
                }
            }
            if ($zoneName === null) {
                throw HttpException::badRequest('That zone is not in this garden.');
            }
        }

        $now = $this->app->clock()->nowUtc();
        $endsAt = $now->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');

        $id = (new TimerRepository($this->db, $userId))->insert([
            'garden_id'     => $gardenId,
            'water_zone_id' => $zoneId,
            'minutes'       => $minutes,
            'started_at'    => $now->format('Y-m-d H:i:s'),
            'ends_at'       => $endsAt,
            'log_when_done' => $logWhenDone ? 1 : 0,
        ]);

        return ['id' => $id, 'ends_at' => $endsAt, 'minutes' => $minutes,
                'zone_name' => $zoneName, 'garden_name' => (string) $garden['name']];
    }

    // -- Firing the due ones --------------------------------------------------

    /**
     * @return array{considered:int,fired:int,logged:int,pushed:int,emailed:int,failures:int,log:list<string>}
     */
    public function fire(?int $limit = null): array
    {
        $limit ??= $this->app->config()->int('timers.batch', 50);
        $nowUtc = $this->app->clock()->utcStamp();
        $summary = ['considered' => 0, 'fired' => 0, 'logged' => 0, 'pushed' => 0,
                    'emailed' => 0, 'failures' => 0];

        foreach (TimerRepository::due($this->db, $nowUtc, $limit) as $timer) {
            $summary['considered']++;
            $id = (int) $timer['id'];

            if (!TimerRepository::claim($this->db, $id, $nowUtc)) {
                $this->note('#' . $id . ' already claimed by another run');
                continue;
            }
            $summary['fired']++;

            $eventId = null;
            $error = null;
            try {
                if ((int) $timer['log_when_done'] === 1) {
                    $eventId = $this->logWatering($timer);
                    $summary['logged']++;
                }
            } catch (Throwable $e) {
                $error = 'log: ' . $e->getMessage();
                $summary['failures']++;
            }

            $via = 'none';
            try {
                $via = $this->notify($timer, $eventId);
                if ($via === 'push') {
                    $summary['pushed']++;
                } elseif ($via === 'email') {
                    $summary['emailed']++;
                }
                // The phone was tried and said no: not a failure of the
                // timer -- the mail went -- but the one fact the landing
                // page needs, so it rides on the row.
                if ($via !== 'push' && $this->pushProblem !== null) {
                    $error = ($error === null ? '' : $error . '; ') . $this->pushProblem
                        . ($via === 'email' ? ' -- emailed instead' : '');
                }
            } catch (Throwable $e) {
                $error = ($error === null ? '' : $error . '; ') . 'notify: ' . $e->getMessage();
                $summary['failures']++;
            }

            TimerRepository::finish($this->db, $id, $via, $eventId, $error);
            $this->note(\sprintf('#%d %s: %d min on %s -> %s%s%s', $id, $timer['user_name'],
                (int) $timer['minutes'], self::placeName($timer), $via,
                $eventId === null ? '' : ', logged as event ' . $eventId,
                $error === null ? '' : ' -- ' . $error));
        }

        return $summary + ['log' => $this->log];
    }

    /**
     * The watering, as the garden actions form would have written it: a
     * garden_event on the user's local day, the zone's own method, and the
     * fan-out to every living plant in the zone's rows (handoff Section 4.7).
     *
     * @param array<string,mixed> $timer a row from TimerRepository::due()
     */
    public function logWatering(array $timer): int
    {
        $userId = (int) $timer['user_id'];
        $plantings = new PlantingRepository($this->db, $userId);
        $events = new EventRepository($this->db, $userId, $plantings);
        $gardens = new GardenRepository($this->db, $userId);

        $zoneId = $timer['water_zone_id'] === null ? null : (int) $timer['water_zone_id'];
        $rowIds = $zoneId === null ? [] : $gardens->zoneRowIds($zoneId);
        $today = $this->app->clock()->todayFor((string) ($timer['timezone'] ?? 'UTC'));

        $result = $events->recordGardenEvent(
            (int) $timer['garden_id'],
            EventType::WATERED,
            $today,
            [
                'duration_min'     => (int) $timer['minutes'],
                'ref_list_item_id' => $timer['zone_method_id'] === null ? null : (int) $timer['zone_method_id'],
                'narrative'        => 'Timer: ' . (int) $timer['minutes'] . ' min, logged when it finished.',
            ],
            $rowIds,
            $zoneId,
            $zoneId !== null,
        );

        return $result['event_id'];
    }

    /**
     * Reach the phone. Push to every live subscription; if none took it,
     * the outbox. Returns how it went: 'push', 'email' or 'none'.
     *
     * @param array<string,mixed> $timer
     */
    public function notify(array $timer, ?int $eventId): string
    {
        $userId = (int) $timer['user_id'];
        $subscriptions = new PushSubscriptionRepository($this->db, $userId);
        $pushed = 0;
        $this->pushProblem = null;

        $pair = Vapid::existing($this->db);
        $live = $pair === null ? [] : $subscriptions->live();
        if ($live !== []) {
            $problems = [];
            $payload = $this->pushPayload($timer, $eventId);
            foreach ($this->sendTo($pair, $live, $payload, 'carl-timer', $subscriptions) as $outcome) {
                if ($outcome['ok']) {
                    $pushed++;
                    continue;
                }
                $this->note('  push to ' . $outcome['service'] . ' failed: ' . $outcome['error']);
                $problems[] = $outcome['service'] . ': ' . $outcome['error'];
            }
            if ($pushed === 0 && $problems !== []) {
                $this->pushProblem = 'push: ' . \implode('; ', $problems);
            }
        }
        if ($pushed > 0) {
            return 'push';
        }

        $email = (string) ($timer['email'] ?? '');
        if ($email === '') {
            return 'none';
        }
        [$subject, $text, $html] = $this->mail($timer, $eventId);
        $queued = $this->app->outbox()->queue(
            $userId, self::MAIL_KIND, $email, (string) ($timer['user_name'] ?? ''),
            $subject, $text, $html, [], 'timer:' . (int) $timer['id']
        );
        return 'email';
    }

    /**
     * One payload to a set of subscriptions, with the bookkeeping every
     * caller wants: a subscription that took it is touched, one the service
     * says is gone is marked so nothing tries it again, and each answer comes
     * back with the service's name and its reason.
     *
     * @param array{public:string,private:string} $pair
     * @param list<array<string,mixed>> $subscriptions rows of push_subscription
     * @return list<array{id:int,service:string,ok:bool,status:int,gone:bool,error:?string}>
     */
    private function sendTo(array $pair, array $subscriptions, string $payload, string $topic,
                            PushSubscriptionRepository $repository, ?int $timeoutSeconds = null): array
    {
        $push = new WebPush(
            $pair,
            $this->app->config()->string('push.subject', 'mailto:carl@reshiftmanager.com'),
            $this->app->config()->int('push.ttl', 3600),
            $this->transport,
            $timeoutSeconds === null ? null : new \Carl\Core\HttpClient('CarlTheGardenHelper/1.0 (web push)', $timeoutSeconds),
        );

        $out = [];
        foreach ($subscriptions as $subscription) {
            $result = $push->send($subscription, $payload, $topic);
            if ($result['ok']) {
                $repository->touch((int) $subscription['id']);
            } elseif ($result['gone']) {
                $repository->markFailed((int) $subscription['id'], 'gone: HTTP ' . $result['status']);
            }
            $out[] = [
                'id'      => (int) $subscription['id'],
                'service' => PushSubscriptionRepository::serviceName((string) $subscription['endpoint']),
                'ok'      => $result['ok'],
                'status'  => $result['status'],
                'gone'    => $result['gone'],
                'error'   => $result['error'],
            ];
        }
        return $out;
    }

    /**
     * "Send a test notification" (Phase 17): one push, now, to the phones
     * given, and what each push service said about it. This is the ONE push
     * that happens on a request rather than from the cron, and it is a
     * deliberate diagnostic pressed by a person: the service's answer is the
     * diagnosis, and a minute's wait for the cron would put that answer on a
     * timer row instead of on the screen in front of them. Bounded: the
     * caller caps how many phones, and the socket timeout is short.
     *
     * @param list<array<string,mixed>> $subscriptions rows of push_subscription
     * @param string $navigate the absolute URL the notification opens
     * @return list<array{id:int,service:string,ok:bool,status:int,gone:bool,error:?string}>
     * @throws \RuntimeException when the install has no push key pair yet
     */
    public function testPush(int $userId, array $subscriptions, string $navigate, string $localTime): array
    {
        $pair = Vapid::existing($this->db);
        if ($pair === null) {
            throw new \RuntimeException('Push notifications are not set up on this install yet.');
        }
        $encoded = \json_encode([
            'web_push'     => 8030,
            'notification' => [
                'title'    => 'Carl can reach this phone',
                'body'     => 'Test sent at ' . $localTime . '. A finished timer will arrive like this.',
                'navigate' => $navigate,
                'tag'      => 'carl-test',
                'lang'     => 'en',
            ],
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $this->sendTo($pair, $subscriptions, $encoded === false ? '{}' : $encoded, 'carl-test',
            new PushSubscriptionRepository($this->db, $userId), 10);
    }

    // -- What the phone shows -------------------------------------------------

    /**
     * The declarative Web Push body: shown by iOS 18.4+ with no service
     * worker, and by sw.js everywhere else. The tap opens the timer's own
     * page, where the watering is either already logged or one button away.
     *
     * @param array<string,mixed> $timer
     */
    public function pushPayload(array $timer, ?int $eventId): string
    {
        $encoded = \json_encode([
            'web_push'     => 8030,
            'notification' => [
                'title'    => self::placeName($timer) . ' is done',
                'body'     => (int) $timer['minutes'] . ' min in ' . $timer['garden_name'] . '. '
                    . ($eventId !== null ? 'Logged as a watering.' : 'Tap to log the watering.'),
                'navigate' => $this->timerUrl((int) $timer['id']),
                'tag'      => 'carl-timer-' . (int) $timer['id'],
                'lang'     => 'en',
            ],
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * The mail twin, plain text first (handoff Section 12).
     *
     * @param array<string,mixed> $timer
     * @return array{0:string,1:string,2:string} subject, text, html
     */
    public function mail(array $timer, ?int $eventId): array
    {
        $place = self::placeName($timer);
        $minutes = (int) $timer['minutes'];
        $url = $this->timerUrl((int) $timer['id']);
        $when = $this->app->clock()->localNow((string) ($timer['timezone'] ?? 'UTC'))->format('H:i');

        $subject = 'Timer done: ' . $minutes . ' min on ' . $place;
        $lines = [
            'Your ' . $minutes . ' minute timer on ' . $place . ' in ' . $timer['garden_name']
                . ' finished at ' . $when . '.',
            '',
            $eventId !== null
                ? 'Carl logged it as a watering, so there is nothing to do.'
                : 'It was not logged. Tap to log it as a watering:',
            $url,
            '',
            '--',
            'Carl The Garden Helper',
        ];
        $e = static fn (string $v): string => \htmlspecialchars($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="color-scheme" content="light">'
            . '</head><body style="margin:0;padding:0;background-color:#ffffff">'
            . '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;'
            . 'font-size:15px;line-height:1.5;color:#191d19;max-width:620px">'
            . '<p>' . $e($lines[0]) . '</p>'
            . '<p>' . $e($lines[2]) . '</p>'
            . '<p><a href="' . $e($url) . '" style="color:#377f47">' . $e($url) . '</a></p>'
            . '<p style="color:#656b63;font-size:13px">Carl The Garden Helper</p>'
            . '</div></body></html>';

        return [$subject, \implode("\n", $lines) . "\n", $html];
    }

    private function timerUrl(int $id): string
    {
        return \rtrim($this->app->config()->string('tags.origin'), '/') . $this->app->url('timers/' . $id);
    }

    /** @param array<string,mixed> $timer */
    public static function placeName(array $timer): string
    {
        $zone = \trim((string) ($timer['zone_name'] ?? ''));
        return $zone !== '' ? $zone : (string) ($timer['garden_name'] ?? 'the garden');
    }

    // -- The one-tap on the MOTD ----------------------------------------------

    /**
     * The timer each zone would want for today's deficit: the same arithmetic
     * as the recommendation's "About 40 min on Drip east refills it", from
     * the SAME stored deficit (Phase 15 handoff Section 7: read the row, do
     * not recompute the season), so the button says what the sentence says.
     *
     * @param array<string,mixed> $place a watering_recommendation row joined
     *        to its garden's dimensions
     * @param list<array<string,mixed>> $zones the garden's zones
     * @return list<array{zone_id:int,zone_name:string,minutes:int}>
     */
    public static function refillOptions(array $place, array $zones): array
    {
        $deficit = (float) ($place['deficit_mm'] ?? 0);
        if ($deficit <= 0.0 || (string) ($place['tier'] ?? 'skip') === 'skip') {
            return [];
        }
        $rowSpacing = DripLine::rowSpacingIn($place);
        $out = [];
        foreach ($zones as $zone) {
            $spec = DripLine::resolve($zone, $rowSpacing);
            if ($spec === null) {
                continue;
            }
            $minutes = DripLine::minutesFor($deficit, $spec['rate_mm_h'], $spec['efficiency_pct']);
            if ($minutes === null) {
                continue;
            }
            $out[] = ['zone_id' => (int) $zone['id'], 'zone_name' => (string) $zone['name'], 'minutes' => $minutes];
            if (\count($out) === 3) {
                break;
            }
        }
        return $out;
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }
}
