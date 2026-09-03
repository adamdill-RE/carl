<?php

declare(strict_types=1);

namespace Carl\Reports;

use Carl\Domain\LabelStock;
use Carl\Qr\Encoder;
use Carl\Qr\Symbol;
use Carl\Qr\TagUrl;

require_once (\defined('CARL_ROOT') ? \CARL_ROOT : \dirname(__DIR__, 3)) . '/vendor/fpdf/fpdf.php';

/**
 * A sheet of QR plant tags (docs/QR-TAGS-SPEC.md Section 5).
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SIBLING OF `Document` AND NOT A SUBCLASS
 * ---------------------------------------------------------------------------
 *
 * `Document` is hard-coded to A4 -- `parent::__construct('P','mm','A4')` with
 * MARGIN = 15.0 and WIDTH = 180.0 as private constants derived from it -- and
 * **every Avery template is US Letter**. A4 is 210 x 297 mm; Letter is
 * 215.9 x 279.4. Render a Letter template onto A4 and every column sits ~3 mm
 * off horizontally while the sheet runs 17.6 mm short vertically, so the
 * bottom row falls off the page. NOTHING ERRORS. You find out when you hold
 * the print against a real label sheet (Section 5.5).
 *
 * Four things this class does that `Document` cannot be talked into:
 *
 *  1. `'Letter'` -- FPDF 1.86 has it in StdPageSizes, so no patching.
 *  2. `SetMargins(0, 0, 0)`. Labels are absolutely positioned from the
 *     template's own origin, not laid out in a text flow.
 *  3. `SetAutoPageBreak(false)`. With it on, a label near the foot of the
 *     sheet silently throws itself onto a second page -- and a second page of
 *     labels prints on the blank side of nothing.
 *  4. Empty Header() and Footer(). `Document` draws a running header and
 *     "page n of m"; on a label sheet those print across the labels.
 *
 * Do not try to parameterise `Document`'s page size: MARGIN and WIDTH are
 * consts used in twenty places and every one of them assumes a text column.
 *
 * ---------------------------------------------------------------------------
 * THE CODE IS DRAWN AS RECTANGLES, NOT AS AN IMAGE
 * ---------------------------------------------------------------------------
 *
 * Each module run becomes one `Rect(..., 'F')` (Section 4.3), which buys three
 * things: vector output that is exact at whatever DPI the printer has -- and
 * the module is well under a millimetre, so that matters; no GD, and
 * therefore none of the memory behaviour of Phase 5 handoff Section 2.1; and
 * no temp file, on a host where the writable directories are few and the
 * deploy does not own them.
 *
 * FILL ONLY, NEVER STROKE. `SetLineWidth` is irrelevant with 'F', but a
 * stroked rectangle would bleed adjacent modules together, and that is the
 * obvious way to get this subtly wrong: the sheet looks right and nothing
 * scans.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE CODE SITS IS THE STOCK'S DECISION, NOT THIS CLASS'S
 * ---------------------------------------------------------------------------
 *
 * `LabelStock::symbolBox()` says how big the symbol is and where on the face
 * it goes, from the face's own height and a per-stock margin for the
 * printer's feed (Phase 17: the 00757 face is 19 mm tall, not 26, and a code
 * sized to 26 ran off it). This class draws what it is told and puts the
 * text in whatever is left to the right. The registration sheet draws the
 * same box as an outline, so the owner can see where the ink will land
 * before any lands.
 */
final class LabelSheet extends \FPDF
{
    /** The quiet zone is four modules, and it is not optional (ISO 18004 6.3). */
    private const QUIET = TagUrl::QUIET_MODULES;

    /**
     * A real six-character code, for measuring: the symbol version, and so
     * the module, depend on the length of what is encoded, and every tag is
     * this long.
     */
    private const SAMPLE_CODE = 'AB7K4M';

    private const INK = [0, 0, 0];
    private const PAPER = [255, 255, 255];

