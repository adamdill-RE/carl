<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\PlantingState;

/**
 * The main menu (handoff Section 4.2): the MOTD weather matrix, region
 * guidance lines, and the menu itself.
 *
 * Both MOTD blocks are dismissable for the session and reappear next day --
 * or immediately if the forecast changed materially since the dismissal,
 * which is what the stored hash decides.
 */
final class MenuController extends Controller
{
    private const DISMISS_KEY = '_motd_dismissed';

    public function index(Request $request): Response
    {
        $user = $this->user();
        $today = $this->today();

        // Weather is never fetched on the request path: this reads the tables
        // the cron filled and nothing else (weather.md Section 3, rule 2).
        $weather = ['recent' => [], 'forecast' => []];
        $alerts = [];
        $location = null;

        if ($user->weatherLocationId !== null) {
            $location = $this->weather()->findLocation($user->weatherLocationId);
            $weather = $this->weather()->motd($user->weatherLocationId, $today);
            $alerts = $this->weather()->activeAlerts($user->weatherLocationId);
        }

        // Read, never computed: the model runs nightly after the weather
        // step and stores the row (handoff Section 11).
        $watering = $this->watering()->forDate($today);

        // Today's items (handoff Section 4.2), the same content as the daily
        // email. The hourly digest job computes and stores them; a menu that
        // recomputed eleven rules over every planting would be the slowest
        // page here and would disagree with the email that went out at six.
        $items = $this->reminders()->forDate($today);

        $categories = $this->categoriesGrown();
        $guidance = $this->reference()->guidanceFor($user->regionId, $categories, $today);
        $pests = $this->reference()->activePests($user->regionId, $categories, $today);

        $region = $user->regionId === null ? null : $this->reference()->findRegion($user->regionId);
        $researched = $region !== null && (string) $region['research_status'] === 'researched';

        $forecastHash = self::forecastHash($weather['forecast']);
        $dismissed = $this->isDismissed($today, $forecastHash);

        $models = [];
        foreach ($weather['recent'] as $day) {
            $models[(string) $day['source_model']] = true;
        }

        return $this->render('menu', [
            'weather'       => $weather,
            'watering'      => $watering,
            'items'         => $items,
            'alerts'        => $alerts,
            'location'      => $location,
            'guidance'      => $guidance,
            'pests'         => $pests,
            'region'        => $region,
            'researched'    => $researched,
            'dismissed'     => $dismissed,
            'forecastHash'  => $forecastHash,
            'weatherModels' => \array_keys($models),
            'counts'        => $this->summaryCounts(),
            'onboarded'     => $user->isOnboarded(),
        ]);
    }

    public function dismiss(Request $request): Response
    {
        $this->app->session()->set(self::DISMISS_KEY, [
            'date' => $this->today(),
            'hash' => (string) $request->input('forecast_hash', ''),
        ]);
        return $this->redirect('/');
    }

    /**
     * Dismissed only while it is still the same day AND the forecast has not
     * changed materially since (handoff Section 4.2).
     */
    private function isDismissed(string $today, string $forecastHash): bool
    {
        $dismissed = $this->app->session()->get(self::DISMISS_KEY);
        if (!\is_array($dismissed)) {
            return false;
        }
        return ($dismissed['date'] ?? null) === $today
            && ($dismissed['hash'] ?? null) === $forecastHash;
    }

    /** @param list<array<string,mixed>> $forecast */
    public static function forecastHash(array $forecast): string
    {
        $material = [];
        foreach ($forecast as $day) {
            $material[] = [
                $day['forecast_date'] ?? null,
                $day['temp_max_c'] ?? null,
                $day['temp_min_c'] ?? null,
                $day['precip_mm'] ?? null,
                $day['precip_prob_pct'] ?? null,
            ];
        }
        return \hash('sha256', (string) \json_encode($material));
    }

    /**
     * The plant categories this user actually grows, which is what the
     * guidance and pest lines are filtered by.
     *
     * @return list<string>
     */
    private function categoriesGrown(): array
    {
        $values = $this->app->db()->column(
            'SELECT DISTINCT pt.category FROM `planting` p'
            . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' WHERE p.user_id = :user_id AND p.state <> :ended',
            ['user_id' => $this->userId(), 'ended' => PlantingState::ENDED]
        );
        return \array_map(\strval(...), $values);
    }

    /** @return array{living:int,plantings:int,gardens:int,events:int} */
    private function summaryCounts(): array
    {
        $row = $this->app->db()->one(
            'SELECT'
            . ' (SELECT COALESCE(SUM(quantity_live), 0) FROM `planting`'
            . '   WHERE user_id = :u1 AND state <> :ended) AS living,'
            . ' (SELECT COUNT(*) FROM `planting` WHERE user_id = :u2) AS plantings,'
            . ' (SELECT COUNT(*) FROM `garden` WHERE user_id = :u3 AND is_active = 1) AS gardens,'
            . ' (SELECT COUNT(*) FROM `plant_event` WHERE user_id = :u4) AS events',
            // Emulation is off, so one value needs four names (hosting Section 7).
            ['u1' => $this->userId(), 'u2' => $this->userId(), 'u3' => $this->userId(),
             'u4' => $this->userId(), 'ended' => PlantingState::ENDED]
        );

        return [
            'living'    => (int) ($row['living'] ?? 0),
            'plantings' => (int) ($row['plantings'] ?? 0),
            'gardens'   => (int) ($row['gardens'] ?? 0),
            'events'    => (int) ($row['events'] ?? 0),
        ];
    }
}
