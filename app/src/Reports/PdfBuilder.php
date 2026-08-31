<?php

declare(strict_types=1);

namespace Carl\Reports;

use Carl\Domain\EventType;
use Carl\Domain\PlantingState;
use Carl\Support\Photos;
use Carl\Support\Tokens;
use Carl\Support\Units;

/**
 * Composes a plant or garden report into a PDF (handoff Section 13.2):
 * research card, event table, charts, photos, citations.
 *
 * **The budget is what shapes this file**, and it is tight (hosting Section
 * 4): 30 s of `max_execution_time` and 128M of `memory_limit`, against a
 * target of under 10 s and 64 MB on a twenty-photo report.
 *
 * The whole cost is the photographs. GD holds a decoded image at roughly
 * width x height x 4 bytes, so a stored 1920 px photo is about 11 MB open --
 * twenty of them at once would be 220 MB and the request would die. So the
 * loop below reads ONE photo, downscales it, frees both GD handles and keeps
 * only the small JPEG string. Peak memory is one photo, not twenty.
 *
 * Photos are capped at MAX_PHOTOS and the document says so when it truncates.
 * Which twenty matters: a season report is about progression, so they are
 * taken as an even spread across the whole period rather than as the first
 * twenty, which would show only the seedlings.
 */
final class PdfBuilder
{
    /** Handoff Section 13.2: max 20 per report, and say so when it truncates. */
    public const MAX_PHOTOS = 20;

    /** Section 13.2 again: GD-downscaled to 800 px on the long edge. */
    public const PHOTO_EDGE = 800;

    /**
     * The event table is the other unbounded thing here. A plant watered
     * daily for three seasons has ~900 events; printing all of them makes a
     * twenty-page appendix nobody reads and costs the time budget. The table
     * carries the most recent of them and says how many it left out -- the
     * complete log is the CSV export, which is streamed and has no cap.
     */
    public const MAX_EVENTS = 250;

    public function __construct(
        private Photos $photos,
        private Units $units,
        private Tokens $tokens,
    ) {
    }

    /**
     * @param array<string,mixed> $series      from Series::forPlantingRow()
     * @param array<string,mixed> $planting    from PlantingRepository::findWithDetail()
     * @param array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
     * @param list<array<string,mixed>> $events from EventRepository::timeline()
     * @param list<array<string,mixed>> $photos from PhotoRepository::forPlanting()
     * @param array{weight_g:float,count_qty:int,events:int,first:?string,last:?string} $yield
     * @param list<string> $chartJpegs already validated and re-encoded
     */
    public function plant(
        array $series,
        array $planting,
        array $card,
        array $events,
        array $photos,
        array $yield,
        array $chartJpegs,
        int $userId,
        string $today,
    ): string {
        $subject = $series['subject'];
        $place = (string) $subject['where'];

        $pdf = new Document(
            (string) $subject['title'],
            \trim(PlantingState::label((string) $subject['state'])
                . ($place !== '' ? ' - ' . $place : '')),
            Units::longDate($today),
            $this->tokens
        );
        $pdf->open();

        $pdf->heading('Where it stands');
        $pdf->facts($this->plantFacts($planting, $yield));

        $this->researchCard($pdf, $card);
        $this->weatherSection($pdf, $series, $chartJpegs);
        $this->eventTable($pdf, $events);
        $this->photoSection($pdf, $photos, $userId);
        $this->citations($pdf, $series, $card);

        return $pdf->render();
    }

