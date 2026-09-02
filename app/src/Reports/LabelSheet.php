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
 * the module is 0.65 mm, so that matters; no GD, and therefore none of the
 * memory behaviour of Phase 5 handoff Section 2.1; and no temp file, on a host
 * where the writable directories are few and the deploy does not own them.
 *
 * FILL ONLY, NEVER STROKE. `SetLineWidth` is irrelevant with 'F', but a
 * stroked rectangle would bleed adjacent modules together, and that is the
 * obvious way to get this subtly wrong: the sheet looks right and nothing
 * scans.
 */
final class LabelSheet extends \FPDF
{
    /**
     * Millimetres of the tag face given up to application slop, each side.
     *
     * Section 2.3: the stake is 25.4 mm and ~0.7 mm a side is lost to a label
     * that is never applied perfectly straight, so the symbol is sized into
     * TagUrl::TAG_FACE_MM. On a label WIDER than the stake this is what keeps
     * the code inside the part that ends up on the plastic -- the 00757 label
     * is 88.9 mm wide and the stake it goes on is still 25.4.
     */
    private const SLOP = 0.7;

    /** The quiet zone is four modules, and it is not optional (ISO 18004 6.3). */
    private const QUIET = TagUrl::QUIET_MODULES;

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
     */
    public function registrationSheet(): void
    {
        $g = LabelStock::geometry($this->stock);
        $this->AddPage();

        // Where the instructions go. A stock whose labels start near the top
        // of the page and carry a clear flap beside them (00757) has no room
        // above the first label, so the words go INTO the flap column, which
        // on the real sheet is clear film and on this plain-paper copy is the
        // one wide space nothing else needs. Otherwise the top margin.
        $inFlap = $g['flap_w'] > 40.0 && $g['origin_y'] < 30.0;
        $textX = $inFlap ? $g['origin_x'] + $g['label_w'] + 2.0 : 12.0;
        $textY = $inFlap ? $g['origin_y'] + $g['label_h'] + 2.0 : 8.0;
        $textW = $inFlap ? $g['flap_w'] - 4.0 : 190.0;

        $this->SetFont('Helvetica', 'B', $inFlap ? 9 : 11);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($textX, $textY);
        $this->MultiCell($textW, 5, $this->t('Carl tag sheet - registration test for ' . LabelStock::name($this->stock)), 0, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->SetX($textX);
        $this->MultiCell($textW, 4, $this->t(
            'Print this on PLAIN PAPER at 100% scale, then hold it against a sheet of '
            . LabelStock::name($this->stock) . ' up to a window. Every outline should sit on a label. '
            . 'If they drift across the sheet the pitch is wrong; if they are all shifted the same way '
            . 'the margin is wrong. Check the 100 mm rule at the foot first: if that does not measure '
            . '100 mm, the print was scaled and nothing else on this page means anything.'
            . ($g['flap_w'] > 0.01
                ? ' The right-hand outline of each pair is the clear flap: it must sit on the film, '
                  . 'and the line between the two is the fold.'
                : '')
        ), 0, 'L');

        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->SetFont('Helvetica', '', 7);

        for ($i = 0; $i < $g['per_sheet']; $i++) {
            [$x, $y] = LabelStock::position($this->stock, $i);
            $this->Rect($x, $y, $g['label_w'], $g['label_h']);

            // The clear flap of a self-laminating label, BESIDE the label and
            // hinged on its right-hand edge (Phase 16; LabelStock says how
            // that was learned). It is drawn so the fold line can be checked
            // against the real sheet, and it is the part of the die nothing
            // may print on: a code in the flap is a code under the laminate's
            // adhesive, and it looks fine until the flap is folded.
            if ($g['flap_w'] > 0.01) {
                $this->SetLineWidth(0.1);
                $this->Rect($x + $g['label_w'], $y, $g['flap_w'], $g['label_h']);
                $this->SetLineWidth(0.2);
                if ($i === 0) {
                    $this->SetXY($x + $g['label_w'] + 2, $y + 2);
                    $this->Cell($g['flap_w'] - 4, 4, $this->t('fold <- clear flap, do not print here'), 0, 0);
                }
            }

            // The printable part of a label whose flap folds UP from below,
            // where a stock has one. Neither stock does now; kept because it
            // is the other way a self-laminating die can be cut.
            if ($g['print_h'] < $g['label_h'] - 0.01) {
                $this->SetLineWidth(0.1);
                $this->Line($x, $y + $g['print_h'], $x + $g['label_w'], $y + $g['print_h']);
                $this->SetLineWidth(0.2);
                $this->SetXY($x + 2, $y + $g['print_h'] + 1);
                $this->Cell(60, 4, $this->t('fold - clear flap, do not print here'), 0, 0);
            }

            $this->SetXY($x + 2, $y + 2);
            $this->Cell(20, 4, $this->t('#' . ($i + 1)), 0, 0);
        }

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
     * @param array{0:float,1:float} $at
     * @param array<string,mixed> $tag
     */
    private function label(array $at, array $tag, bool $named): void
    {
        $g = LabelStock::geometry($this->stock);
        [$x, $y] = $at;
        $code = (string) $tag['code'];

        $symbol = Encoder::encode($this->tagBase . $code, TagUrl::EC_LEVEL);

        // The symbol is sized to the STAKE, not to the label: 25.4 mm less the
        // application slop each side, and never taller than the printable area
        // allows. Section 2.3 sizes the whole physical design around this
        // number and a label that is wider than the stake must not grow it.
        $available = \min(TagUrl::TAG_FACE_MM, $g['print_h'] - 2 * self::SLOP);
        $side = \max(12.0, $available);

        $this->drawSymbol($symbol, $x + self::SLOP, $y + self::SLOP, $side);

        $textX = $x + self::SLOP + $side + 2.0;
        $textW = $g['label_w'] - ($textX - $x) - 2.0;

        $this->SetTextColor(...self::INK);

        // The six characters, large and monospaced. This is the recovery path
        // when the symbol is caked in soil -- and one will be -- so it is not
        // a caption, it is the other half of the tag (Section 2.4).
        $this->SetFont('Courier', 'B', 13);
        $this->SetXY($textX, $y + self::SLOP);
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
        // write along.
        $bandY = $y + $g['print_h'] - 5.0;
        if ($bandY > $y + self::SLOP + $side - 2.0) {
            $this->SetDrawColor(150, 150, 150);
            $this->SetLineWidth(0.15);
            $this->Line($textX, $bandY + 3.4, $x + $g['label_w'] - 2.0, $bandY + 3.4);
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
