<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * The label sheets Carl knows how to print (docs/QR-TAGS-SPEC.md Section 5.3),
 * and the sheet geometry each one needs.
 *
 * BOTH STOCKS ARE FIRST-CLASS. The choice follows the printer the user owns,
 * and neither is right for everyone: the polyester stock is the more durable
 * by a distance and is laser-only; the self-laminating stock seals ordinary
 * inkjet output under a clear flap and therefore works with any printer. The
 * default is the self-laminating one, because it is the one that cannot fail
 * on an unknown printer -- a user who has never opened the setting gets the
 * safe answer, and a user with a laser changes it once.
 *
 * A Domain class like EventType and SoilType, so adding a third stock is one
 * entry and every screen that lists stocks picks it up.
 *
 * ---------------------------------------------------------------------------
 * WHERE THESE NUMBERS COME FROM, AND WHAT VERIFIES THEM
 * ---------------------------------------------------------------------------
 *
 * Section 5.6 says to take the geometry from each manufacturer's published
 * template and never from arithmetic here, because Avery's pitches are not
 * always label width plus gutter and a 0.5 mm error compounds across eight
 * rows into a visibly crooked sheet. That is the right instruction and it was
 * only half followable: Avery publishes the label size, the sheet size and
 * the per-sheet count, and for 60517 a margin, but does NOT publish the
 * pitches or the origin anywhere reachable.
 *
 * So each number below is marked with where it came from:
 *
 *   [published]  Avery states it: page size, label size, labels per sheet,
 *                and 60517's 0.325 in margin.
 *   [derived]    Computed here by centring the grid on the sheet at the
 *                published margin. Symmetric top-to-bottom and left-to-right,
 *                which every Avery die is.
 *
 * A [derived] number is a hypothesis. Section 5.6 already says what settles
 * it, and it is not this file: THE REGISTRATION TEST SHEET
 * (/tags/batches/{id}/registration.pdf) prints the same layout as outlines on
 * plain paper, to be held against a real label sheet up to a window. That is
 * the acceptance test for these constants, it is not optional the first time
 * a SKU is printed, and it is why the print screen puts it in front of the
 * user rather than in a help page.
 *
 * If a sheet comes back misregistered, correct the numbers HERE -- one entry,
 * no migration, and every batch already minted re-renders correctly, because
 * a batch stores only which stock it was for.
 */
final class LabelStock
{
    public const AVERY_60517 = 'avery_60517';
    public const AVERY_00757 = 'avery_00757';

    /** Section 5.3: the one that cannot fail on an unknown printer. */
    public const FALLBACK = self::AVERY_00757;

    /** US Letter, in millimetres. Every Avery template is Letter, never A4. */
    public const PAGE_W = 215.9;
    public const PAGE_H = 279.4;

    /**
     * @var array<string,array{
     *   name:string, size:string, printer:string, note:string,
     *   columns:int, rows:int,
     *   label_w:float, label_h:float, print_h:float,
     *   origin_x:float, origin_y:float, pitch_x:float, pitch_y:float
     * }>
     */
    private const STOCKS = [
        self::AVERY_60517 => [
            'name'    => 'Avery UltraDuty 60517',
            'size'    => '1 x 2.5 in polyester, 24 per sheet',
            'printer' => 'Laser only',
            'note'    => 'Polyester film, not paper: waterproof, UV resistant, and rated to '
                       . 'BS5609 section 2 -- ninety days in seawater. A tomato bed is a gentle '
                       . 'environment by comparison. It will not survive an inkjet.',
            // [published] 24 per sheet at 1 x 2.5 in on Letter can only be 3 across.
            'columns' => 3,
            'rows'    => 8,
            // [published] 1 x 2.5 in.
            'label_w' => 63.5,
            'label_h' => 25.4,
            // The whole label is printable; there is no flap.
            'print_h' => 25.4,
            // [published] Avery's own margin note for 60517: 0.325 in.
            'origin_x' => 8.255,
            // [derived] (215.9 - 2 x 8.255 - 63.5) / 2 columns of gap.
            'pitch_x' => 67.945,
            // [derived] Eight 1 in rows, contiguous, centred: (279.4 - 203.2) / 2.
            'origin_y' => 38.1,
            'pitch_y'  => 25.4,
        ],
        self::AVERY_00757 => [
            'name'    => 'Avery Easy Align 00757',
            'size'    => 'self-laminating, 1-1/32 x 3-1/2 in printable, 10 per sheet',
            'printer' => 'Laser or inkjet',
            'note'    => 'Half the label is printable white and half is a clear flap that folds '
                       . 'over and seals the print under polyester. The ink never touches water, '
                       . 'so ordinary inkjet output survives. Pick this if there is no laser printer.',
            // [published] 10 per sheet at 3.5 in wide on Letter can only be 2 across.
            'columns' => 2,
            'rows'    => 5,
            // [published] the printable area is 1-1/32 x 3-1/2 in.
            'label_w' => 88.9,
            'print_h' => 26.194,
            // [derived] The die is the printable area plus a laminating flap of
            // the same height: 2-1/16 in. THE PRINT GOES IN THE TOP HALF and the
            // clear flap folds up over it -- which is the one thing about this
            // stock the registration sheet exists to confirm, because printing
            // into the flap wastes a whole sheet and looks fine until it is
            // folded.
            'label_h' => 52.3875,
            // [derived] Two 3.5 in columns centred on 8.5 in leaves 0.625 in a side.
            'origin_x' => 15.875,
            'pitch_x'  => 95.25,
            // [derived] Five 2-1/16 in rows centred on 11 in.
            'origin_y' => 8.73125,
            'pitch_y'  => 52.3875,
        ],
    ];