    /**
     * @param array<string,mixed> $series from Series::forGarden()
     * @param array<string,mixed> $garden
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $plantings
     * @param list<array<string,mixed>> $events from EventRepository::gardenTimeline()
     * @param list<array<string,mixed>> $photos
     * @param list<string> $chartJpegs
     */
    public function garden(
        array $series,
        array $garden,
        array $rows,
        array $plantings,
        array $events,
        array $photos,
        array $chartJpegs,
        int $userId,
        string $today,
    ): string {
        $pdf = new Document(
            (string) $garden['name'],
            \count($rows) . ' row' . (\count($rows) === 1 ? '' : 's')
                . ', ' . \count($plantings) . ' planting' . (\count($plantings) === 1 ? '' : 's'),
            Units::longDate($today),
            $this->tokens
        );
        $pdf->open();

        $pdf->heading('The garden');
        $pdf->facts([
            'Size' => $garden['ns_ft'] !== null && $garden['ew_ft'] !== null
                ? $garden['ns_ft'] . ' x ' . $garden['ew_ft'] . ' ft' : null,
            'Rows'        => \count($rows) . ' running '
                . ((string) $garden['row_orientation'] === 'ns' ? 'north-south' : 'east-west'),
            'Soil'        => \Carl\Domain\SoilType::label($garden['soil_type']),
            'Indoors'     => (int) $garden['is_indoor'] === 1 ? 'Yes' : null,
            'Report dates' => $this->rangeText($series['range']),
        ]);

        if ($plantings !== []) {
            $pdf->heading('What is planted here');
            $pdf->table(
                ['Plant', 'Row', 'State', 'Living', 'Started'],
                [62, 30, 32, 20, 36],
                (static function () use ($plantings): iterable {
                    foreach ($plantings as $planting) {
                        yield [
                            \trim($planting['category'] . ' ' . $planting['type']),
                            $planting['row_name'] ?? $planting['container_name'] ?? '',
                            PlantingState::label((string) $planting['state']),
                            (string) $planting['quantity_live'],
                            Units::longDate((string) $planting['start_date']),
                        ];
                    }
                })(),
                ['L', 'L', 'L', 'R', 'L']
            );
        }

        $this->weatherSection($pdf, $series, $chartJpegs);
        $this->gardenEventTable($pdf, $events);
        $this->photoSection($pdf, $photos, $userId);
        $this->citations($pdf, $series, ['plant' => null, 'regions' => []]);

        return $pdf->render();
    }

    // -- Sections ---------------------------------------------------------

    /**
     * @param array<string,mixed> $planting
     * @param array{weight_g:float,count_qty:int,events:int,first:?string,last:?string} $yield
     * @return array<string,string|null>
     */
    private function plantFacts(array $planting, array $yield): array
    {
        $initial = (int) $planting['quantity_initial'];
        $live = (int) $planting['quantity_live'];

        $facts = [
            'Started' => Units::longDate((string) $planting['start_date'])
                . ' (' . \str_replace('_', ' ', (string) $planting['start_method']) . ')',
            'In the ground' => $planting['in_ground_date'] !== null
                ? Units::longDate((string) $planting['in_ground_date']) : null,
            'Germinated' => $planting['germinated_at'] !== null
                ? Units::longDate((string) $planting['germinated_at']) : null,
            'Ended' => $planting['ended_at'] !== null
                ? Units::longDate((string) $planting['ended_at']) : null,
            'Living' => $live . ' of ' . $initial
                . ($initial > 0 ? ' (' . \round($live / $initial * 100) . '% survival)' : ''),
        ];

        if ($yield['events'] > 0) {
            $picked = [];
            if ($yield['weight_g'] > 0) {
                $picked[] = $this->units->weight($yield['weight_g']);
            }
            if ($yield['count_qty'] > 0) {
                $picked[] = $yield['count_qty'] . ' picked';
            }
            $facts['Yield'] = \implode(', ', $picked)
                . ' over ' . $yield['events'] . ' harvest' . ($yield['events'] === 1 ? '' : 's')
                . ', ' . Units::longDate($yield['first']) . ' to ' . Units::longDate($yield['last']);
        }

        return $facts;
    }

