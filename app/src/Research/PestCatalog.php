<?php

declare(strict_types=1);

namespace Carl\Research;

use Carl\Core\Database;
use RuntimeException;

/**
 * The built-in pest, disease and disorder reference (Phase 9).
 *
 * `db/migrations/022_pest_reference.sql` is the argument for why this exists
 * at all -- read it first. This file is the content, and it is in PHP rather
 * than in the migration for one reason: a migration is immutable once applied
 * (hosting Section 7) and this is editorial prose about living things. A
 * corrected sentence about squash vine borer must not need a new schema
 * version, and an admin must be able to re-apply the catalogue after a
 * correction ships. `apply()` is idempotent on `pest_key`, so re-applying it
 * converges rather than duplicating, and `/admin/reference-sync` is the
 * button.
 *
 * ------------------------------------------------------------------
 * WHAT IS IN HERE, AND WHAT DELIBERATELY IS NOT
 * ------------------------------------------------------------------
 *
 * **Facts and practices, summarised in Carl's own words from the cooperative
 * extension IPM literature.** Every entry is written here rather than copied:
 * the biology and the control practices are facts and widely published, the
 * prose in any one publication is not ours to lift, and `source` names the
 * publication families each entry is drawn from so a gardener can go and read
 * the long version.
 *
 * **Active ingredients, never brands. No rates, ever. No crop clearances.**
 * "The label is the law" is not a slogan: FIFRA section 12(a)(2)(G) makes it
 * a federal violation to use a registered pesticide in a manner inconsistent
 * with its labeling, states hold the enforcement, and which products are
 * registered for which crop differs between them. A table that printed a rate
 * would be telling somebody to break the law about half the time and would be
 * out of date the rest. So `chemical_controls` names what the active
 * ingredient IS and leaves every number to the packet in the reader's hand.
 *
 * **The organic answer comes first because it usually is the answer.** For a
 * home garden a row cover, a hand-pick, a hose and a resistant variety solve
 * most of this list outright, which is what every extension IPM programme
 * says. `chemical_controls` is last in the record and last on the screen.
 *
 * **`pollinator_risk` is a flag, not a sentence, because it is the hazard
 * that is invisible.** Spinosad is a good example and it is why the column
 * exists: it is OMRI-listed, it is on EPA's reduced-risk list, and it is
 * acutely toxic to honey bees while the spray is still wet and essentially
 * harmless once it has dried. "Apply at dusk" is the entire difference
 * between those two facts and nothing on the shelf says it loudly.
 *
 * **No photographs and no identification key.** Carl is a log, not a
 * diagnostic tool, and a wrong identification confidently delivered is worse
 * than none. `signs` and `look_alikes` are written to help somebody recognise
 * what they are already looking at, and every entry that could be confused
 * with a nutrient problem says so.
 *
 * ------------------------------------------------------------------
 * THE SHAPE OF A ROW
 * ------------------------------------------------------------------
 *
 * `affects_categories` uses the `plant_type.category` vocabulary and is
 * semicolon-separated, matching `pest_region.affects_categories` and every
 * other multi-valued cell in the research template. An EMPTY value means
 * "anything", which is right for slugs and for frost and wrong for squash
 * vine borer.
 *
 * `severity` is what it costs to ignore, not how unpleasant it looks:
 *   cosmetic    -- you will still eat the crop
 *   manageable  -- a smaller crop, or a few plants, if left alone
 *   serious     -- most of that crop, or the planting, in a bad year
 *   fatal       -- the plant does not recover, and often neither does the bed
 */
final class PestCatalog
{
    /**
     * The publication families every entry is summarised from. Named at the
     * level of the programme rather than a document number: these are the
     * home-garden IPM sources a US gardener can actually reach, and the
     * county extension office is the right next step for anything specific.
     */
    public const SOURCE = 'Summarised from cooperative extension IPM literature: '
        . 'UC IPM Pest Notes, Cornell and UMass vegetable IPM fact sheets, '
        . 'Ohio State Ohioline, Texas A&M AgriLife, University of Minnesota Extension.';

    /** The columns the seed file carries, in order. Its header must match. */
    private const FIELDS = [
        'pest_key', 'name', 'latin_name', 'also_called', 'kind', 'affects_categories',
        'severity', 'description', 'signs', 'consequence', 'look_alikes', 'monitoring',
        'prevention', 'organic_controls', 'chemical_controls', 'beneficials',
        'pollinator_risk', 'treatments',
    ];

    /** @var list<array<string,string>>|null */
    private static ?array $cache = null;

