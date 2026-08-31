<?php

declare(strict_types=1);

namespace Carl\Support;

/**
 * CSV writing, with the one guard hosting Section 8.5 makes non-optional:
 * a cell that a spreadsheet would read as a formula is neutralised before it
 * is written.
 *
 * The attack is worth stating plainly, because the mitigation looks like
 * paranoia otherwise. A user types a plant nickname of
 * `=cmd|' /C calc'!A0`, exports their own plants, and opens the file. Excel
 * treats a leading `=` as a formula and offers to run it. The person harmed
 * is the person the export was made for, and nothing in the app looked
 * wrong at any point.
 *
 * `-` and `+` also lead formulas, but they lead ordinary numbers far more
 * often: quantity_delta is negative on every cull. Prefixing `-3` would
 * corrupt the column an export exists to let someone analyse. So a cell that
 * is a complete numeric literal is left alone -- no formula is also a valid
 * number, so the exemption cannot let one through.
 *
 * RFC 4180 quoting otherwise: CRLF line endings, doubled quotes, and a field
 * quoted only when it needs to be.
 */
final class Csv
{
    /** What a spreadsheet may read as the start of a formula. */
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Excel on Windows reads a CSV as the system codepage unless a UTF-8 BOM
     * says otherwise, which turns every accented cultivar name to mojibake.
     * Other readers skip it.
     */
    public const BOM = "\u{FEFF}";

    /**
     * Neutralise a cell that would otherwise be read as a formula.
     *
     * The leading apostrophe is the conventional mitigation: a spreadsheet
     * reads the rest as literal text and shows it without the quote.
     */
    public static function neutralise(string $value): string
    {
        // Spaces only. ltrim's default charlist includes tab and carriage
        // return, which are themselves on the dangerous list -- trimming them
        // away would make the check look past the very characters it is
        // there to catch.
        $probe = \ltrim($value, ' ');
        if ($probe === '' || !\in_array($probe[0], self::DANGEROUS, true)) {
            return $value;
        }
        if (self::isNumericLiteral($probe)) {
            return $value;
        }
        return "'" . $value;
    }

    /** A complete number, and therefore incapable of being a formula. */
    public static function isNumericLiteral(string $value): bool
    {
        return \preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $value) === 1;
    }

    /** One field: neutralised, then quoted if the format needs it. */
    public static function field(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }

        $text = self::neutralise((string) $value);

        // Quote when the format requires it, when leading or trailing
        // whitespace would otherwise be lost, and always when the cell was
        // neutralised -- so the apostrophe reads as part of the value rather
        // than as an accident.
        $needsQuotes = \strpbrk($text, ",\"\r\n") !== false
            || $text !== \trim($text, " \t")
            || \str_starts_with($text, "'");

        return $needsQuotes ? '"' . \str_replace('"', '""', $text) . '"' : $text;
    }

    /** @param list<mixed> $values */
    public static function line(array $values): string
    {
        return \implode(',', \array_map(self::field(...), $values)) . "\r\n";
    }
}