    /**
     * The research card (handoff Section 9.1), the same values the page
     * shows and with the same confidence markers beside them.
     *
     * The marker is not decoration. Section 9.1 is emphatic that a reader
     * must be able to tell a measured number for this county from a generic
     * one, and a printed report is the copy that outlives the screen.
     *
     * @param array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
     */
    private function researchCard(Document $pdf, array $card): void
    {
        $plant = $card['plant'];
        if ($plant === null) {
            return;
        }

        $pdf->heading('What the research says');

        $pdf->facts([
            'Plant' => \trim(((string) $plant['category']) . ' ' . ((string) $plant['type']))
                . ($plant['latin_name'] !== null ? ' (' . $plant['latin_name'] . ')' : '')
                . ($plant['confidence'] !== null ? '  [' . $plant['confidence'] . ']' : ''),
            'Family'           => $plant['plant_family'],
            'Days to maturity' => $this->dtmText($plant),
            'Germination'      => $this->germinationText($plant),
            'Spacing'          => $plant['spacing_in'] !== null
                ? Units::length($plant['spacing_in']) : null,
            'Seed depth'       => $plant['seed_depth_in'] !== null
                ? Units::length($plant['seed_depth_in']) : null,
            'Sun'              => $plant['sun'] !== null ? \ucfirst((string) $plant['sun']) : null,
            'Start indoors'    => $plant['weeks_before_transplant_to_start'] !== null
                ? $plant['weeks_before_transplant_to_start'] . ' weeks before transplanting' : null,
            'Harden off'       => $plant['hardening_days_default'] !== null
                ? $plant['hardening_days_default'] . ' days' : null,
            'Heat'             => (int) $plant['heat_tolerant'] === 1 ? 'Tolerant' : null,
        ]);

        if ($card['regions'] !== []) {
            $pdf->paragraph('In your area', 9);
            $pdf->table(
                ['Season', 'Window', 'Method', 'Recommended', 'Confidence'],
                [26, 46, 30, 34, 44],
                (static function () use ($card): iterable {
                    foreach ($card['regions'] as $region) {
                        yield [
                            \ucfirst((string) $region['season']),
                            Units::monthDayRange($region['window_start'], $region['window_end']),
                            (string) ($region['method'] ?? ''),
                            (int) $region['recommended'] === 1 ? 'yes' : '',
                            (string) ($region['confidence'] ?? ''),
                        ];
                    }
                })(),
                ['L', 'L', 'L', 'L', 'L']
            );

            foreach ($card['regions'] as $region) {
                $notes = \trim((string) ($region['regional_notes'] ?? ''));
                if ($notes !== '') {
                    $pdf->note(\ucfirst((string) $region['season']) . ': ' . $notes);
                }
            }
        } else {
            $pdf->note(
                'Carl has no researched planting windows for this county yet, so these are'
                . ' the general values. Days to maturity still counts down normally.'
            );
        }

        $notes = \trim((string) ($plant['notes'] ?? ''));
        if ($notes !== '') {
            $pdf->paragraph($notes, 9);
        }
    }

    /**
     * @param array<string,mixed> $series
     * @param list<string> $chartJpegs
     */
    private function weatherSection(Document $pdf, array $series, array $chartJpegs): void
    {
        $range = $series['range'];
        $hasDays = $series['days'] !== [];

        if (!$hasDays && $chartJpegs === []) {
            return;
        }

        $pdf->heading('The weather that actually happened');

        if ($hasDays) {
            $pdf->facts([
                'Dates'        => $this->rangeText($range),
                'Days covered' => (string) $range['days_held'],
                'Total rain'   => $series['totals']['rain'],
                'Total ET0'    => $series['totals']['et0'],
                'Water balance' => $series['totals']['balance'] . '  (rain minus evapotranspiration)',
                'Hottest / coldest' => $series['totals']['temp_range'],
            ]);
        }

        if ((int) $range['days_missing'] > 0) {
            $pdf->note(
                $range['days_missing'] . ' day' . ((int) $range['days_missing'] === 1 ? '' : 's')
                . ' in this range have not been fetched yet, so they are absent from the'
                . ' totals and from the charts rather than counted as zero.'
            );
        }
        if ((int) $range['provisional'] > 0) {
            $pdf->note(
                $range['provisional'] . ' of the days shown are still provisional: the archive'
                . ' revises recent days for about two weeks, so these figures can move.'
            );
        }
        if ($range['clamped'] === true) {
            $pdf->note(
                'This report covers the most recent ' . $range['max_days'] . ' days.'
                . ' Earlier weather is in the CSV export.'
            );
        }

        foreach ($chartJpegs as $jpeg) {
            $pdf->chart($jpeg);
        }
    }