    /**
     * Upsert the catalogue. Idempotent on `pest_key`: re-applying converges.
     *
     * WHERE IT MEETS THE RESEARCH IMPORTER, because that writes seven of the
     * same columns and the two have to agree on a rule rather than race.
     *
     * THE RULE IS LAST WRITER WINS ON THE SIX SHARED COLUMNS -- `name`,
     * `kind`, `description`, `signs`, `treatments`, `source` -- AND BOTH
     * WRITERS ARE IDEMPOTENT, EXPLICIT ADMIN ACTIONS. Import a county dataset
     * and the pests it names read as that dataset wrote them; press "re-apply"
     * on /admin/research-import and they read as Carl ships them. Neither is
     * lost, neither happens by itself, and each is one click from the other.
     *
     * `source` deliberately moves WITH the text rather than being pinned to
     * either side: an entry whose description came from a county dataset is
     * credited to that dataset's source, and one showing Carl's text is
     * credited to the extension literature it was summarised from. Pinning
     * `source` to the catalogue would attribute somebody else's sentences to
     * us, and pinning the description without the source would do the reverse.
     *
     * NOTHING ELSE IS SHARED. The twelve columns 022 added -- consequence,
     * look_alikes, monitoring, prevention, organic_controls,
     * chemical_controls, beneficials and the rest -- are the catalogue's
     * alone, because the research template does not carry them and will not
     * until it goes to version 3. An imported entry that Carl does not ship
     * therefore has a name, a description, signs and a `treatments` line, and
     * the reference screen falls back to that line rather than drawing an
     * empty card.
     *
     * @return int rows written
     */
    public static function apply(Database $db, string $root): int
    {
        $rows = self::rows($root);
        if ($rows === []) {
            return 0;
        }

        $now = \gmdate('Y-m-d H:i:s');
        $columns = \array_merge(self::FIELDS, ['source', 'is_builtin', 'created_at', 'updated_at']);

        // Everything except `treatments`, `pest_key` and `created_at`.
        $update = [
            'name', 'latin_name', 'also_called', 'kind', 'affects_categories', 'severity',
            'description', 'signs', 'consequence', 'look_alikes', 'monitoring', 'prevention',
            'organic_controls', 'chemical_controls', 'beneficials', 'pollinator_risk',
            'source', 'is_builtin', 'updated_at',
        ];

        $tuples = [];
        foreach ($rows as $row) {
            $tuple = [];
            foreach (self::FIELDS as $field) {
                $value = $row[$field] ?? '';
                $tuple[] = match ($field) {
                    // An empty cell is NULL, never an empty string -- except
                    // for affects_categories, where "" already means "every
                    // crop" throughout the research schema and a NULL would
                    // be a second spelling of the same thing.
                    'pollinator_risk'    => (int) $value,
                    'affects_categories' => $value,
                    default              => $value === '' ? null : $value,
                };
            }
            $tuple[] = self::SOURCE;
            $tuple[] = 1;
            $tuple[] = $now;
            $tuple[] = $now;
            $tuples[] = $tuple;
        }

        // Chunked at twenty, the way ListRepository::seedForNewUser() chunks.
        // upsertChunk() binds positionally, so the named-placeholder rule
        // (hosting Section 7) is not what is at stake here -- the statement
        // SIZE is. Twenty-two columns of prose across seventy-six rows in one
        // INSERT is several hundred kilobytes against a max_allowed_packet
        // this account does not set and cannot see.
        foreach (\array_chunk($tuples, 20) as $chunk) {
            $db->upsertChunk('pest', $columns, $chunk, $update);
        }

        return \count($tuples);
    }

    /**
     * The catalogue, read from `db/seed/pest_catalog.csv`.
     *
     * A CSV and not a PHP array, for the same reason `db/seed/zcta.csv` is
     * one: this is a table of data that people read and correct, and a table
     * is the shape a diff of it is legible in. It is also very nearly the
     * research template's own `pests.csv` with the new columns added, so
     * taking the template to version 3 later is a header change rather than a
     * rewrite.
     *
     * @return list<array<string,string>>
     */
    public static function rows(string $root): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = $root . '/db/seed/pest_catalog.csv';
        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Pest catalogue seed file is missing: ' . $path);
        }

        $header = \fgetcsv($handle, 0, ',', '"', '');
        if ($header !== self::FIELDS) {
            \fclose($handle);
            throw new RuntimeException(
                'Pest catalogue header does not match PestCatalog::FIELDS. '
                . 'The seed file and the class have to agree column for column.'
            );
        }

        $rows = [];
        $seen = [];
        while (($line = \fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            if (\count($line) !== \count(self::FIELDS)) {
                \fclose($handle);
                throw new RuntimeException(
                    'Pest catalogue row ' . (\count($rows) + 1) . ' has ' . \count($line)
                    . ' cells, expected ' . \count(self::FIELDS) . '.'
                );
            }
            /** @var array<string,string> $row */
            $row = \array_combine(self::FIELDS, \array_map(
                static fn (?string $cell): string => \trim((string) $cell),
                $line
            ));
            // A duplicate key would silently collapse two entries into one on
            // the upsert, and the count would still look right.
            if (isset($seen[$row['pest_key']])) {
                \fclose($handle);
                throw new RuntimeException('Pest catalogue has two entries for ' . $row['pest_key'] . '.');
            }
            $seen[$row['pest_key']] = true;
            $rows[] = $row;
        }
        \fclose($handle);

        return self::$cache = $rows;
    }

    /** How many entries the catalogue ships. */
    public static function count(string $root): int
    {
        return \count(self::rows($root));
    }
}
