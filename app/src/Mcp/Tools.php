<?php

declare(strict_types=1);

namespace Carl\Mcp;

use Carl\Analysis\Document;
use Carl\Auth\User;
use Carl\Core\App;
use Carl\Core\HttpException;
use Carl\Domain\DripLine;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\ReferenceRepository;
use Carl\Repo\TagRepository;
use Carl\Repo\WateringRepository;
use Carl\Repo\WeatherRepository;
use Carl\Reports\Series;
use Carl\Support\Attribution;
use Carl\Support\Clock;

/**
 * What the MCP server can be asked (Phase 16; Phase 15 handoff Section 3.1).
 *
 * Eight tools and one resource, every one of them READ-ONLY, and every one
 * of them a cut of data Carl already shapes for Claude: `/export/claude.json`
 * and `Analysis\Document` are "Carl, for Claude", and this is those two in
 * pieces a conversation asks for.
 *
 * THE ISOLATION IS THE REPOSITORIES'. Nothing here writes SQL. Every read
 * goes through the same user-scoped classes the pages use, constructed for
 * the token's owner, so a tool cannot reach another account any more than a
 * page can (handoff Section 5). The thing that would break that is a tool
 * that assembled its own statement; do not add one.
 *
 * THE BOUNDS ARE NOT OPTIONAL. The raw export of a five-year account is
 * 3.3 MB and 918,000 tokens; a tool that returns it has failed the caller.
 * Every list takes a limit with a ceiling, every timeline is capped, and the
 * encoded result is measured against mcp.max_response_bytes before it
 * leaves -- over the cap, the answer is an error that says how to narrow
 * the question, never a truncated document that looks whole.
 */
final class Tools
{
    public const SUMMARY_URI = 'carl://export/summary';

    private ?PlantingRepository $plantings = null;
    private ?EventRepository $events = null;
    private ?GardenRepository $gardens = null;
    private ?TagRepository $tags = null;
    private ?WateringRepository $watering = null;
    private ?WeatherRepository $weather = null;
    private ?ReferenceRepository $reference = null;

    public function __construct(
        private App $app,
        private User $user,
        private string $today,
        private int $maxBytes = 262144,
    ) {
    }

    /** One paragraph the client shows its model on connect. */
    public function instructions(): string
    {
        return 'Carl is one gardener\'s own record: what they planted, what they did to it,'
            . ' and the weather that actually happened where they are. Every tool reads that'
            . ' gardener\'s data and nothing else, and nothing here writes. Dates are the'
            . ' gardener\'s local calendar days. Weather from the `weather` tool is in the'
            . ' gardener\'s display units; the summary resource and the export are SI.'
            . ' Start with `list_gardens` and `list_plants`, or read ' . self::SUMMARY_URI
            . ' for the whole season in one document.';
    }

    /**
     * The tools, in the shape tools/list returns.
     *
     * @return list<array<string,mixed>>
     */
    public function catalogue(): array
    {
        $int = static fn (string $description, ?int $minimum = null): array
            => \array_filter(['type' => 'integer', 'description' => $description, 'minimum' => $minimum],
                static fn (mixed $v): bool => $v !== null);
        $str = static fn (string $description): array => ['type' => 'string', 'description' => $description];
        $bool = static fn (string $description): array => ['type' => 'boolean', 'description' => $description];
        $schema = static fn (array $properties, array $required = []): array
            => ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties,
                'required' => $required, 'additionalProperties' => false];

