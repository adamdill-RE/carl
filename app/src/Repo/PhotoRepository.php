<?php

declare(strict_types=1);

namespace Carl\Repo;

/**
 * Photograph rows. The files themselves live in var/photos/<user_id>/,
 * outside public_html, and are served only through a controller that checks
 * ownership -- never a direct URL (handoff Section 5.3).
 */
final class PhotoRepository extends Repository
{
    protected function table(): string
    {
        return 'photo';
    }

    protected function writable(): array
    {
        return ['planting_id', 'garden_id', 'plant_event_id', 'garden_event_id', 'taken_on',
                'stored_name', 'thumb_name', 'width', 'height', 'bytes', 'caption'];
    }

    protected function hasUpdatedAt(): bool
    {
        return false;
    }

    /** @return list<array<string,mixed>> chronological, the order a report reads in */
    public function forPlanting(int $plantingId): array
    {
        return $this->where('`planting_id` = :planting_id', ['planting_id' => $plantingId],
            '`taken_on`, `id`');
    }

    /** @return list<array<string,mixed>> */
    public function forGarden(int $gardenId): array
    {
        return $this->where('`garden_id` = :garden_id', ['garden_id' => $gardenId],
            '`taken_on`, `id`');
    }

    /** @return list<array<string,mixed>> */
    public function forEvent(int $plantEventId): array
    {
        return $this->where('`plant_event_id` = :event_id', ['event_id' => $plantEventId], '`id`');
    }

    /**
     * Photo counts for many plantings at once, so a list showing a camera
     * badge costs one statement rather than one per row.
     *
     * @param list<int> $plantingIds
     * @return array<int,int>
     */
    public function countsForPlantings(array $plantingIds): array
    {
        if ($plantingIds === []) {
            return [];
        }
        $params = [];
        $in = self::inClause($plantingIds, 'p', $params);
        $rows = $this->db->all(
            'SELECT `planting_id`, COUNT(*) AS n FROM `photo`'
            . ' WHERE ' . $this->scoped('`planting_id` ' . $in)
            . ' GROUP BY `planting_id`',
            $this->bind($params)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['planting_id']] = (int) $row['n'];
        }
        return $out;
    }

    /** Attach photos uploaded before the event existed to that event. */
    public function attachToEvent(array $photoIds, int $plantEventId): int
    {
        if ($photoIds === []) {
            return 0;
        }
        $params = [];
        $in = self::inClause($photoIds, 'ph', $params);
        return $this->db->run(
            'UPDATE `photo` SET `plant_event_id` = :event_id'
            . ' WHERE ' . $this->scoped('`id` ' . $in . ' AND `plant_event_id` IS NULL'),
            $this->bind($params + ['event_id' => $plantEventId])
        )->rowCount();
    }

    public function attachToGardenEvent(array $photoIds, int $gardenEventId): int
    {
        if ($photoIds === []) {
            return 0;
        }
        $params = [];
        $in = self::inClause($photoIds, 'ph', $params);
        return $this->db->run(
            'UPDATE `photo` SET `garden_event_id` = :event_id'
            . ' WHERE ' . $this->scoped('`id` ' . $in . ' AND `garden_event_id` IS NULL'),
            $this->bind($params + ['event_id' => $gardenEventId])
        )->rowCount();
    }
}
