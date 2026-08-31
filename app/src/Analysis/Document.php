<?php

declare(strict_types=1);

namespace Carl\Analysis;

use Carl\Auth\User;
use Carl\Core\App;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\ReferenceRepository;
use Carl\Repo\WeatherRepository;
use Carl\Support\Clock;
use Carl\Support\Units;

/**
 * The document one analysis is built from (Phase 5 handoff Section 3.1).
 *
 * This is NOT `/export/claude.json`. That document is for a person to paste
 * into a conversation and is deliberately complete; this one is for an API
 * call the application pays for and has to fit in a context window, so it is
 * bounded by construction.
 *
 * **Why it had to be a different document.** Phase 5 handoff Section 3.1 says
 * to measure a real one first. Measured 2026-08-31 on MySQL 8.0 against a
 * synthetic five-year account -- 150 plantings, 4,500 events, 1,826 days of
 * weather:
 *
 * | Section | Bytes | Share |
 * | --- | --- | --- |
 * | `plant_events` | 2,257,261 | 68% |
 * | `weather.days` | 820,079 | 25% |
 * | `plantings` | 166,829 | 5% |
 * | everything else | 62,119 | 2% |
 * | **total** | **3,306,288** | ~918,000 tokens |
 *
 * Section 3.1 offers two bounds -- cap the date range, or summarise the
 * weather into weekly rows. Neither alone is enough: the range cap alone
 * leaves a heavy year at ~450 KB of event log, and the weekly rows alone
 * leave the other 75% untouched. This applies three, in the order the
 * measurement puts them in:
 *
 *  1. **A window.** `analysis.days` back from the gardener's own today, 365
 *     by default -- a season and its shoulders. Everything is filtered to it
 *     and the document says what it covers.
 *  2. **Weekly weather** (`WeatherRepository::weeklySummary()`). 365 daily
 *     rows become 53.
 *  3. **Per-planting event roll-ups** (`EventRepository::summaryByPlanting()`)
 *     instead of the raw log, plus the narratives verbatim and capped by
 *     count. Thirty "watered" rows become one line that says twelve times,
 *     340 minutes, last on the 20th -- which is the whole of what the reader
 *     needed from them.
 *
 * The same account through this class: **140,510 bytes, roughly 39,000
 * tokens** -- a factor of 23.5 -- in 16 ms. `deploy.md` Section 0.9 has the
 * full breakdown, and `12_analysis_test.php` asserts the bound, which is the
 * thing that will notice when someone adds a field per event and quietly puts
 * the megabytes back.
 *
 * **Twelve statements, whatever the size of the account.** Not "per planting"
 * and not "per garden" -- the one loop that was per-garden is why
 * `gardenSection()` reads `rowsForGardens()` (hosting Section 9). Every read
 * is user-scoped by the repository, not by this class (handoff Section 5).
 */
final class Document
{
    /**
     * Bumped when the shape changes, so a stored answer can be read back.
     *
     * 2 (Phase 6): `research` gained a `sources` map and folded each plant's
     * region windows underneath it. See researchSection().
     */
    public const VERSION = 2;

    public function __construct(
        private PlantingRepository $plantings,
        private EventRepository $events,
        private GardenRepository $gardens,
        private WeatherRepository $weather,
        private ReferenceRepository $reference,
        private Units $units,
        private int $days = 365,
        private int $maxNarratives = 60,
        private int $maxPlantings = 400,
    ) {
    }

    /** Built from the container, so a caller does not assemble six repositories. */
    public static function forUser(App $app, User $user): self
    {
        $db = $app->db();
        $plantings = new PlantingRepository($db, $user->id);
        $config = $app->config();

        return new self(
            $plantings,
            new EventRepository($db, $user->id, $plantings),
            new GardenRepository($db, $user->id),
            new WeatherRepository($db),
            new ReferenceRepository($db),
            $app->units(),
            $config->int('analysis.days', 365),
            $config->int('analysis.max_narratives', 60),
            $config->int('analysis.max_plantings', 400),
        );
    }

