<?php

// carl:kind=dml
//
// Apply the built-in pest catalogue and put it in front of every account that
// already exists (Phase 9). `022_pest_reference.sql` is the argument; this is
// the data landing.
//
// A .php migration and not a .sql one, for the reason 011_seed_zcta.php is
// one: the content is too bulky and too much of a table to express as literal
// SQL, and `Carl\Research\PestCatalog` reads it from `db/seed/pest_catalog.csv`
// -- which is where it can be corrected without a schema change and re-applied
// from /admin/reference-sync.
//
// THREE STATEMENTS' WORTH OF WORK, IN THIS ORDER, AND THE ORDER MATTERS.
//
//   1. The catalogue upserts on `pest_key`. The sixteen keys the Hill County
//      dataset uses -- aphid, spider_mite, squash_vine_borer and the rest --
//      are deliberately the same keys the catalogue uses, so on an install
//      that has already imported it the rows MERGE rather than doubling.
//      PestCatalog::apply() is the docblock on which side owns which column;
//      the short version is that the catalogue owns the description of the
//      organism and the dataset keeps `treatments` and everything regional.
//
//   2. ADOPT BEFORE INSERTING. Somebody who typed "Aphids" into the inline
//      "+ Add new..." last season has a `user_list_item` row with a NULL
//      `pest_id` and the unique key (user_id, list_type, name) already spoken
//      for. Pointing that row at the catalogue entry -- rather than failing to
//      insert beside it -- is what turns a year of their own typing into
//      joined-up data, and it is the whole reason this migration is three
//      statements and not one. The match is on name, which under
//      utf8mb4_unicode_ci is case- and accent-insensitive, exactly like the
//      unique index it has to agree with.
//
//   3. Insert what is left. `NOT EXISTS` on `pest_id` is enough BECAUSE step
//      two ran first: any name collision has already been adopted, so nothing
//      here can hit the unique key.
//
// Then the treatments. `ListType::seedPestTreatments()` is the shelf a
// gardener reaches for, and it is seeded rather than left blank for the same
// reason as the cull reasons in Phase 1: the first time somebody logs a
// treatment they are standing at a plant, not designing a taxonomy. They are
// ordinary user rows and can be archived like any other.

declare(strict_types=1);

use Carl\Core\Database;
use Carl\Domain\ListType;
use Carl\Research\PestCatalog;

return static function (Database $db): void {
    $root = \dirname(__DIR__, 2);

    PestCatalog::apply($db, $root);

    // 2. Adopt the rows an account typed for itself that name a catalogue
    //    entry. Only where `pest_id` is NULL: a row already pointed somewhere
    //    is not ours to move.
    $db->run(
        'UPDATE `user_list_item` u JOIN `pest` p ON p.`name` = u.`name`'
        . ' SET u.`pest_id` = p.`id`, u.`updated_at` = UTC_TIMESTAMP()'
        . ' WHERE u.`list_type` = :list_type AND u.`pest_id` IS NULL',
        ['list_type' => ListType::PEST_DISEASE]
    );

    // 3. Every catalogue entry that is not yet on an account's list.
    //    ListRepository::syncPestsFromReference() does the same thing one
    //    account at a time and is what keeps it true afterwards; this is the
    //    set-based version, for the accounts that already exist.
    $db->run(
        'INSERT INTO `user_list_item`'
        . ' (`user_id`, `list_type`, `name`, `pest_id`, `is_active`, `sort_order`,'
        . '  `created_at`, `updated_at`)'
        . ' SELECT u.`id`, :list_type, p.`name`, p.`id`, 1, 0,'
        . '        UTC_TIMESTAMP(), UTC_TIMESTAMP()'
        . ' FROM `user` u CROSS JOIN `pest` p'
        . ' WHERE NOT EXISTS ('
        . '   SELECT 1 FROM `user_list_item` x'
        . '   WHERE x.`user_id` = u.`id` AND x.`list_type` = :existing_type'
        . '     AND x.`pest_id` = p.`id`'
        . ' )',
        ['list_type' => ListType::PEST_DISEASE, 'existing_type' => ListType::PEST_DISEASE]
    );

    // The treatment shelf, for the accounts that already exist. Same shape,
    // and `sort_order` keeps the least drastic options at the top of the
    // dropdown -- which is the order an IPM programme puts them in and the
    // order somebody should read them in at the moment of choosing.
    $treatments = ListType::seedPestTreatments();
    if ($treatments !== []) {
        $userIds = $db->column('SELECT `id` FROM `user`');
        $rows = [];
        foreach ($userIds as $userId) {
            foreach ($treatments as $index => [$name, $active]) {
                $rows[] = [(int) $userId, ListType::PEST_TREATMENT, $name, $active,
                           1, $index, \gmdate('Y-m-d H:i:s'), \gmdate('Y-m-d H:i:s')];
            }
        }
        foreach (\array_chunk($rows, 200) as $chunk) {
            // Re-running changes nothing that matters: `name` is the natural
            // key and the update touches only the two columns the seed owns.
            $db->upsertChunk(
                'user_list_item',
                ['user_id', 'list_type', 'name', 'attr_1', 'is_active', 'sort_order',
                 'created_at', 'updated_at'],
                $chunk,
                ['attr_1']
            );
        }
    }
};