    /**
     * @param string $tagBase the tag URL up to but not including the code,
     *        already in the case it will be encoded in. Carl\Qr\TagUrl owns
     *        that decision and its docblock is why it is not obvious: the
     *        mount segment of the path is a real directory, so an uppercase
     *        URL is only safe where the server will answer one.
     */
    public function __construct(private string $stock, private string $tagBase)
    {
        parent::__construct('P', 'mm', 'Letter');

        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false);
        $this->SetCreator('Carl The Garden Helper');
        $this->SetTitle($this->t('Carl plant tags'));
        $this->SetCompression(true);
    }

    // FPDF's names, not ours. Both empty: see the class docblock.
    public function Header(): void   // phpcs:ignore
    {
    }

    public function Footer(): void   // phpcs:ignore
    {
    }

    /**
     * A sheet of blank tags: the code, the six characters, one line saying
     * what this is, and a band to write on (Section 5.9).
     *
     * @param list<array<string,mixed>> $tags rows carrying at least `code`
     */
    public function blankSheets(array $tags): void
    {
        $this->sheetsOf($tags, false);
    }

    /**
     * Named labels for tags that are already on a plant: the SAME code -- this
     * is a reprint, not a new tag -- plus the plant name, variety and start
     * date, applied over or beside the blank one.
     *
     * @param list<array<string,mixed>> $tags
     * @param int $startAt the label position to begin at, so a part-used sheet
     *        is not wasted. Section 5.1: this control belongs HERE and not on
     *        the blank-sheet path, because blank sheets are always printed
     *        whole and named ones never are.
     */
    public function namedLabels(array $tags, int $startAt = 0): void
    {
        $perSheet = LabelStock::perSheet($this->stock);
        $startAt = \max(0, \min($startAt, $perSheet - 1));

        $this->sheetsOf(\array_merge(\array_fill(0, $startAt, null), $tags), true);
    }

    /**
     * The registration test sheet: the same geometry as outlines and numbers,
     * for plain paper (Section 5.6).
     *
     * THIS IS THE ACCEPTANCE TEST FOR `LabelStock`'s CONSTANTS, and it is not
     * optional the first time a stock is used. Hold it against a real label
     * sheet up to a window before committing a sheet of polyester. Half of
     * those constants are derived rather than published -- LabelStock says
     * which -- and this is what turns a derivation into a measurement.
     *
     * Three outlines per label where the stock has a laminate (Phase 17):
     * the heavy one is the printable face and must sit on the white; the
     * thin one round it is the laminate's footprint, the die the sheet is
     * cut to; the thin one to the right is the flap, with the fold between.
     * And inside every face, a small square where the code's ink will land,
     * so "does the code clear the edge" is answered by this sheet and not by
     * the first sheet of film.
     */
    public function registrationSheet(): void
    {
        $g = LabelStock::geometry($this->stock);
        $this->AddPage();

        $bordered = $g['die_w'] > $g['label_w'] + 0.01 || $g['die_h'] > $g['label_h'] + 0.01;
        $box = $this->symbolBox();

        // Where the instructions go. A stock whose labels start near the top
        // of the page and carry a clear flap beside them (00757) has no room
        // above the first label, so the words go INTO the flap column, which
        // on the real sheet is clear film and on this plain-paper copy is the
        // one wide space nothing else needs. Otherwise the top margin.
        $inFlap = $g['flap_w'] > 40.0 && $g['origin_y'] < 30.0;
        $textX = $inFlap ? $g['origin_x'] + $g['die_w'] + 2.0 : 12.0;
        $textY = $inFlap ? $g['origin_y'] + $g['die_h'] + 2.0 : 8.0;
        $textW = $inFlap ? $g['flap_w'] - 4.0 : 190.0;

        $instructions = $this->t(
            'Print this on PLAIN PAPER at 100% scale, then hold it against a sheet of '
            . LabelStock::name($this->stock) . ' up to a window. Check the 100 mm rule at the foot '
            . 'first: if that does not measure 100 mm, the print was scaled and nothing else on this '
            . 'page means anything.'
            . ($bordered
                ? ' The HEAVY outline in each row is the printable face ('
                  . self::inches($g['label_h']) . ' x ' . self::inches($g['label_w'])
                  . ' in) and must sit on the white label. The thin outline round it is the '
                  . 'clear laminate\'s footprint (' . self::inches($g['die_h']) . ' x '
                  . self::inches($g['die_w']) . ' in), which is what the sheet is cut to.'
                : ' Every outline should sit on a label.')
            . ($g['flap_w'] > 0.01
                ? ' The outline to the right is the clear flap: it must sit on the film, and the '
                  . 'line between the two is the fold.'
                : '')
            . ' The small square inside each face is where the code\'s ink lands: it should have '
            . 'white all round it, ' . \number_format($box['edge_y'], 1) . ' mm above and below.'
            . ' If the outlines drift down the sheet the row pitch (' . \number_format($g['pitch_y'], 2)
            . ' mm) is wrong; if they all sit high or low the top margin ('
            . \number_format($g['origin_y'], 2) . ' mm) is; if they sit left or right of the labels '
            . 'the side margin (' . \number_format($g['origin_x'], 2) . ' mm) is. Each is one number '
            . 'in LabelStock.'
        );

        $this->SetDrawColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 7);

        for ($i = 0; $i < $g['per_sheet']; $i++) {
            [$x, $y] = LabelStock::position($this->stock, $i);
            [$dx, $dy] = LabelStock::diePosition($this->stock, $i);

            // The die: the laminate's footprint, drawn thin so the face's
            // heavy outline reads as the thing to line up.
            if ($bordered) {
                $this->SetLineWidth(0.1);
                $this->Rect($dx, $dy, $g['die_w'], $g['die_h']);
            }

            // The printable face.
            $this->SetLineWidth(0.3);
            $this->Rect($x, $y, $g['label_w'], $g['label_h']);

            // The clear flap of a self-laminating label, BESIDE the die and
            // hinged on its right-hand edge (Phase 16; LabelStock says how
            // that was learned). It is drawn so the fold line can be checked
            // against the real sheet, and it is the part of the die nothing
            // may print on: a code in the flap is a code under the laminate's
            // adhesive, and it looks fine until the flap is folded.
            if ($g['flap_w'] > 0.01) {
                $this->SetLineWidth(0.1);
                $this->Rect($dx + $g['die_w'], $dy, $g['flap_w'], $g['die_h']);
                if ($i === 0) {
                    $this->SetXY($dx + $g['die_w'] + 2, $dy + 2);
                    $this->Cell($g['flap_w'] - 4, 4, $this->t('fold <- clear flap, do not print here'), 0, 0);
                }
            }

            // The printable part of a label whose flap folds UP from below,
            // where a stock has one. Neither stock does now; kept because it
            // is the other way a self-laminating die can be cut.
            if ($g['print_h'] < $g['label_h'] - 0.01) {
                $this->SetLineWidth(0.1);
                $this->Line($x, $y + $g['print_h'], $x + $g['label_w'], $y + $g['print_h']);
                $this->SetXY($x + 2, $y + $g['print_h'] + 1);
                $this->Cell(60, 4, $this->t('fold - clear flap, do not print here'), 0, 0);
            }

            // Where the ink lands: the dark modules, quiet zone excluded,
            // because the quiet zone is white and the edge that matters is
            // the first black one.
            $this->SetLineWidth(0.15);
            $this->Rect($x + $box['edge_x'], $y + $box['edge_y'], $box['dark'], $box['dark']);

            // The label's number, beside the code where the text goes.
            $this->SetXY($x + $box['x'] + $box['side'] + 2.0, $y + $g['edge']);
            $this->Cell(20, 4, $this->t('#' . ($i + 1) . ($i === 0 ? '  square = code' : '')), 0, 0);
        }

        // The words last, on a white panel, so that where they share the
        // flap column with the outlines (00757) they cover a few flaps rather
        // than print across them: the first flap keeps its label, the rest
        // are the same rectangle ten times.
        if ($inFlap) {
            $this->SetFillColor(...self::PAPER);
            $this->Rect($textX - 1.0, $textY - 1.0, $textW + 2.0, 62.0, 'F');
        }
        $this->SetFont('Helvetica', 'B', $inFlap ? 9 : 11);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($textX, $textY);
        $this->MultiCell($textW, 5, $this->t('Carl tag sheet - registration test for ' . LabelStock::name($this->stock)), 0, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->SetX($textX);
        $this->MultiCell($textW, 4, $instructions, 0, 'L');

        $this->calibrationRule();
    }

    public function render(): string
    {
        return (string) $this->Output('S');
    }

    // -- Pieces -----------------------------------------------------------

    /**
     * @param list<array<string,mixed>|null> $tags a null is a skipped position
     */
    private function sheetsOf(array $tags, bool $named): void
    {
        $perSheet = LabelStock::perSheet($this->stock);
        $index = 0;

        foreach ($tags as $tag) {
            if ($index % $perSheet === 0) {
                $this->AddPage();
                $this->calibrationRule();
            }
            if ($tag !== null) {
                $this->label(LabelStock::position($this->stock, $index % $perSheet), $tag, $named);
            }
            $index++;
        }
    }

    /**
     * One label: the symbol on the left, the human-readable code under it,
     * and whatever text the label carries to the right.
     *
     * @param array{0:float,1:float} $at the printable face's top-left corner
     * @param array<string,mixed> $tag
     */
    private function label(array $at, array $tag, bool $named): void
    {
        $g = LabelStock::geometry($this->stock);
        [$x, $y] = $at;
        $code = (string) $tag['code'];
        $edge = $g['edge'];

        $symbol = Encoder::encode($this->tagBase . $code, TagUrl::EC_LEVEL);

        // The symbol is sized by the stock: the stake face less slop where
        // the label is tall enough for that, the face less the feed margin
        // where it is not (LabelStock::symbolBox). A label wider than the
        // stake must not grow it, and a label shorter than the stake must
        // shrink it.
        $box = LabelStock::symbolBox($this->stock, $symbol->size() + 2 * self::QUIET);
        $this->drawSymbol($symbol, $x + $box['x'], $y + $box['y'], $box['side']);

        // The text starts past the stake's width, so it wraps round the
        // side of the stake rather than crowding the code on its face.
        $textX = $x + \max($box['x'] + $box['side'] + 2.0, LabelStock::STAKE_W + 1.0);
        $inset = \max($edge, 2.0);
        $textW = $g['label_w'] - ($textX - $x) - $inset;

        $this->SetTextColor(...self::INK);

        // The six characters, large and monospaced. This is the recovery path
        // when the symbol is caked in soil -- and one will be -- so it is not
        // a caption, it is the other half of the tag (Section 2.4).
        $this->SetFont('Courier', 'B', 13);
        $this->SetXY($textX, $y + $edge);
        $this->Cell($textW, 5.5, $this->t($code), 0, 2, 'L');

        if ($named) {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetX($textX);
            $this->Cell($textW, 4, $this->t(self::plantName($tag)), 0, 2, 'L');

            $this->SetFont('Helvetica', '', 7);
            $this->SetX($textX);
            $this->Cell($textW, 3.5, $this->t(self::startedLine($tag)), 0, 2, 'L');
            return;
        }

        // A stranger who finds a stake in a garden should be able to tell what
        // it is. One line, and it is the only branding on the label.
        $this->SetFont('Helvetica', '', 6);
        $this->SetX($textX);
        $this->Cell($textW, 3, $this->t('Carl plant tag - scan me'), 0, 2, 'L');

        // The write-on band. On seed-starting day you want to scrawl "Cherokee
        // Purple" with a garden marker before Carl has ever heard of the plant
        // (Section 1.4), so a blank tag leaves room for a pen and a rule to
        // write along -- inside the feed margin, like everything else on the
        // face, and only where the face has room for it under the text.
        $lineY = $y + $g['print_h'] - \max($edge, 1.6);
        if ($lineY > $y + $edge + 8.5 + 1.5) {
            $this->SetDrawColor(150, 150, 150);
            $this->SetLineWidth(0.15);
            $this->Line($textX, $lineY, $x + $g['label_w'] - $inset, $lineY);
            $this->SetDrawColor(0, 0, 0);
        }
    }

    /**
     * The symbol, as merged horizontal runs of filled rectangles.
     *
     * A version 3 code is 841 modules; one rectangle each is 841 calls per
     * label and 20,184 on a sheet of 24. Symbol::runs() merges each row first,
     * which brings it to 150-250 (Section 4.3).
     */
    private function drawSymbol(Symbol $symbol, float $x, float $y, float $side): void
    {
        $extent = $symbol->size() + 2 * self::QUIET;
        $module = $side / $extent;

        // The quiet zone drawn as paper, not left to the label stock. On white
        // polyester it is invisible; over a named label being applied on top of
        // an older print it is the difference between scanning and not.
        $this->SetFillColor(...self::PAPER);
        $this->Rect($x, $y, $side, $side, 'F');

        $this->SetFillColor(...self::INK);
        foreach ($symbol->runs() as $run) {
            $this->Rect(
                $x + ($run['col'] + self::QUIET) * $module,
                $y + ($run['row'] + self::QUIET) * $module,
                $run['length'] * $module,
                $module,
                'F'
            );
        }
    }

    /**
     * The symbol's box for a real code on this stock: what `label()` draws
     * and what the registration sheet outlines, from one encoding.
     *
     * @return array{x:float,y:float,side:float,module:float,quiet:float,dark:float,edge_x:float,edge_y:float}
     */
    private function symbolBox(): array
    {
        $symbol = Encoder::encode($this->tagBase . self::SAMPLE_CODE, TagUrl::EC_LEVEL);
        return LabelStock::symbolBox($this->stock, $symbol->size() + 2 * self::QUIET);
    }

    /**
     * A 100 mm rule across the foot of every sheet.
     *
     * Scaled printing is the single most likely cause of a batch that will not
     * scan (Section 5.7): Chrome's print preview defaults to "fit to printable
     * area", which shrinks the page a few per cent -- enough to both
     * misregister every label and take the module below the size Section 2.3
     * sized it for. The print screen says so in words; this is the backstop
     * that catches it after the fact.
     */
    private function calibrationRule(): void
    {
        $y = LabelStock::PAGE_H - 8.0;
        $x = (LabelStock::PAGE_W - 100.0) / 2;

        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->Line($x, $y, $x + 100.0, $y);
        for ($i = 0; $i <= 10; $i++) {
            $tick = $i % 5 === 0 ? 2.0 : 1.2;
            $this->Line($x + $i * 10.0, $y - $tick, $x + $i * 10.0, $y + $tick);
        }

        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y + 1.5);
        $this->Cell(100.0, 4, $this->t('This line must measure 100 mm. Print at 100% scale, never "fit to page".'), 0, 0, 'C');
    }

    /** Millimetres as the inches a label box prints on: 19.05 -> "3/4", 26.194 -> "1-1/32". */
    private static function inches(float $mm): string
    {
        $inches = $mm / 25.4;
        $whole = (int) \floor($inches + 1e-6);
        $rest = $inches - $whole;
        // Thirty-seconds are as fine as any Avery size goes.
        $n = (int) \round($rest * 32);
        if ($n === 32) {
            $whole++;
            $n = 0;
        }
        $d = 32;
        while ($n > 0 && $n % 2 === 0) {
            $n = \intdiv($n, 2);
            $d = \intdiv($d, 2);
        }
        if ($n === 0) {
            return (string) $whole;
        }
        return ($whole > 0 ? $whole . '-' : '') . $n . '/' . $d;
    }

    /** @param array<string,mixed> $tag */
    private static function plantName(array $tag): string
    {
        $label = \trim((string) ($tag['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        return \trim(((string) ($tag['category'] ?? '')) . ' ' . ((string) ($tag['type'] ?? '')));
    }

    /** @param array<string,mixed> $tag */
    private static function startedLine(array $tag): string
    {
        $category = \trim((string) ($tag['category'] ?? '') . ' ' . (string) ($tag['type'] ?? ''));
        $started = (string) ($tag['start_date'] ?? '');
        $parts = \array_filter([
            $category === '' ? '' : $category,
            $started === '' ? '' : 'started ' . $started,
        ]);
        return \implode('  -  ', $parts);
    }

    /**
     * FPDF's core fonts are Windows-1252 and Carl's text is UTF-8.
     *
     * Every string printed by FPDF goes through this (Section 9, rule 8). A
     * curly apostrophe in a variety name -- and a nursery will write one -- is
     * silent mojibake without it, visible only in the PDF.
     */
    private function t(string $text): string
    {
        $converted = @\mb_convert_encoding(\str_replace("\u{00A0}", ' ', $text), 'Windows-1252', 'UTF-8');
        return \is_string($converted) ? $converted : $text;
    }
}
