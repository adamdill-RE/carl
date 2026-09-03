<?php

declare(strict_types=1);

namespace Carl\Domain;

use Carl\Qr\TagUrl;

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
 *                60517's 0.325 in margin -- and, for 00757, the two sizes in
 *                Avery's own Easy Align brochure (GEN-0816-06, 2015): "LABEL
 *                SIZE 3/4 in x 3-1/4 in" and "LABEL SIZE WITH CLEAR LAMINATE
 *                1-1/32 in x 3-1/2 in".
 *   [observed]   Read off a real sheet by the owner, holding it (Phase 16):
 *                the 00757 die is ONE column of ten, not two columns of
 *                five, and the clear flap is BESIDE each label, not below.
 *   [derived]    Computed here by centring the grid on the sheet at the
 *                published margin, and the printable face on its laminate.
 *                Symmetric top-to-bottom and left-to-right, which every
 *                Avery die is.
 *
 * A [derived] number is a hypothesis. Section 5.6 already says what settles
 * it, and it is not this file: THE REGISTRATION TEST SHEET
 * (/tags/batches/{id}/registration.pdf) prints the same layout as outlines on
 * plain paper, to be held against a real label sheet up to a window. That is
 * the acceptance test for these constants, it is not optional the first time
 * a SKU is printed, and it is why the print screen puts it in front of the
 * user rather than in a help page.
 *
 * THE 00757 LAYOUT WAS WRONG FOR FIFTEEN PHASES AND THE SUITE COULD NOT
 * KNOW. Phase 8 derived "10 per sheet at 3.5 in wide can only be 2 across"
 * and stacked five rows of two, with the flap folding UP from under each
 * label. The real sheet -- in the owner's hand, Phase 16 -- is ten labels in
 * one column down the left, each with its clear flap to the RIGHT, folding
 * over sideways. Two columns of five is not a rounding error: it prints five
 * codes into the flaps, where they are wiped off by the laminate, and five
 * more into the margin between rows. Every number that a test can check
 * (fits the page, rows do not overlap) was true of both layouts, which is
 * exactly why the registration sheet, and not the suite, is the acceptance
 * test.
 *
 * AND THEN THE 00757 FACE WAS THE WRONG SIZE FOR ONE MORE (Phase 17). Every
 * listing says "1-1/32 x 3-1/2 in", and sixteen phases read that as the
 * printable label. It is the label WITH ITS LAMINATE: Avery's own brochure
 * gives the white face as 3/4 x 3-1/4 in, sitting inside a 1-1/32 x 3-1/2
 * in clear border that the flap seals to. The code had been sized to a
 * 26 mm face that is really 19 mm tall, so its ink ran 18.8 mm down a 19.05
 * mm label: exact on a perfect feed, and outside the top or the bottom edge
 * on every real one. The owner said so in one sentence -- "just outside the
 * upper and lower boundary" -- and that sentence is the measurement. So a
 * stock now carries BOTH rectangles, the die (the laminate footprint, which
 * is what the sheet is cut to and what the registration sheet draws) and
 * the face (the white, which is all that may carry ink), and `symbolBox()`
 * sizes the code to the face with a margin for the feed.
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
     * The stake the label ends up on: 1 in wide (Section 1.2). The code is
     * centred on this much of the label's width, so a label applied a little
     * off-centre still has the whole symbol on the face.
     */
    public const STAKE_W = 25.4;

    /**
     * The smallest symbol (quiet zone included) worth printing. A face that
     * cannot hold this has no business carrying a code, and a stock whose
     * numbers come out under it has a typo in them.
     */
    private const MIN_SYMBOL_MM = 12.0;

    /**
     * @var array<string,array{
     *   name:string, size:string, printer:string, note:string,
     *   columns:int, rows:int,
     *   label_w:float, label_h:float, print_h:float,
     *   die_w:float, die_h:float, flap_w:float, edge:float,
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
            // [published] 1 x 2.5 in, and the whole of it is printable.
            'label_w' => 63.5,
            'label_h' => 25.4,
            'print_h' => 25.4,
            // No laminate border and no flap: the die is the label.
            'die_w'   => 63.5,
            'die_h'   => 25.4,
            'flap_w'  => 0.0,
            // [derived] Section 2.3's application slop. A laser places the
            // sheet to a fraction of a millimetre; this is for the hand that
            // sticks the label on, not for the printer.
            'edge'    => 0.7,
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
            'size'    => 'self-laminating, 3/4 x 3-1/4 in printable face, 10 per sheet',
            'printer' => 'Laser or inkjet',
            'note'    => 'Each label is a white face of 3/4 x 3-1/4 in inside a clear 1-1/32 x 3-1/2 '
                       . 'in border, with a clear flap of the same size to its right that folds '
                       . 'over sideways and seals the print under polyester. The ink never '
                       . 'touches water, so ordinary inkjet output survives. Pick this if there '
                       . 'is no laser printer.',
            // [observed] ONE column of ten, down the left of the sheet. The
            // "two across" of earlier phases put half the codes into the
            // flaps.
            'columns' => 1,
            'rows'    => 10,
            // [published] The brochure's "LABEL SIZE": 3/4 x 3-1/4 in. This
            // is the white, and the only part that may carry ink. The
            // "1-1/32 x 3-1/2 in" on every listing is the size WITH the
            // laminate -- see the class docblock for what believing
            // otherwise cost.
            'label_w' => 82.55,
            'label_h' => 19.05,
            // The whole face is printable; the flap is beside it, not below,
            // so nothing here folds up over the print.
            'print_h' => 19.05,
            // [published] "LABEL SIZE WITH CLEAR LAMINATE": 1-1/32 x 3-1/2
            // in. The die is cut to this; the face sits inside it.
            'die_w'   => 88.9,
            'die_h'   => 26.194,
            // [observed] The clear flap: the die's own size, to its right,
            // hinged on the die's right-hand edge. Drawn on the registration
            // sheet so the fold line can be checked, and never printed on.
            'flap_w'  => 88.9,
            // [observed] How far the ink stays inside the face. The owner
            // saw labels land a sixteenth of an inch high or low of each
            // other on an inkjet feeding this slick film (Phase 17), so the
            // margin is that and a little over: 1.75 mm of white before the
            // quiet zone even starts, top and bottom.
            'edge'    => 1.75,
            // [derived] Die plus flap is 7 in on an 8.5 in sheet; centred,
            // that leaves 0.75 in a side, which puts the LABEL left of centre
            // -- the "biased left" look of the real sheet.
            'origin_x' => 19.05,
            // One column: the pitch is never stepped. It is set to the
            // die-plus-flap width so fitsPage() measures the whole die.
            'pitch_x'  => 177.8,
            // [derived] Ten 1-1/32 in dies, contiguous, centred on 11 in.
            'origin_y' => 8.73,
            'pitch_y'  => 26.194,
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

    /** How many labels sit side by side; 1 means "row" is the whole address. */
    public static function columns(?string $sku): int
    {
        return self::STOCKS[self::orFallback($sku)]['columns'];
    }

    public static function perSheet(?string $sku): int
    {
        $stock = self::STOCKS[self::orFallback($sku)];
        return $stock['columns'] * $stock['rows'];
    }

    /**
     * The full geometry, in millimetres from the top-left corner of the page.
     *
     * `origin_x`/`origin_y` are the corner of the first DIE -- the laminate's
     * footprint on a self-laminating stock, the label itself on a plain one.
     * The printable face sits centred inside the die; `position()` is where
     * the face is, `diePosition()` where the die is.
     *
     * @return array{
     *   columns:int, rows:int, per_sheet:int,
     *   label_w:float, label_h:float, print_h:float,
     *   die_w:float, die_h:float, flap_w:float, edge:float,
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
            'die_w'     => $stock['die_w'],
            'die_h'     => $stock['die_h'],
            'flap_w'    => $stock['flap_w'],
            'edge'      => $stock['edge'],
            'origin_x'  => $stock['origin_x'],
            'origin_y'  => $stock['origin_y'],
            'pitch_x'   => $stock['pitch_x'],
            'pitch_y'   => $stock['pitch_y'],
        ];
    }

    /**
     * The top-left corner of the DIE of one label, 0-indexed across then
     * down: the laminate's footprint, or the label itself where there is no
     * laminate.
     *
     * @return array{0:float,1:float} x, y in millimetres
     */
    public static function diePosition(?string $sku, int $index): array
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
     * The top-left corner of the printable face of one label, 0-indexed
     * across then down. [derived] The face is centred on its die, which is
     * how a laminate that has to overlap the face evenly is cut.
     *
     * @return array{0:float,1:float} x, y in millimetres
     */
    public static function position(?string $sku, int $index): array
    {
        $g = self::geometry($sku);
        [$x, $y] = self::diePosition($sku, $index);

        return [
            $x + ($g['die_w'] - $g['label_w']) / 2,
            $y + ($g['die_h'] - $g['label_h']) / 2,
        ];
    }

    /**
     * Where the code goes on a label, relative to the face's top-left
     * corner: the whole symbol, quiet zone included, and what falls out of
     * that -- the module, and how far the ink stays from the face's edges.
     *
     * THE FACE DECIDES THE SIZE, NOT THE STAKE. Section 2.3 sized the symbol
     * to the 1 in stake less application slop (TagUrl::TAG_FACE_MM), which
     * is right for a label at least that tall; the 00757 face is 19.05 mm,
     * so there the symbol is the face less the feed margin each side, and
     * the module is what it is. Centred on the stake's width rather than
     * pushed to the left edge, so a label stuck on a little off-centre still
     * has the whole code on the front.
     *
     * The ink margin (`edge_x`, `edge_y`) is the number to read on the
     * registration sheet: it is the distance from the face's edge to the
     * first dark module, and it is what a feed that lands a label high or
     * low eats into. Under about 1.5 mm on a printer that drifts is a code
     * with its top row on the laminate.
     *
     * @param int $extent the symbol's modules across, quiet zone included
     *        (a version 3 symbol is 29 + 8, a version 4 is 33 + 8)
     * @return array{x:float,y:float,side:float,module:float,quiet:float,dark:float,edge_x:float,edge_y:float}
     */
    public static function symbolBox(?string $sku, int $extent): array
    {
        $g = self::geometry($sku);
        $extent = \max(1, $extent);

        $side = \min(TagUrl::TAG_FACE_MM, $g['print_h'] - 2 * $g['edge']);
        $side = \max(self::MIN_SYMBOL_MM, $side);

        $x = (\min($g['label_w'], self::STAKE_W) - $side) / 2;
        $y = ($g['print_h'] - $side) / 2;

        $module = $side / $extent;
        $quiet = TagUrl::QUIET_MODULES * $module;

        return [
            'x'      => \round($x, 3),
            'y'      => \round($y, 3),
            'side'   => \round($side, 3),
            'module' => \round($module, 3),
            'quiet'  => \round($quiet, 3),
            'dark'   => \round($side - 2 * $quiet, 3),
            'edge_x' => \round($x + $quiet, 3),
            'edge_y' => \round($y + $quiet, 3),
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
     * The same place as words: "row 3", or "row 3, column 2" on a stock that
     * has columns, with "page 2, " in front when the batch ran to a second
     * sheet. "Column 1" on a one-column sheet is noise, and the two screens
     * that print a place should not each decide that for themselves.
     */
    public static function placeText(?string $sku, int $ordinal): string
    {
        $place = self::place($sku, $ordinal);
        $text = ($place['sheet'] > 1 ? 'page ' . $place['sheet'] . ', ' : '') . 'row ' . $place['row'];
        if (self::columns($sku) > 1) {
            $text .= ', column ' . $place['column'];
        }
        return $text;
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
        // The die and the flap count: a flap off the edge of the paper is a
        // label that cannot be sealed.
        $right = $g['origin_x'] + ($g['columns'] - 1) * $g['pitch_x'] + $g['die_w'] + $g['flap_w'];
        $bottom = $g['origin_y'] + ($g['rows'] - 1) * $g['pitch_y'] + $g['die_h'];

        return $right <= self::PAGE_W + 0.001 && $bottom <= self::PAGE_H + 0.001;
    }
}