    /** @param list<array<string,mixed>> $events from EventRepository::timeline() (newest first) */
    private function eventTable(Document $pdf, array $events): void
    {
        if ($events === []) {
            return;
        }
        $pdf->heading('Everything that was logged');

        $total = \count($events);
        $shown = \array_slice($events, 0, self::MAX_EVENTS);

        $pdf->table(
            ['Date', 'Action', 'Detail', 'Note'],
            [24, 40, 40, 76],
            (function () use ($shown): iterable {
                foreach ($shown as $event) {
                    yield [
                        Units::longDate((string) $event['event_date']),
                        EventType::label((string) $event['event_type'])
                            . ($event['source_garden_event_id'] !== null ? ' *' : ''),
                        $this->eventDetail($event),
                        (string) ($event['narrative'] ?? ''),
                    ];
                }
            })(),
            ['L', 'L', 'L', 'L']
        );

        if ($total > self::MAX_EVENTS) {
            $pdf->note(
                'The ' . self::MAX_EVENTS . ' most recent of ' . $total . ' entries are shown.'
                . ' The complete log is in the events CSV export.'
            );
        }
        // handoff Section 4.7: a zone watering writes one derived row per
        // living plant. Marking them is what stops a reader counting a bed
        // watering as a separate hand watering of this plant. The key is only
        // printed when a row actually carries the mark -- a legend for a
        // symbol that is not on the page is noise, and noise in a report
        // teaches people to skip the notes that matter.
        foreach ($shown as $event) {
            if ($event['source_garden_event_id'] !== null) {
                $pdf->note(
                    '* derived from a garden action rather than logged against this plant directly.'
                );
                break;
            }
        }
    }

    /** @param list<array<string,mixed>> $events from EventRepository::gardenTimeline() */
    private function gardenEventTable(Document $pdf, array $events): void
    {
        if ($events === []) {
            return;
        }
        $pdf->heading('Garden actions');

        $total = \count($events);
        $shown = \array_slice($events, 0, self::MAX_EVENTS);

        $pdf->table(
            ['Date', 'Action', 'Zone', 'Logged to', 'Note'],
            [24, 38, 34, 20, 64],
            (static function () use ($shown): iterable {
                foreach ($shown as $event) {
                    $bits = [];
                    foreach (['ref_name', 'ref_name_2'] as $key) {
                        if (($event[$key] ?? null) !== null && (string) $event[$key] !== '') {
                            $bits[] = (string) $event[$key];
                        }
                    }
                    if ($event['duration_min'] !== null) {
                        $bits[] = $event['duration_min'] . ' min';
                    }
                    yield [
                        Units::longDate((string) $event['event_date']),
                        EventType::label((string) $event['event_type'])
                            . ($bits !== [] ? ' - ' . \implode(', ', $bits) : ''),
                        (string) ($event['zone_name'] ?? ''),
                        (int) $event['fanout_count'] > 0 ? $event['fanout_count'] . ' plants' : '',
                        (string) ($event['narrative'] ?? ''),
                    ];
                }
            })(),
            ['L', 'L', 'L', 'R', 'L']
        );

        if ($total > self::MAX_EVENTS) {
            $pdf->note(
                'The ' . self::MAX_EVENTS . ' most recent of ' . $total . ' entries are shown.'
                . ' The complete log is in the events CSV export.'
            );
        }
    }

    /**
     * Photographs, one at a time.
     *
     * This is the whole memory budget of the report (Section 13.2). The loop
     * body opens exactly one file, and Photos::downscaledJpeg frees both GD
     * handles before it returns -- so peak use is one decoded photo plus the
     * small JPEGs already collected, not twenty decoded photos.
     *
     * @param list<array<string,mixed>> $photos
     */
    private function photoSection(Document $pdf, array $photos, int $userId): void
    {
        if ($photos === []) {
            return;
        }

        $total = \count($photos);
        $chosen = self::spread($photos, self::MAX_PHOTOS);

        $prepared = [];
        foreach ($chosen as $photo) {
            $jpeg = $this->photos->downscaledJpeg(
                $userId, (string) $photo['stored_name'], self::PHOTO_EDGE
            );
            if ($jpeg === null) {
                continue;   // a missing file loses one picture, not the report
            }
            $caption = \trim((string) ($photo['caption'] ?? ''));
            $prepared[] = [
                'jpeg'    => $jpeg,
                'caption' => Units::longDate((string) $photo['taken_on'])
                    . ($caption !== '' ? ' - ' . $caption : ''),
            ];
        }

        if ($prepared === []) {
            return;
        }

        $pdf->heading('Photographs');
        $pdf->photoGrid($prepared);

        if ($total > self::MAX_PHOTOS) {
            $pdf->note(
                self::MAX_PHOTOS . ' of ' . $total . ' photographs are shown, spread evenly'
                . ' across the period so the report follows the progression rather than'
                . ' stopping at the first ' . self::MAX_PHOTOS . '.'
            );
        }
    }

