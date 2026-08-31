<?php

declare(strict_types=1);

namespace Carl\Analysis;

/**
 * What one analysis is about (Phase 6 handoff Section 3.5).
 *
 * Phase 5 shipped one kind: the whole season. It left an `analysis.scope`
 * column saying `season` on every row "precisely so this needs no migration",
 * and this class is that column's grammar: `season`, `garden:12`, `plant:34`.
 * Sixteen characters is what the column holds, which fits any id this
 * application will ever have.
 *
 * The reason a narrower one is worth having is not cost. It is that a
 * gardener looking at one struggling bed does not want a review of the year,
 * and an answer about everything is an answer about nothing in particular.
 *
 * **Parsing is not authorisation.** A scope read off a form says which rows
 * the document should be filtered to; whether this account may see them is a
 * separate question, answered by looking the subject up through the user's
 * own repositories. `AdviceController::ask()` does that before queueing, and
 * `Document` filters rows the repositories already scoped to the owner -- so
 * a forged `garden:999` produces an empty document, never somebody else's.
 */
final class Scope
{
    public const SEASON = 'season';
    public const GARDEN = 'garden';
    public const PLANT  = 'plant';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $subjectId,
    ) {
    }

    public static function season(): self
    {
        return new self(self::SEASON, null);
    }

    public static function garden(int $id): self
    {
        return new self(self::GARDEN, $id);
    }

    public static function plant(int $id): self
    {
        return new self(self::PLANT, $id);
    }

    /**
     * Read a stored or submitted scope. Anything unrecognised is the season,
     * which is the widest and safest reading: a scope Carl cannot parse must
     * not silently narrow an answer to nothing.
     */
    public static function parse(?string $raw): self
    {
        $raw = \trim((string) $raw);
        if ($raw === '' || $raw === self::SEASON) {
            return self::season();
        }

        $parts = \explode(':', $raw, 2);
        if (\count($parts) !== 2 || !\ctype_digit($parts[1]) || (int) $parts[1] <= 0) {
            return self::season();
        }

        return match ($parts[0]) {
            self::GARDEN => self::garden((int) $parts[1]),
            self::PLANT  => self::plant((int) $parts[1]),
            default      => self::season(),
        };
    }

    /** The value stored in `analysis.scope`, which VARCHAR(16) holds. */
    public function value(): string
    {
        return $this->subjectId === null ? $this->kind : $this->kind . ':' . $this->subjectId;
    }

    public function isSeason(): bool
    {
        return $this->kind === self::SEASON;
    }

    /** A label for the page and for the prompt, given the subject's name. */
    public function describe(?string $subjectName = null): string
    {
        if ($this->isSeason()) {
            return 'the whole season';
        }
        $name = $subjectName !== null && $subjectName !== '' ? $subjectName : ('#' . $this->subjectId);
        return ($this->kind === self::GARDEN ? 'the garden ' : 'the planting ') . $name;
    }
}