    /** @return array<string,string> value => label, for a select */
    public static function options(): array
    {
        $out = [];
        foreach (self::STOCKS as $key => $stock) {
            $out[$key] = $stock['name'] . ' -- ' . $stock['size'];
        }
        return $out;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return \array_keys(self::STOCKS);
    }

    public static function isValid(?string $sku): bool
    {
        return $sku !== null && isset(self::STOCKS[$sku]);
    }

    /** Anything unknown -- an old batch, a hand-edited row -- reads as the safe stock. */
    public static function orFallback(?string $sku): string
    {
        return self::isValid($sku) ? (string) $sku : self::FALLBACK;
    }

    public static function name(?string $sku): string
    {
        return self::STOCKS[self::orFallback($sku)]['name'];
    }

    public static function size(?string $sku): string
    {
        return self::STOCKS[self::orFallback($sku)]['size'];
    }

    public static function printer(?string $sku): string
    {
        return self::STOCKS[self::orFallback($sku)]['printer'];
    }

    public static function note(?string $sku): string
    {
        return self::STOCKS[self::orFallback($sku)]['note'];
    }

    public static function perSheet(?string $sku): int
    {
        $stock = self::STOCKS[self::orFallback($sku)];
        return $stock['columns'] * $stock['rows'];
    }

    /**
     * The full geometry, in millimetres from the top-left corner of the page.
     *
     * @return array{
     *   columns:int, rows:int, per_sheet:int,
     *   label_w:float, label_h:float, print_h:float,
     *   origin_x:float, origin_y:float, pitch_x:float, pitch_y:float
     * }
     */
    public static function geometry(?string $sku): array
    {
        $stock = self::STOCKS[self::orFallback($sku)];
        return [
            'columns'   => $stock['columns'],
            'rows'      => $stock['rows'],
            'per_sheet' => $stock['columns'] * $stock['rows'],
            'label_w'   => $stock['label_w'],
            'label_h'   => $stock['label_h'],
            'print_h'   => $stock['print_h'],
            'origin_x'  => $stock['origin_x'],
            'origin_y'  => $stock['origin_y'],
            'pitch_x'   => $stock['pitch_x'],
            'pitch_y'   => $stock['pitch_y'],
        ];
    }

    /**
     * The top-left corner of the printable area of one label, 0-indexed
     * across then down.
     *
     * @return array{0:float,1:float} x, y in millimetres
     */
    public static function position(?string $sku, int $index): array
    {
        $g = self::geometry($sku);
        $column = $index % $g['columns'];
        $row = \intdiv($index, $g['columns']) % $g['rows'];

        return [
            $g['origin_x'] + $column * $g['pitch_x'],
            $g['origin_y'] + $row * $g['pitch_y'],
        ];
    }

    /**
     * Where a label is on the physical sheet, in the words a person uses to
     * find it: "sheet 2, row 3, column 1", all 1-based.
     *
     * The desk half of docs/QR-TAGS-SPEC.md Section 5.2 asks somebody to
     * pick a free code off a list and then find that label on a sheet of
     * twenty-four. The code is printed on the label, so it can be found by
     * reading -- but a row and a column turn a scan of the sheet into a
     * glance, and they cost nothing, because minting order IS sheet order
     * (LabelSheet::sheetsOf() places tags in the order they were minted).
     *
     * @param int $ordinal the tag's 0-based position within its batch
     * @return array{sheet:int,row:int,column:int}
     */
    public static function place(?string $sku, int $ordinal): array
    {
        $g = self::geometry($sku);
        $within = $ordinal % $g['per_sheet'];

        return [
            'sheet'  => \intdiv($ordinal, $g['per_sheet']) + 1,
            'row'    => \intdiv($within, $g['columns']) + 1,
            'column' => $within % $g['columns'] + 1,
        ];
    }

    /**
     * Does the last label fit on the page?
     *
     * Not decoration: AutoPageBreak is off on a label sheet (Section 5.5), so
     * geometry that runs past the paper does not error and does not spill to a
     * second page -- it prints off the bottom and the labels simply are not
     * there. This is asserted in 21_tags_test.php for every stock, so a
     * corrected constant that is corrected wrong is caught by the suite rather
     * than by a wasted sheet.
     */
    public static function fitsPage(?string $sku): bool
    {
        $g = self::geometry($sku);
        $right = $g['origin_x'] + ($g['columns'] - 1) * $g['pitch_x'] + $g['label_w'];
        $bottom = $g['origin_y'] + ($g['rows'] - 1) * $g['pitch_y'] + $g['label_h'];

        return $right <= self::PAGE_W + 0.001 && $bottom <= self::PAGE_H + 0.001;
    }
}