    /**
     * @param array<string,mixed> $series
     * @param array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
     */
    private function citations(Document $pdf, array $series, array $card): void
    {
        $lines = $series['attribution'];

        // The research rows carry their own source, and a report that prints
        // a first-frost date without saying where it came from is the thing
        // handoff Section 9 exists to prevent.
        $sources = [];
        foreach ($card['regions'] as $region) {
            $source = \trim((string) ($region['source'] ?? ''));
            if ($source !== '') {
                $sources[$source] = true;
            }
        }

        if ($lines === [] && $sources === []) {
            return;
        }

        $pdf->heading('Where these numbers come from');
        foreach ($lines as $line) {
            $pdf->paragraph($line, 8);
        }
        foreach (\array_keys($sources) as $source) {
            $pdf->paragraph('Regional research: ' . $source, 8);
        }
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Take at most $limit items, evenly spaced, always keeping the first and
     * the last. A season report is about progression; the first twenty
     * photographs of a sixty-photograph season are all seedlings.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function spread(array $items, int $limit): array
    {
        $count = \count($items);
        if ($count <= $limit || $limit < 1) {
            return $items;
        }
        if ($limit === 1) {
            return [$items[0]];
        }

        $out = [];
        $step = ($count - 1) / ($limit - 1);
        for ($i = 0; $i < $limit; $i++) {
            $out[] = $items[(int) \round($i * $step)];
        }
        return $out;
    }

    /** @param array<string,mixed> $range */
    private function rangeText(array $range): string
    {
        if ((int) $range['days'] <= 0) {
            return 'nothing covered yet';
        }
        return Units::longDate((string) $range['from']) . ' to ' . Units::longDate((string) $range['to']);
    }

    /** @param array<string,mixed> $plant */
    private function dtmText(array $plant): ?string
    {
        $min = $plant['dtm_days_min'] ?? null;
        $max = $plant['dtm_days_max'] ?? null;
        if ($min === null && $max === null) {
            return null;
        }
        $range = $min !== null && $max !== null ? $min . '-' . $max : (string) ($min ?? $max);
        $from = (string) ($plant['dtm_counted_from'] ?? '');
        return $range . ' days' . ($from !== '' ? ' from ' . \str_replace('_', ' ', $from) : '');
    }

    /** @param array<string,mixed> $plant */
    private function germinationText(array $plant): ?string
    {
        if ($plant['germ_days_min'] === null) {
            return null;
        }
        $text = $plant['germ_days_min']
            . ($plant['germ_days_max'] !== null ? '-' . $plant['germ_days_max'] : '') . ' days';
        if ($plant['germ_soil_temp_f_min'] !== null) {
            // Soil temperatures come from the research tables in Fahrenheit
            // by definition -- they are the numbers on a seed packet, not SI
            // measurements to convert (research-template README, "Units").
            $text .= ' at ' . $plant['germ_soil_temp_f_min']
                . ($plant['germ_soil_temp_f_max'] !== null ? '-' . $plant['germ_soil_temp_f_max'] : '')
                . "\u{00B0}F soil";
        }
        return $text;
    }

    /** @param array<string,mixed> $event */
    private function eventDetail(array $event): string
    {
        $bits = [];
        foreach (['ref_name', 'ref_name_2', 'row_name', 'container_name'] as $key) {
            $value = \trim((string) ($event[$key] ?? ''));
            if ($value !== '') {
                $bits[] = $value;
            }
        }
        if ($event['quantity_delta'] !== null && (int) $event['quantity_delta'] !== 0) {
            $bits[] = (int) $event['quantity_delta'] > 0
                ? '+' . $event['quantity_delta'] : (string) $event['quantity_delta'];
        }
        if ($event['duration_min'] !== null) {
            $bits[] = $event['duration_min'] . ' min';
        }
        if ($event['weight_g'] !== null && (float) $event['weight_g'] > 0) {
            $bits[] = $this->units->weight($event['weight_g']);
        }
        if ($event['count_qty'] !== null && (int) $event['count_qty'] > 0) {
            $bits[] = $event['count_qty'] . ' picked';
        }
        return \implode(', ', $bits);
    }
}
