<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\ListType;

/**
 * The pest and disease reference (Phase 9).
 *
 * `db/migrations/022_pest_reference.sql` is the argument for why the
 * catalogue exists and `Carl\Research\PestCatalog` is the argument for what
 * is in it. This is the screen, and it has one job: to be readable by
 * somebody standing at a plant with a hole in a leaf.
 *
 * **It is a reference, not a diagnostic tool.** There is no key, no decision
 * tree and no photographs. A wrong identification delivered confidently is
 * worse than none, so the screen shows what a thing looks like, what it costs
 * to ignore, what it is confused with, and what to do -- and leaves the
 * identifying to the person who can see it.
 *
 * **A LIST THAT EXPANDS ONE ENTRY, RATHER THAN SEVENTY-SIX CARDS.** Drawn in
 * full the catalogue is 202 KB of HTML, which is ten times the whole client
 * shell and several seconds on the connection somebody standing in a garden
 * actually has. So the page is a list -- name, what it attacks, and the line
 * that says what you would see -- and `?key=` opens one entry in full. The
 * list is still the whole catalogue in one statement and still searchable in
 * the browser, because `signs` is the sentence people search by; the eight
 * paragraphs behind each name are fetched when somebody asks for them.
 *
 * **The default filter is "affects something I grow".** Which is the whole
 * difference between a catalogue and a useful one: a gardener with tomatoes
 * and beans does not need the entry on clubroot, and the toggle to see
 * everything is one link away for the day they plant cabbage.
 */
final class PestController extends Controller
{
    public function index(Request $request): Response
    {
        // Controller::choice() reads a POSTED field; this is a GET filter, so
        // the same check is made here rather than borrowed.
        $kind = (string) ($request->query('kind', '') ?? '');
        if (!\in_array($kind, ['pest', 'disease', 'disorder'], true)) {
            $kind = '';
        }
        $search = (string) ($request->query('q', '') ?? '');
        $category = (string) ($request->query('category', '') ?? '');

        // What this account actually grows, from the plantings it already
        // has. One statement, and it is also what fills the category filter,
        // so the dropdown can never offer something with nothing behind it.
        $grown = $this->categoriesGrown();

        // `all=1` is the escape hatch, and it has to exist: somebody reading
        // about clubroot in February has not planted the cabbage yet.
        $mineOnly = $request->query('all') === null && $grown !== [];

        $pests = $this->reference()->pestCatalogue([
            'kind' => $kind, 'search' => $search, 'category' => $category,
        ]);

        if ($mineOnly && $category === '') {
            $pests = self::affecting($pests, $grown);
        }

        // The expanded entry, read on its own rather than picked out of the
        // list: a link from the Lists screen or a bookmark must open the card
        // it names whether or not the current filters would have shown it.
        // One statement, and only when somebody asked for a card.
        $key = (string) ($request->query('key', '') ?? '');
        $selected = $key === '' ? null : $this->reference()->findPestByKey($key);

        return $this->render('pests/index', [
            'pests'      => $pests,
            'selected'   => $selected,
            'kind'       => $kind,
            'search'     => $search,
            'category'   => $category,
            'grown'      => $grown,
            'mineOnly'   => $mineOnly,
            // The account's own additions, so the screen can say plainly that
            // the catalogue is a starting point and not a closed list.
            'ownCount'   => $this->ownEntryCount(),
            'pageTitle'  => 'Pests and diseases',
        ]);
    }

    /**
     * The categories this account grows, lower-cased for matching and in
     * their original spelling for the dropdown.
     *
     * @return array<string,string> lower-cased key => display name
     */
    private function categoriesGrown(): array
    {
        $rows = $this->app->db()->all(
            'SELECT DISTINCT pt.`category` FROM `planting` p'
            . ' JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' WHERE p.`user_id` = :user_id ORDER BY pt.`category`',
            ['user_id' => $this->userId()]
        );
        $out = [];
        foreach ($rows as $row) {
            $name = (string) $row['category'];
            $out[\strtolower(\trim($name))] = $name;
        }
        return $out;
    }

    /**
     * Keep the entries that touch one of these categories, plus the ones that
     * touch everything.
     *
     * An empty `affects_categories` means "anything" throughout the research
     * schema, and here that is load-bearing rather than a default: slugs,
     * cutworms, frost, waterlogging and herbicide drift all carry an empty
     * cell, and they are exactly the entries a gardener most needs. A filter
     * that dropped them would hide the general answers and keep the specific
     * ones.
     *
     * @param list<array<string,mixed>> $pests
     * @param array<string,string> $grown
     * @return list<array<string,mixed>>
     */
    private static function affecting(array $pests, array $grown): array
    {
        $out = [];
        foreach ($pests as $pest) {
            $affects = \array_filter(\array_map(
                static fn (string $c): string => \strtolower(\trim($c)),
                \explode(';', (string) ($pest['affects_categories'] ?? ''))
            ));
            if ($affects === [] || \array_intersect($affects, \array_keys($grown)) !== []) {
                $out[] = $pest;
            }
        }
        return $out;
    }

    /** How many pest entries this account added itself, rather than got with Carl. */
    private function ownEntryCount(): int
    {
        return (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM `user_list_item`'
            . ' WHERE `user_id` = :user_id AND `list_type` = :list_type AND `pest_id` IS NULL',
            ['user_id' => $this->userId(), 'list_type' => ListType::PEST_DISEASE],
            0
        );
    }
}
