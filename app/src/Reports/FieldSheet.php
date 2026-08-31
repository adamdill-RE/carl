<?php

declare(strict_types=1);

namespace Carl\Reports;

require_once (\defined('CARL_ROOT') ? \CARL_ROOT : \dirname(__DIR__, 3)) . '/vendor/fpdf/fpdf.php';

/**
 * The field-recording sheet (handoff Section 13.4), blank or prefilled for
 * one garden.
 *
 * A paper page to take out to the beds and write on, then transcribe. It has
 * blocked two phases waiting on a layout; the layout was designed in Phase 6
 * and this is it in FPDF.
 *
 * **One generator, not a checked-in file.** Section 13.4 says "delivered as a
 * static PDF in `public/assets/field-sheet.pdf`". That was written in Phase 1,
 * before there was any PDF layer; Phase 4 built one, and it is needed here
 * anyway because the same section asks for a per-garden prefilled version
 * "using the same layout". Two artefacts from one layout means one of them
 * goes stale silently -- the binary in the repository, which nothing tests
 * and nobody looks at. So the blank sheet is this class with no garden, the
 * prefilled sheet is this class with one, and there is exactly one layout to
 * keep right. The deviation is deliberate and is recorded in `deploy.md`.
 *
 * **A4 and Letter from one page.** Drawn to 210 mm wide (A4, the narrower)
 * and bounded at 270 mm tall (Letter's 279 mm less a margin, Letter being the
 * shorter). Inside both, so one file prints correctly on either without the
 * "shrink to fit" that would take the writing lines below the ~7 mm a pen
 * needs.
 *
 * **Black on white, and no grey.** A dotted or 60%-grey rule is a sub-pixel
 * mark: a 600 dpi mono engine halftones it into a broken line or drops it
 * altogether. Every rule here is a solid black hairline, and the row heading
 * band is a solid bar with the text knocked out in white -- which is also the
 * only fill on the page, because a large flood fill drinks toner.
 */
final class FieldSheet extends \FPDF
{
    private const MARGIN      = 12.0;
    private const WIDTH       = 186.0;   // 210 mm less both margins
    private const BOTTOM      = 270.0;   // Letter's 279 mm, less a margin
    private const ROW_H       = 7.0;     // ~7 mm: what a ballpoint needs
    private const LINE_H      = 6.0;

    /**
     * Millimetres kept back below the round for the detail block and the
     * footer, on a prefilled sheet.
     *
     * AutoPageBreak is off, so a row that does not fit is not pushed to a
     * second page -- it is drawn past the bottom of the paper and simply does
     * not print, taking the footer with it. This reserve is what stops that,
     * and `truncated` is what stops the loss being silent.
     */
    private const RESERVE = 76.0;

    /** The round's columns, in millimetres, summing to WIDTH. */
    private const COLUMNS = [
        'plant' => 79.0, 'wat' => 9.0, 'fert' => 9.0, 'mul' => 9.0,
        'pest' => 9.0, 'pick' => 9.0, 'qty' => 12.0, 'min' => 11.0, 'note' => 39.0,
    ];

    /**
     * The subset of the event vocabulary a person ticks standing at a bed.
     *
     * Not all of `Carl\Domain\EventType`: the ones left off are either set on
     * the plant form (seed_started, direct_sown, transplanted_in,
     * hardening_schedule_set) or recorded by the app itself (photo_added).
     * Printing twenty-one boxes to make the sheet complete would make it
     * unusable, which is a worse kind of incomplete.
     */
    private const TICKS = [
        'Watered', 'Fertilised', 'Mulched', 'Amended', 'Pest seen',
        'Pest treated', 'Yield', 'Germinated', 'Failed to germ.', 'Died',
        'Culled', 'Up-potted', 'Transplanted', 'Hardening started', 'Moved',
    ];

    /** The garden_event vocabulary of migration 007. */
    private const GARDEN_TICKS = [
        'Watered', 'Fertilised', 'Amended', 'Mulched', 'Pest seen',
        'Pest treated', 'Note',
    ];

    /**
     * The deepest the content reached, in mm.
     *
     * There is no rasteriser on the build machine, so "does it fit on one
     * page" cannot be answered by looking. This is what makes it answerable
     * by a test instead: the sheet is one page with AutoPageBreak off, so
     * content past BOTTOM does not spill onto page two -- it is silently
     * printed off the bottom of the paper, taking the line that says where to
     * transcribe it with it. That is exactly the failure a second pass over
     * the design found, and it must not come back through the PDF.
     */
    private float $deepest = 0.0;