    /**
     * @param string $today the gardener's own local calendar day
     * @param Scope|null $scope narrows the document to one garden or one
     *        planting (Phase 6). Null is the whole season, as Phase 5 shipped.
     * @return array<string,mixed>
     */
    public function build(User $user, string $today, ?Scope $scope = null): array
    {
        $scope ??= Scope::season();
        $from = (string) Clock::addDays($today, -($this->days - 1));

        $plantings = $this->plantings->overlappingWindow($from, $today, $this->maxPlantings);
        $byPlanting = $this->groupByPlanting($from, $today);

        // A narrower scope is a FILTER over rows already fetched, never a
        // second query. The repositories scoped them to their owner on the
        // way in, so filtering here cannot widen anything -- and the twelve
        // statements of Phase 6 handoff Section 4.3 stay twelve whether the
        // question is about one bed or the year.
        $plantings = self::withinScope($plantings, $scope);

        $document = [
            'document'  => 'carl-analysis',
            'version'   => self::VERSION,
            'generated' => \gmdate('c'),
            'covers'    => \array_filter([
                'from'    => $from,
                'to'      => $today,
                'days'    => $this->days,
                // Said in covers rather than tucked away, because it bounds
                // the document exactly as the dates do: an answer about one
                // bed that reads as an answer about the garden is the same
                // failure as a summary that does not say it is one.
                'subject' => $scope->isSeason() ? null : $scope->value(),
            ], static fn (mixed $v): bool => $v !== null),
            // Said once, here, rather than left for the reader to infer from
            // the shape: a summary that does not announce itself as a summary
            // gets read as a complete record.
            'read_me' => [
                'One gardener\'s own records, summarised. Dates are that gardener\'s local'
                    . ' calendar days, not UTC.',
                'Weather is weekly, rolled up from daily observations, in SI: Celsius,'
                    . ' millimetres. water_balance_mm is rain minus ET0 for the week; a run of'
                    . ' negative weeks is the soil drying out.',
                'Events are counts per plant per action, not the individual entries. "derived"'
                    . ' counts the ones that reached the plant through a garden-wide action'
                    . ' rather than being logged against it directly.',
                'Anything outside covers.from and covers.to is not in this document. The'
                    . ' gardener may have records going back further.',
            'research.sources maps an id to a citation. A research row carries source_id, not'
                    . ' the text, because the same publication is cited by most of them; look'
                    . ' the id up before quoting a source.',
                'A research value carries a confidence: verified, approx or generic. A generic'
                    . ' value is a catalogue default, not a measurement for this county.',
            ],
            'gardener' => [
                'name'          => $user->name,
                'timezone'      => $user->timezone,
                'zip'           => $user->zip,
                'display_units' => $this->units->isUs() ? 'us' : 'si',
                'today'         => $today,
            ],
        ];

        if ($user->regionId !== null) {
            $region = $this->reference->findRegion($user->regionId);
            $document['gardener']['region'] = $region === null ? null : self::clean($region);
        }

        $document['gardens'] = $this->gardenSection($from, $today, $scope, $plantings);
        $document['plantings'] = $this->plantingSection($plantings, $byPlanting);
        $document['weather'] = $this->weatherSection($user, $from, $today);

        $plantTypeIds = [];
        foreach ($plantings as $planting) {
            $plantTypeIds[(int) $planting['plant_type_id']] = true;
        }
        $document['research'] = $this->researchSection(\array_keys($plantTypeIds), $user->regionId);

        return $document;
    }