        return [
            [
                'name'        => 'list_gardens',
                'title'       => 'List gardens',
                'description' => 'Every active garden with its rows, watering zones (and what each zone'
                    . ' puts down, where its emitter figures are known) and the containers. Small; call it first.',
                'inputSchema' => $schema([]),
                'annotations' => self::readOnly('List gardens'),
            ],
            [
                'name'        => 'list_plants',
                'title'       => 'List plants',
                'description' => 'Plantings, living by default, newest first, with where each stands, its'
                    . ' counts and the QR tag codes on it. Filter by garden or search by name, variety'
                    . ' label or tag code. Use `plant` for one planting\'s full history.',
                'inputSchema' => $schema([
                    'living'    => $bool('Only plantings that have not ended. Default true.'),
                    'garden_id' => $int('Only this garden.', 1),
                    'search'    => $str('Matches category, type, the gardener\'s label, or a tag code.'),
                    'limit'     => $int('Rows to return. Default 100, at most 500.', 1),
                ]),
                'annotations' => self::readOnly('List plants'),
            ],
            [
                'name'        => 'plant',
                'title'       => 'One planting in full',
                'description' => 'One planting: its record, its whole timeline of logged events newest'
                    . ' first (with sizes, yields and durations), its yield totals, the tag codes on it,'
                    . ' and the research card in force for the gardener\'s region.',
                'inputSchema' => $schema([
                    'id'           => $int('The planting id, from list_plants.', 1),
                    'events_limit' => $int('Timeline rows to include. Default 200, at most 1000.', 1),
                ], ['id']),
                'annotations' => self::readOnly('One planting'),
            ],
            [
                'name'        => 'weather',
                'title'       => 'Weather over a range',
                'description' => 'Daily weather. Give plant_id or garden_id for the days that subject has been'
                    . ' in the ground, in the gardener\'s display units with the subject\'s own events'
                    . ' beside it; or give from and to (YYYY-MM-DD, at most 400 days) for the gardener\'s'
                    . ' location as stored, in SI.',
                'inputSchema' => $schema([
                    'plant_id'  => $int('A planting id.', 1),
                    'garden_id' => $int('A garden id.', 1),
                    'from'      => $str('First day, YYYY-MM-DD. Default 30 days ago.'),
                    'to'        => $str('Last day, YYYY-MM-DD. Default today.'),
                ]),
                'annotations' => self::readOnly('Weather'),
            ],
            [
                'name'        => 'watering',
                'title'       => 'Today\'s watering recommendation',
                'description' => 'For each garden and container: water, likely or skip, with the soil-water'
                    . ' arithmetic behind it and, where a zone knows its emitters, the minutes that would'
                    . ' refill the deficit. Computed overnight, not now; the row says when.',
                'inputSchema' => $schema([]),
                'annotations' => self::readOnly('Watering today'),
            ],
            [
                'name'        => 'garden_actions',
                'title'       => 'Garden-level actions',
                'description' => 'What was done to a whole garden or one of its zones -- waterings, mulch,'
                    . ' fertiliser, pest treatments -- newest first. A zone watering also appears on each'
                    . ' plant it reached; those plant rows carry source_garden_event_id, so do not count both.',
                'inputSchema' => $schema([
                    'garden_id' => $int('The garden id, from list_gardens.', 1),
                    'limit'     => $int('Rows to return. Default 100, at most 500.', 1),
                ], ['garden_id']),
                'annotations' => self::readOnly('Garden actions'),
            ],
            [
                'name'        => 'research_card',
                'title'       => 'Research card for a plant type',
                'description' => 'The catalogue values for a plant type -- days to maturity, spacing, depth,'
                    . ' water needs -- plus this region\'s sowing and transplant windows and companion'
                    . ' pairings, each with its source and confidence. Search by name to find the id.',
                'inputSchema' => $schema([
                    'plant_type_id' => $int('A plant type id.', 1),
                    'search'        => $str('Part of a category or type name, e.g. "tomato" or "Cherokee".'),
                ]),
                'annotations' => self::readOnly('Research card'),
            ],
            [
                'name'        => 'pests',
                'title'       => 'Pests and diseases',
                'description' => 'The pest and disease reference: signs, what it costs, what to do, and'
                    . ' when it is active in this region. active_only narrows it to what is active for'
                    . ' the categories this gardener grows, today.',
                'inputSchema' => $schema([
                    'search'      => $str('Part of a name, latin name or the signs text.'),
                    'kind'        => ['type' => 'string', 'enum' => ['pest', 'disease', 'disorder'],
                                      'description' => 'One kind only.'],
                    'category'    => $str('Only entries affecting this plant category, e.g. "Tomato".'),
                    'active_only' => $bool('Only what is active in the region today for what is grown.'),
                    'limit'       => $int('Rows to return. Default 30, at most 100.', 1),
                ]),
                'annotations' => self::readOnly('Pests and diseases'),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function resources(): array
    {
        return [[
            'uri'         => self::SUMMARY_URI,
            'name'        => 'season-summary',
            'title'       => 'The season, summarised',
            'description' => 'The whole account for the last ' . $this->app->config()->int('analysis.days', 365)
                . ' days as one bounded document: gardens, plantings with per-action roll-ups and the'
                . ' gardener\'s notes, weekly weather in SI, and the research in force. The same'
                . ' document Carl\'s own Recommendations feature sends; about 40,000 tokens for a'
                . ' busy account.',
            'mimeType'    => 'application/json',
        ]];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed> a tools/call result
     */
    public function call(string $name, array $arguments): array
    {
        try {
            $data = match ($name) {
                'list_gardens'   => $this->listGardens(),
                'list_plants'    => $this->listPlants($arguments),
                'plant'          => $this->plant($arguments),
                'weather'        => $this->weather($arguments),
                'watering'       => $this->watering(),
                'garden_actions' => $this->gardenActions($arguments),
                'research_card'  => $this->researchCard($arguments),
                'pests'          => $this->pests($arguments),
                default          => throw new \InvalidArgumentException('Unknown tool: ' . $name),
            };
        } catch (HttpException $e) {
            // "Not one of your plants": a 404 on a page, and here a result
            // that says so -- the conversation can act on it, an exception
            // cannot. The message never says whether the id exists for
            // somebody else, because the repository never knew.
            return self::failure($e->getMessage());
        } catch (ToolFailure $e) {
            return self::failure($e->getMessage());
        }

        return $this->bounded($data, $name);
    }

    /** @return array<string,mixed> a resources/read content entry */
    public function readResource(string $uri): array
    {
        if ($uri !== self::SUMMARY_URI) {
            throw new ResourceNotFound('No such resource: ' . $uri);
        }
        $document = Document::forUser($this->app, $this->user)->build($this->user, $this->today);
        return [
            'uri'      => $uri,
            'mimeType' => 'application/json',
            'text'     => self::encode($document),
        ];
    }

    // -- The tools ---------------------------------------------------------

    /** @return array<string,mixed> */
    private function listGardens(): array
    {
        $gardens = $this->gardens()->activeGardens();
        $ids = \array_map(static fn (array $g): int => (int) $g['id'], $gardens);
        $rows = $this->gardens()->rowsForGardens($ids);
        $zones = $this->gardens()->zonesForGardens($ids);

        $out = [];
        foreach ($gardens as $garden) {
            $id = (int) $garden['id'];
            $rowSpacing = DripLine::rowSpacingIn($garden);
            $out[] = self::clean($garden) + [
                'rows'  => \array_map(self::clean(...), $rows[$id] ?? []),
                'zones' => \array_map(static function (array $zone) use ($rowSpacing): array {
                    $spec = DripLine::resolve($zone, $rowSpacing);
                    return self::clean($zone) + [
                        'puts_down' => $spec === null ? null : $spec['description'],
                    ];
                }, $zones[$id] ?? []),
            ];
        }

        return [
            'today'      => $this->today,
            'gardens'    => $out,
            'containers' => \array_map(self::clean(...), $this->gardens()->containers(true)),
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function listPlants(array $a): array
    {
        $filters = ['living' => self::bool($a, 'living', true)];
        $gardenId = self::int($a, 'garden_id');
        if ($gardenId !== null) {
            $filters['garden_id'] = $gardenId;
        }
        $search = self::string($a, 'search');
        if ($search !== null && $search !== '') {
            $filters['search'] = \substr($search, 0, 80);
        }
        $limit = self::int($a, 'limit', 100, 1, 500);

        $rows = $this->plantings()->listWithDetail($filters, $limit);
        $codes = $this->tags()->codesForPlantings(\array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return [
            'today'     => $this->today,
            'count'     => \count($rows),
            'truncated' => \count($rows) === $limit,
            'plantings' => \array_map(static function (array $row) use ($codes): array {
                return self::clean($row) + ['tags' => $codes[(int) $row['id']] ?? []];
            }, $rows),
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function plant(array $a): array
    {
        $id = self::int($a, 'id');
        if ($id === null) {
            throw new \InvalidArgumentException('plant needs an id.');
        }
        $planting = $this->plantings()->findWithDetail($id);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }
        $limit = self::int($a, 'events_limit', 200, 1, 1000);

        $timeline = $this->events()->timeline($id);
        $card = $this->reference()->researchCard((int) $planting['plant_type_id'], $this->user->regionId);

        return [
            'today'     => $this->today,
            'planting'  => self::clean($planting),
            'yield'     => $this->plantings()->yieldSummary($id),
            'tags'      => \array_map(static fn (array $t): string => (string) $t['code'], $this->tags()->tagsOn($id)),
            'events'    => [
                'total'     => \count($timeline),
                'truncated' => \count($timeline) > $limit,
                'rows'      => \array_map(self::clean(...), \array_slice($timeline, 0, $limit)),
            ],
            'research'  => [
                'plant'      => $card['plant'] === null ? null : self::clean($card['plant']),
                'windows'    => \array_map(self::clean(...), $card['regions']),
                'companions' => $card['companions'],
            ],
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function weather(array $a): array
    {
        $locationId = $this->user->weatherLocationId;
        $plantId = self::int($a, 'plant_id');
        $gardenId = self::int($a, 'garden_id');

        if ($plantId !== null || $gardenId !== null) {
            $series = new Series($this->plantings(), $this->events(), $this->gardens(),
                $this->weatherRepo(), $this->app->units());
            return $plantId !== null
                ? $series->forPlanting($plantId, $locationId, $this->today)
                : $series->forGarden((int) $gardenId, $locationId, $this->today);
        }

        if ($locationId === null) {
            throw new ToolFailure('This account has no weather location yet.');
        }

        $to = Clock::parseDate(self::string($a, 'to')) ?? $this->today;
        $from = Clock::parseDate(self::string($a, 'from')) ?? (string) Clock::addDays($to, -29);
        if ($from > $to) {
            throw new \InvalidArgumentException('from is after to.');
        }
        if ((int) Clock::daysBetween($from, $to) > 400) {
            throw new \InvalidArgumentException('At most 400 days at a time; narrow the range.');
        }

        $rows = $this->weatherRepo()->series($locationId, $from, $to);
        $models = [];
        foreach ($rows as $row) {
            $models[(string) $row['source_model']] = true;
        }

        return [
            'from'        => $from,
            'to'          => $to,
            'units'       => ['temperature' => 'C', 'precipitation' => 'mm', 'et0' => 'mm', 'wind' => 'km/h'],
            'days'        => $rows,
            'attribution' => Attribution::lines(\array_keys($models)),
        ];
    }

    /** @return array<string,mixed> */
    private function watering(): array
    {
        $rows = $this->wateringRepo()->forDate($this->today);
        return [
            'today'            => $this->today,
            'computed_last_at' => $rows === [] ? $this->wateringRepo()->lastComputedAt() : null,
            'places'           => \array_map(static function (array $row): array {
                unset($row['user_id'], $row['place_key']);
                return $row;
            }, $rows),
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function gardenActions(array $a): array
    {
        $gardenId = self::int($a, 'garden_id');
        if ($gardenId === null) {
            throw new \InvalidArgumentException('garden_actions needs a garden_id.');
        }
        $garden = $this->gardens()->findOrFail($gardenId);
        $limit = self::int($a, 'limit', 100, 1, 500);
        $rows = $this->events()->gardenTimeline($gardenId, $limit);

        return [
            'garden'    => ['id' => (int) $garden['id'], 'name' => $garden['name']],
            'count'     => \count($rows),
            'truncated' => \count($rows) === $limit,
            'events'    => \array_map(static function (array $row): array {
                unset($row['payload']);
                return self::clean($row);
            }, $rows),
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function researchCard(array $a): array
    {
        $typeId = self::int($a, 'plant_type_id');
        if ($typeId !== null) {
            $card = $this->reference()->researchCard($typeId, $this->user->regionId);
            if ($card['plant'] === null) {
                throw new ToolFailure('No plant type with that id.');
            }
            return [
                'plant'      => self::clean($card['plant']),
                'windows'    => \array_map(self::clean(...), $card['regions']),
                'companions' => $card['companions'],
                'region_researched' => $this->user->regionId !== null,
            ];
        }

        $search = \strtolower(\trim((string) self::string($a, 'search')));
        if ($search === '') {
            throw new \InvalidArgumentException('research_card needs a plant_type_id or a search.');
        }
        $matches = [];
        foreach ($this->reference()->plantTypesForRegion($this->user->regionId, $this->today) as $type) {
            $haystack = \strtolower($type['category'] . ' ' . $type['type'] . ' ' . ($type['latin_name'] ?? ''));
            if (\str_contains($haystack, $search)) {
                $matches[] = [
                    'plant_type_id'   => (int) $type['id'],
                    'category'        => $type['category'],
                    'type'            => $type['type'],
                    'plant_family'    => $type['plant_family'] ?? null,
                    'in_region'       => (int) ($type['in_region'] ?? 0) === 1,
                    'recommended'     => (int) ($type['recommended'] ?? 0) === 1,
                    'earliest_window' => $type['earliest_window'] ?? null,
                ];
            }
            if (\count($matches) >= 25) {
                break;
            }
        }
        return ['search' => $search, 'matches' => $matches];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function pests(array $a): array
    {
        $limit = self::int($a, 'limit', 30, 1, 100);

        if (self::bool($a, 'active_only', false)) {
            $categories = $this->plantings()->filterOptions()['categories'];
            $rows = $this->reference()->activePests($this->user->regionId, $categories, $this->today);
            return [
                'today'      => $this->today,
                'categories_grown' => $categories,
                'region_researched' => $this->user->regionId !== null,
                'count'      => \count($rows),
                'active'     => \array_map(self::clean(...), \array_slice($rows, 0, $limit)),
            ];
        }

        $filters = [];
        foreach (['search', 'kind', 'category'] as $key) {
            $value = self::string($a, $key);
            if ($value !== null && $value !== '') {
                $filters[$key] = \substr($value, 0, 80);
            }
        }
        $rows = $this->reference()->pestCatalogue($filters);
        return [
            'count'     => \count($rows),
            'truncated' => \count($rows) > $limit,
            'entries'   => \array_map(self::clean(...), \array_slice($rows, 0, $limit)),
        ];
    }

    // -- Shape ---------------------------------------------------------------

    /**
     * The result envelope, with the bound applied. Over the cap, an error
     * that says so and how to narrow it: a truncated JSON document looks
     * whole and is worse than no document.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function bounded(array $data, string $tool): array
    {
        $text = self::encode($data);
        if (\strlen($text) > $this->maxBytes) {
            return self::failure(\sprintf(
                '%s returned %d KB, over the %d KB cap. Narrow it: a smaller limit, a search, one garden,'
                . ' or a shorter date range.',
                $tool, \intdiv(\strlen($text), 1024), \intdiv($this->maxBytes, 1024)
            ));
        }
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function failure(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /** @return array<string,mixed> */
    private static function readOnly(string $title): array
    {
        return ['title' => $title, 'readOnlyHint' => true, 'destructiveHint' => false,
                'idempotentHint' => true, 'openWorldHint' => false];
    }

    private static function encode(mixed $value): string
    {
        $encoded = \json_encode($value,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * A row for a reader that pays by the token: no user_id (it is this
     * user's on every row), no nulls (an absent key says the same thing).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function clean(array $row): array
    {
        unset($row['user_id']);
        return \array_filter($row, static fn (mixed $v): bool => $v !== null);
    }

    /** @param array<string,mixed> $a */
    private static function int(array $a, string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
    {
        $value = $a[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value)) && !(\is_float($value) && \floor($value) === $value)) {
            throw new \InvalidArgumentException($key . ' must be an integer.');
        }
        $value = (int) $value;
        if ($min !== null && $value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }
        return $value;
    }

    /** @param array<string,mixed> $a */
    private static function string(array $a, string $key): ?string
    {
        $value = $a[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_scalar($value)) {
            throw new \InvalidArgumentException($key . ' must be a string.');
        }
        return \trim((string) $value);
    }

    /** @param array<string,mixed> $a */
    private static function bool(array $a, string $key, bool $default): bool
    {
        $value = $a[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value) && \in_array(\strtolower($value), ['true', 'false', '1', '0'], true)) {
            return \in_array(\strtolower($value), ['true', '1'], true);
        }
        throw new \InvalidArgumentException($key . ' must be true or false.');
    }

    // -- Repositories, for the token's owner ----------------------------------

    private function plantings(): PlantingRepository
    {
        return $this->plantings ??= new PlantingRepository($this->app->db(), $this->user->id);
    }

    private function events(): EventRepository
    {
        return $this->events ??= new EventRepository($this->app->db(), $this->user->id, $this->plantings());
    }

    private function gardens(): GardenRepository
    {
        return $this->gardens ??= new GardenRepository($this->app->db(), $this->user->id);
    }

    private function tags(): TagRepository
    {
        return $this->tags ??= new TagRepository($this->app->db(), $this->user->id);
    }

    private function wateringRepo(): WateringRepository
    {
        return $this->watering ??= new WateringRepository($this->app->db(), $this->user->id);
    }

    private function weatherRepo(): WeatherRepository
    {
        return $this->weather ??= new WeatherRepository($this->app->db());
    }

    private function reference(): ReferenceRepository
    {
        return $this->reference ??= new ReferenceRepository($this->app->db());
    }
}