    /** How many living plants did not fit on the sheet. */
    private int $truncated = 0;

    public function truncatedCount(): int
    {
        return $this->truncated;
    }

    public function contentBottom(): float
    {
        return $this->deepest;
    }

    /** The lowest a sheet may draw and still print on Letter. */
    public function bottomLimit(): float
    {
        return self::BOTTOM;
    }

    /**
     * @param string $basePath the app's own base path, for the "transcribe
     *        at ..." line. No default: 01_core_test.php asserts the literal
     *        appears in exactly one committed file, and a convenience default
     *        here would be a second place the deployment path is written down
     *        (hosting Section 5.2).
     */
    public function __construct(private string $basePath)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(false);   // every sheet is exactly one page
        $this->SetTitle('Carl field sheet');
        $this->SetCreator('Carl The Garden Helper');
    }

    /**
     * The blank plant sheet: a round at the top, detail blocks under it.
     *
     * @param list<array{row:?string,label:string}> $living rows and plants to
     *        print into the round, for the per-garden version. Empty gives
     *        the blank sheet.
     */
    public function plantSheet(
        ?string $gardenName = null,
        array $living = [],
        ?string $printedOn = null,
    ): void {
        $this->AddPage();
        $this->sheetHead('Field sheet', $gardenName, $printedOn);

        if ($living === []) {
            $this->intro('One line per plant. Anything needing a sentence goes in the blocks below.');
            $this->roundTable(8);
            $this->detailBlocks(2);
        } else {
            $this->intro('Everything living in this garden when it was printed, in row order. '
                . 'Cross out what has gone; write anything new at the bottom.');
            $this->roundTable(0, $living);
            $this->detailBlocks(1);
        }

        $this->sheetFoot('Transcribe at ' . $this->basePath . 'log',
            $gardenName === null
                ? 'Enter the date you wrote, not the date you type.'
                : $gardenName . ' - ' . \count(\array_filter(
                    $living, static fn (array $l): bool => $l['row'] === null
                )) . ' living plants when printed'
                    . ($this->truncated > 0 ? ', ' . $this->truncated . ' not shown' : ''));
    }

    /** The blank garden-actions sheet. */
    public function gardenSheet(?string $gardenName = null, ?string $printedOn = null): void
    {
        $this->AddPage();
        $this->sheetHead('Garden actions', $gardenName, $printedOn);
        $this->intro('An action against the whole garden, or one zone of it. Watering a zone '
            . 'fans out to every living plant in its rows when you transcribe it.');

        for ($i = 0; $i < 4; $i++) {
            $this->gardenBlock();
        }

        $this->sheetFoot('Transcribe at ' . $this->basePath . 'gardens -> Garden Actions',
            "Zone watering reaches every living plant in the zone's rows.");
    }

    public function render(): string
    {
        return (string) $this->Output('S');
    }

    // -- Pieces -------------------------------------------------------------

    private function sheetHead(string $title, ?string $gardenName, ?string $printedOn): void
    {
        $this->SetXY(self::MARGIN, self::MARGIN);
        $this->SetFont('Helvetica', 'B', 16);
        $this->Cell(56, 7, $this->t($title), 0, 0);

        // The garden is written in when the sheet is blank and printed when
        // it is not: the same slot either way, so one sheet is not a
        // different shape from the other.
        $x = self::MARGIN + 56;
        $this->fieldBox($x, self::MARGIN, 62, 'Garden', $gardenName);
        $this->fieldBox($x + 64, self::MARGIN, 30, 'Date', null);
        $this->fieldBox($x + 96, self::MARGIN, 30, 'Printed', $printedOn);

        $this->SetXY(self::MARGIN, self::MARGIN + 7);
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(56, 4, $this->t('Carl - the garden helper'), 0, 0);

        $this->SetY(self::MARGIN + 13);
        $this->hairline();
        $this->Ln(2);
    }

    /** A labelled slot: printed value, or a rule to write one on. */
    private function fieldBox(float $x, float $y, float $w, string $label, ?string $value): void
    {
        $this->SetXY($x, $y);
        $this->SetFont('Helvetica', '', 6.5);
        $this->Cell($w, 3, $this->t(\strtoupper($label)), 0, 2);
        $this->SetFont('Helvetica', $value === null ? '' : 'B', $value === null ? 9 : 10);
        $this->Cell($w, 5.5, $value === null ? '' : $this->t($this->fit($value, $w)), 'B', 0);
    }

    private function intro(string $text): void
    {
        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', '', 9);
        $this->MultiCell(self::WIDTH, 4.2, $this->t($text), 0, 'L');
        $this->Ln(1.5);
    }

    /**
     * The round: a tick grid, either blank rows or the garden's own plants.
     *
     * @param list<array{row:?string,label:string}> $living
     */
    private function roundTable(int $blankRows, array $living = []): void
    {
        $headers = ['Plant', 'Wat', 'Fert', 'Mul', 'Pest', 'Pick', 'Qty', 'Min', 'Short note'];
        $widths = \array_values(self::COLUMNS);

        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', 'B', 7.5);
        foreach ($headers as $i => $header) {
            $this->Cell($widths[$i], 5, $this->t($header), 1, 0,
                $i === 0 || $i === 8 ? 'L' : 'C');
        }
        $this->Ln();

        $this->SetFont('Helvetica', '', 8.5);
        $printed = 0;
        foreach ($living as $line) {
            if ($this->GetY() + self::ROW_H > self::BOTTOM - self::RESERVE) {
                // A sheet that quietly stopped listing plants would be read
                // as the whole bed, which is the same failure as a summary
                // that does not say it is one.
                $this->truncated = \count(\array_filter(
                    $living, static fn (array $l): bool => $l['row'] === null
                )) - $printed;
                break;
            }
            if ($line['row'] !== null) {
                // A solid bar with the text knocked out: unmistakable when
                // scanning for "Row 2", and it prints the same on every
                // engine, which a light grey fill does not.
                $this->SetFillColor(0, 0, 0);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Helvetica', 'B', 7.5);
                $this->SetX(self::MARGIN);
                $this->Cell(self::WIDTH, 5, ' ' . $this->t(\strtoupper($line['row'])), 1, 1, 'L', true);
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('Helvetica', '', 8.5);
                continue;
            }
            $this->SetX(self::MARGIN);
            $printed++;
            $this->Cell($widths[0], self::ROW_H, ' ' . $this->t($this->fit($line['label'], $widths[0] - 2)), 1, 0);
            for ($c = 1; $c < 9; $c++) {
                $this->Cell($widths[$c], self::ROW_H, '', 1, 0);
            }
            $this->Ln();
        }

        if ($this->truncated > 0) {
            $this->SetX(self::MARGIN);
            $this->SetFont('Helvetica', 'B', 8);
            $this->Cell(self::WIDTH, 5, $this->t(
                $this->truncated . ' more living plant'
                . ($this->truncated === 1 ? '' : 's')
                . ' did not fit on this page - print a second sheet for the rest.'
            ), 1, 1, 'L');
        }

        for ($r = 0; $r < $blankRows; $r++) {
            $this->SetX(self::MARGIN);
            foreach ($widths as $w) {
                $this->Cell($w, self::ROW_H, '', 1, 0);
            }
            $this->Ln();
        }
        $this->Ln(2.5);
    }

    private function detailBlocks(int $count): void
    {
        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', 'B', 10);
        $this->Cell(30, 5, $this->t('In detail'), 0, 0);
        $this->SetFont('Helvetica', '', 8.5);
        $this->Cell(self::WIDTH - 30, 5,
            $this->t('Every field here is a field on Log Plant Activity.'), 0, 1);
        $this->Ln(0.5);

        for ($i = 0; $i < $count; $i++) {
            $this->detailBlock();
        }
    }

    private function detailBlock(): void
    {
        $top = $this->GetY();
        $inner = self::MARGIN + 3;
        $innerW = self::WIDTH - 6;

        $this->SetXY($inner, $top + 3);
        $this->fieldBox($inner, $top + 3, $innerW - 76, 'Plant', null);
        $this->fieldBox($inner + $innerW - 74, $top + 3, 24, 'Date', null);
        $this->fieldBox($inner + $innerW - 48, $top + 3, 22, 'Qty', null);
        $this->fieldBox($inner + $innerW - 24, $top + 3, 24, 'Photos', null);

        // The vocabulary, five to a line.
        $y = $top + 13;
        $colW = $innerW / 5;
        $this->SetFont('Helvetica', '', 7.5);
        foreach (self::TICKS as $i => $tick) {
            $x = $inner + ($i % 5) * $colW;
            $rowY = $y + \intdiv($i, 5) * 4.6;
            $this->Rect($x, $rowY + 0.6, 2.6, 2.6);
            $this->SetXY($x + 3.4, $rowY);
            $this->Cell($colW - 3.4, 3.8, $this->t($tick), 0, 0);
        }

        $y += 4.6 * (int) \ceil(\count(self::TICKS) / 5) + 1;
        $this->fieldBox($inner, $y, $innerW - 60, 'Which one - fertiliser, mulch, treatment, soil', null);
        $this->fieldBox($inner + $innerW - 58, $y, 26, 'Minutes', null);
        $this->fieldBox($inner + $innerW - 30, $y, 30, 'Weight + unit', null);

        $y += 10;
        $this->SetXY($inner, $y);
        $this->SetFont('Helvetica', '', 6.5);
        $this->Cell($innerW, 3, $this->t('NOTES'), 0, 2);
        $this->Cell($innerW, self::LINE_H, '', 'B', 2);
        $this->Cell($innerW, self::LINE_H, '', 'B', 2);

        $bottom = $this->GetY() + 2;
        $this->Rect(self::MARGIN, $top, self::WIDTH, $bottom - $top);
        $this->deepest = \max($this->deepest, $bottom);
        $this->SetY($bottom + 2.5);
    }

    private function gardenBlock(): void
    {
        $top = $this->GetY();
        $inner = self::MARGIN + 3;
        $innerW = self::WIDTH - 6;

        $this->fieldBox($inner, $top + 3, 30, 'Date', null);
        $this->fieldBox($inner + 32, $top + 3, $innerW - 62, 'Zone', null);
        $this->fieldBox($inner + $innerW - 28, $top + 3, 28, 'Minutes', null);

        $y = $top + 13;
        $colW = $innerW / 7;
        $this->SetFont('Helvetica', '', 8);
        foreach (self::GARDEN_TICKS as $i => $tick) {
            $x = $inner + $i * $colW;
            $this->Rect($x, $y + 0.6, 2.8, 2.8);
            $this->SetXY($x + 3.6, $y);
            $this->Cell($colW - 3.6, 4, $this->t($tick), 0, 0);
        }

        $y += 6;
        $this->SetXY($inner, $y);
        $this->SetFont('Helvetica', '', 6.5);
        $this->Cell($innerW, 3, $this->t('ROWS - TICK OR NAME THE ONES THIS COVERS'), 0, 2);
        $slotW = $innerW / 6;
        $slotY = $this->GetY();
        for ($i = 0; $i < 6; $i++) {
            $x = $inner + $i * $slotW;
            $this->Rect($x, $slotY + 0.8, 2.8, 2.8);
            $this->SetXY($x + 3.6, $slotY);
            $this->Cell($slotW - 4.6, 5, '', 'B', 0);
        }

        $y = $slotY + 7;
        $this->SetXY($inner, $y);
        $this->SetFont('Helvetica', '', 6.5);
        $this->Cell($innerW, 3, $this->t('WHICH ONE, AND ANYTHING WORTH REMEMBERING'), 0, 2);
        $this->Cell($innerW, self::LINE_H, '', 'B', 2);

        $bottom = $this->GetY() + 2;
        $this->Rect(self::MARGIN, $top, self::WIDTH, $bottom - $top);
        $this->deepest = \max($this->deepest, $bottom);
        $this->SetY($bottom + 3);
    }

    /**
     * Not `footer()`: FPDF declares a public `Footer()` that it calls on
     * every page break, and PHP method names are case-insensitive -- so a
     * private `footer()` here is a fatal "access level must be public" at
     * class-load time, not a runtime surprise.
     */
    private function sheetFoot(string $left, string $right): void
    {
        $this->deepest = \max($this->deepest, $this->GetY());
        $this->SetY(self::BOTTOM - 8);
        $this->hairline();
        $this->Ln(1.5);
        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(self::WIDTH / 2, 4, $this->t($left), 0, 0, 'L');
        $this->Cell(self::WIDTH / 2, 4, $this->t($right), 0, 0, 'R');
    }

    private function hairline(): void
    {
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $y = $this->GetY();
        $this->Line(self::MARGIN, $y, self::MARGIN + self::WIDTH, $y);
    }

    /** Trim to what the column can actually show, with an ellipsis. */
    private function fit(string $text, float $width): string
    {
        $text = $this->t($text);
        if ($this->GetStringWidth($text) <= $width) {
            return $text;
        }
        while ($text !== '' && $this->GetStringWidth($text . '...') > $width) {
            $text = \substr($text, 0, -1);
        }
        return $text . '...';
    }

    /**
     * FPDF's core fonts are Windows-1252 and Carl's text is UTF-8; the same
     * conversion `Reports\Document` does, and for the same reason. A garden
     * called "Ada's bed" comes out as mojibake without it.
     */
    private function t(string $text): string
    {
        $converted = @\mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return \is_string($converted) ? $converted : $text;
    }
}