    /** The document as the bytes that go on the wire. */
    public function encode(array $document): string
    {
        $encoded = \json_encode(
            $document,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $encoded === false ? '{}' : $encoded;
    }

    // -- Sections ----------------------------------------------------------

    /**
     * The research values in force, and the section Phase 6 went after.
     *
     * Phase 6 handoff Section 3.5 named this "the section with the worst
     * ratio of bytes to signal", and said a trimmed version would have to
     * keep the citations and confidences -- because they are what stop the
     * answer presenting a catalogue default as a local measurement.
     *
     * Measured before cutting, on an account holding a planting of every
     * plant type in the catalogue: 51,288 bytes, 68% of a 74,893-byte
     * document. Where it went, and what happened to it:
     *
     * | Cut | Bytes | Why it carries nothing |
     * | --- | --- | --- |
     * | `source` repeated per row | 8,886 | 12 distinct citations, cited 99 times |
     * | nulls | 5,518 | an absent value and a null say the same thing |
     * | `dataset_version` per row | 3,168 | identical on all 99 rows |
     * | `region_id` / `plant_type_id` | 1,966 | opaque ids, replaced by nesting |
     *
     * **Not one citation or confidence is dropped.** The sources move into a
     * map and each row points at one; the version is stated once instead of
     * ninety-nine times; the region windows sit under the plant they belong
     * to, so the join that needed the ids is structural. A reader loses
     * nothing except the repetition, and `read_me` says how to follow a
     * source_id so the map cannot be mistaken for a truncation.
     *
     * @param list<int> $plantTypeIds
     * @return array<string,mixed>
     */
    private function researchSection(array $plantTypeIds, ?int $regionId): array
    {
        // The same two statements `/export/claude.json` uses.
        $research = $this->reference->researchFor($plantTypeIds, $regionId);

        $sources = [];
        $datasetVersions = [];

        /** Hoist one row's citation into the map and leave an id behind. */
        $hoist = static function (array $row) use (&$sources, &$datasetVersions): array {
            $version = $row['dataset_version'] ?? null;
            if (\is_string($version) && $version !== '') {
                $datasetVersions[$version] = true;
            }
            unset($row['dataset_version'], $row['region_id'], $row['plant_type_id']);

            $source = $row['source'] ?? null;
            unset($row['source']);
            if (\is_string($source) && $source !== '') {
                $id = \array_search($source, $sources, true);
                if ($id === false) {
                    $id = 's' . (\count($sources) + 1);
                    $sources[$id] = $source;
                }
                $row['source_id'] = $id;
            }

            // An absent key and a null key say the same thing to a reader,
            // and one of them is free.
            return \array_filter($row, static fn (mixed $v): bool => $v !== null);
        };

        $windowsByPlant = [];
        foreach ($research['regions'] as $window) {
            $windowsByPlant[(int) $window['plant_type_id']][] = $hoist(self::clean($window));
        }

        $plants = [];
        foreach ($research['plants'] as $plant) {
            $plantId = (int) $plant['id'];
            $row = $hoist(self::clean($plant));
            // The windows belong to this plant, so they hang off it rather
            // than in a parallel list joined by an id nobody can read.
            $row['windows'] = $windowsByPlant[$plantId] ?? [];
            $plants[] = $row;
        }

        $section = ['plants' => $plants];
        if ($sources !== []) {
            $section['sources'] = $sources;
        }
        if ($datasetVersions !== []) {
            // Normally one. More than one means a region was imported at a
            // different time from the global catalogue, which is worth
            // seeing rather than silently picking a winner.
            $section['dataset_version'] = \count($datasetVersions) === 1
                ? \array_key_first($datasetVersions)
                : \array_keys($datasetVersions);
        }

        return $section;
    }

    /**
     * Which of these plantings the scope is asking about.
     *
     * @param list<array<string,mixed>> $plantings
     * @return list<array<string,mixed>>
     */
    private static function withinScope(array $plantings, Scope $scope): array
    {
        if ($scope->isSeason()) {
            return $plantings;
        }

        $key = $scope->kind === Scope::GARDEN ? 'garden_id' : 'id';
        return \array_values(\array_filter(
            $plantings,
            static fn (array $p): bool => (int) ($p[$key] ?? 0) === $scope->subjectId
        ));
    }

    /**
     * @param list<array<string,mixed>> $plantings already narrowed to the scope
     * @return list<array<string,mixed>>
     */
    private function gardenSection(string $from, string $to, Scope $scope, array $plantings): array
    {
        $actions = [];
        foreach ($this->events->gardenSummaryInWindow($from, $to) as $row) {
            $actions[(int) $row['garden_id']][] = \array_filter([
                'action'   => (string) $row['event_type'],
                'times'    => (int) $row['events'],
                'first'    => $row['first_date'],
                'last'     => $row['last_date'],
                'minutes'  => $row['duration_min'] === null ? null : (int) $row['duration_min'],
                'products' => $row['products'],
            ], static fn (mixed $v): bool => $v !== null);
        }

        $gardens = $this->gardens->activeGardens();

        // A scoped document carries only the beds it is about: the one named
        // for a garden scope, and for a plant scope whichever bed it stands
        // in. Filtered after the read, so this is still one statement.
        if (!$scope->isSeason()) {
            $keep = [];
            if ($scope->kind === Scope::GARDEN) {
                $keep[(int) $scope->subjectId] = true;
            }
            foreach ($plantings as $planting) {
                if ($planting['garden_id'] !== null) {
                    $keep[(int) $planting['garden_id']] = true;
                }
            }
            $gardens = \array_values(\array_filter(
                $gardens,
                static fn (array $g): bool => isset($keep[(int) $g['id']])
            ));
        }

        // rowsForGardens(), not rows() per garden: the loop below would
        // otherwise cost one statement per garden, which is the only part of
        // this document whose cost grew with the account (hosting Section 9).
        $rowsByGarden = $this->gardens->rowsForGardens(
            \array_map(static fn (array $g): int => (int) $g['id'], $gardens)
        );

        $out = [];
        foreach ($gardens as $garden) {
            $gardenId = (int) $garden['id'];
            $out[] = [
                'id'          => $gardenId,
                'name'        => $garden['name'],
                'is_indoor'   => (int) $garden['is_indoor'] === 1,
                'soil_type'   => $garden['soil_type'],
                'ns_ft'       => $garden['ns_ft'],
                'ew_ft'       => $garden['ew_ft'],
                'orientation' => $garden['row_orientation'],
                'rows'        => \array_map(
                    static fn (array $r): array => [
                        'id'   => (int) $r['id'],
                        'name' => $r['name'],
                        'sun'  => $r['sun_exposure'],
                    ],
                    $rowsByGarden[$gardenId] ?? []
                ),
                'actions' => $actions[$gardenId] ?? [],
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $plantings
     * @param array{events:array<int,array<string,mixed>>,pests:array<int,list<string>>,
     *              narratives:array<int,list<array<string,mixed>>>} $byPlanting
     * @return list<array<string,mixed>>
     */
    private function plantingSection(array $plantings, array $byPlanting): array
    {
        $out = [];
        foreach ($plantings as $planting) {
            $id = (int) $planting['id'];
            $out[] = \array_filter([
                'id'            => $id,
                'category'      => $planting['category'],
                'type'          => $planting['type'],
                'plant_family'  => $planting['plant_family'],
                'label'         => $planting['label'],
                'start_method'  => $planting['start_method'],
                'start_date'    => $planting['start_date'],
                'in_ground'     => $planting['in_ground_date'],
                'ended'         => $planting['ended_at'],
                'state'         => $planting['state'],
                'quantity'      => ['initial' => (int) $planting['quantity_initial'],
                                    'live'    => (int) $planting['quantity_live']],
                'garden'        => $planting['garden_name'],
                'row'           => $planting['row_name'],
                'container'     => $planting['container_name'],
                'dtm_days'      => $planting['dtm_days_min'] === null ? null : [
                    'min'  => $planting['dtm_days_min'],
                    'max'  => $planting['dtm_days_max'],
                    'from' => $planting['dtm_counted_from'],
                ],
                'notes'   => $planting['notes'],
                'events'  => $byPlanting['events'][$id] ?? [],
                'pests'   => $byPlanting['pests'][$id] ?? [],
                'entries' => $byPlanting['narratives'][$id] ?? [],
            ], static fn (mixed $v): bool => $v !== null && $v !== []);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function weatherSection(User $user, string $from, string $to): array
    {
        if ($user->weatherLocationId === null) {
            return ['location' => null, 'weeks' => []];
        }
        $location = $this->weather->findLocation($user->weatherLocationId);

        return [
            'location' => $location === null ? null : [
                'label'    => $location['label'],
                'zip'      => $location['zip'],
                'timezone' => $location['timezone'],
            ],
            'units' => ['temperature' => 'C', 'precipitation' => 'mm', 'et0' => 'mm'],
            'weeks' => $this->weather->weeklySummary($user->weatherLocationId, $from, $to),
        ];
    }

    /**
     * The three per-planting reads, done once each and indexed.
     *
     * @return array{events:array<int,array<string,mixed>>,pests:array<int,list<string>>,
     *               narratives:array<int,list<array<string,mixed>>>}
     */
    private function groupByPlanting(string $from, string $to): array
    {
        $events = [];
        foreach ($this->events->summaryByPlanting($from, $to) as $row) {
            $events[(int) $row['planting_id']][(string) $row['event_type']] = \array_filter([
                'times'    => (int) $row['events'],
                'first'    => $row['first_date'],
                'last'     => $row['last_date'],
                // Only worth a line when some of them came from a zone: on
                // most plantings this is zero and saying so costs tokens for
                // nothing.
                'derived'  => (int) $row['derived'] > 0 ? (int) $row['derived'] : null,
                'minutes'  => $row['duration_min'] === null ? null : (int) $row['duration_min'],
                'weight_g' => $row['weight_g'] === null ? null : (float) $row['weight_g'],
                'count'    => $row['count_qty'] === null ? null : (int) $row['count_qty'],
                'lost'     => (int) $row['quantity_delta'] < 0 ? -(int) $row['quantity_delta'] : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        $pests = [];
        foreach ($this->events->pestsInWindow($from, $to) as $row) {
            $verb = (string) $row['event_type'] === \Carl\Domain\EventType::PEST_TREATED
                ? 'treated with' : 'observed';
            $pests[(int) $row['planting_id']][] = $verb . ' ' . $row['ref_name']
                . ' x' . (int) $row['events'] . ', last ' . $row['last_date'];
        }

        $narratives = [];
        foreach ($this->events->narrativesInWindow($from, $to, $this->maxNarratives) as $row) {
            $narratives[(int) $row['planting_id']][] = [
                'date'   => $row['event_date'],
                'action' => $row['event_type'],
                'note'   => $row['narrative'],
            ];
        }

        return ['events' => $events, 'pests' => $pests, 'narratives' => $narratives];
    }

    /**
     * A reference row, trimmed of the bookkeeping columns nobody reading it
     * needs. Same reason `ExportController::row()` drops `user_id`: a file
     * read by something that charges by the token should not carry an
     * `updated_at` per row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function clean(array $row): array
    {
        unset($row['id'], $row['created_at'], $row['updated_at'], $row['user_id']);
        return $row;
    }
}
