<?php

declare(strict_types=1);

namespace Carl\Reports;

use Carl\Support\Units;

require_once (\defined('CARL_ROOT') ? \CARL_ROOT : \dirname(__DIR__, 3)) . '/vendor/fpdf/fpdf.php';

/**
 * The calendar, on paper (Phase 15).
 *
 * A month grid to pin up in the shed, with every worked-out date on it
 * written out in full underneath -- because a chip on the grid says
 * "Transplant" and the paper cannot be tapped to find out for which plant
 * and why. The screen answers that with a day panel; the sheet answers it
 * with the list.
 *
 * **It follows the field sheet's rules, not the report's.** The plant and
 * garden reports read the palette (`Reports\Document`) because they are
 * documents; this is a sheet that goes out to a shed wall and through
 * whatever printer is nearest, like the field sheet, and the design notes
 * are explicit about that class of page: black on white, no grey, one
 * hairline weight, because a 600 dpi mono laser halftones a grey rule into
 * a broken line and drops a light fill altogether. So logged and projected
 * entries are told apart by SHAPE -- a filled square against an open one --
 * which is the same rule the screen follows for a greyscale print (a fill
 * against a dashed border), and never by tone.
 *
 * **A4 and Letter from one page**, the way `FieldSheet` does it: drawn to
 * 210 mm wide (A4, the narrower) and everything kept above 270 mm (Letter's
 * 279 mm less a margin), so one file prints on either paper at actual size
 * with nothing off the edge.
 *
 * **The grid is one page and the list is as many as it needs.** AutoPageBreak
 * is off, as on the field sheet, because FPDF's own break trigger sits at A4's
 * foot and would put a line on Letter's floor. The list breaks itself, above
 * the Letter limit, and the running head and the page count come back on
 * every page so a second sheet found alone still says which month it is.
 *
 * **A cell that cannot hold its day says so.** Nine waterings collapse to one
 * line, as on the screen; a day with more lines than the cell has room for
 * draws what fits and then "+n more", and counts the cell, so a test can
 * prove the overflow is visible rather than printed off the cell's edge.
 */
final class CalendarSheet extends \FPDF
{
    private const MARGIN     = 12.0;
    private const WIDTH      = 186.0;   // 210 mm less both margins
    private const BOTTOM     = 270.0;   // Letter's 279 mm, less a margin
    private const HEAD_H     = 13.0;    // the running head, on every page
    private const GRID_FLOOR = 160.0;   // the grid ends here; the list has the rest
    private const ROW_MAX    = 24.0;    // a week is never taller than this
    private const DAYS_H     = 5.0;     // the Sun..Sat row
    private const NUM_H      = 4.2;     // the day number's line
    private const CHIP_H     = 3.4;     // one entry's line
    private const LIST_H     = 3.8;     // one line of the list
    private const COLUMN     = self::WIDTH / 7;

    /** The deepest the content reached on any page, in mm. */
    private float $deepest = 0.0;

    /** Cells that had more lines than room and said "+n more". */
    private int $overflowCells = 0;

    private bool $started = false;
    private string $monthName = '';
    private string $printedOn = '';
    private string $scope = '';

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(false);
        $this->AliasNbPages();
        $this->SetTitle('Carl calendar');
        $this->SetCreator('Carl The Garden Helper');
    }

    /**
     * Draw the month: the grid, its legend, and the list of what is coming.
     *
     * @param list<list<array{date:string,in_month:bool}>> $weeks  Calendar::grid()
     * @param array<string,list<array<string,mixed>>>      $byDate Calendar::byDate() over the grid's entries
     * @param list<array<string,mixed>>                    $coming the projected entries on the grid, in date order
     * @param string $today     the reader's local today, boxed if it is on the grid
     * @param string $printedOn the same date, as words
     * @param string $scope     what the page was filtered to, in words
     */
    public function month(
        string $monthName,
        array $weeks,
        array $byDate,
        array $coming,
        string $today,
        string $printedOn,
        string $scope,
    ): void {
        $this->monthName = $monthName;
        $this->printedOn = $printedOn;
        $this->scope = $scope;
        $this->started = true;

        $this->AddPage();
        $this->grid($weeks, $byDate, $today);
        $this->legend($weeks, $today);
        $this->comingUp($coming);
    }

    public function render(): string
    {
        return (string) $this->Output('S');
    }

    // -- What a test can read off it ----------------------------------------

    public function contentBottom(): float
    {
        return $this->deepest;
    }

    /** The lowest a page may draw and still print on Letter. */
    public function bottomLimit(): float
    {
        return self::BOTTOM;
    }

    public function overflowCount(): int
    {
        return $this->overflowCells;
    }

    public function pageCount(): int
    {
        return $this->PageNo();
    }

    // -- Page furniture -------------------------------------------------------

    /**
     * The running head, on every page: the month, who printed it and when,
     * and what it was filtered to. FPDF calls this from AddPage().
     */
    public function Header(): void   // phpcs:ignore -- FPDF's name, not ours
    {
        if (!$this->started) {
            return;
        }
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(self::MARGIN, self::MARGIN);
        $this->SetFont('Helvetica', 'B', 15);
        $this->Cell(110, 7, $this->t($this->monthName), 0, 0, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->Cell(self::WIDTH - 110, 7, $this->t('Printed ' . $this->printedOn), 0, 1, 'R');

        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(110, 4, $this->t('Carl - the garden helper'), 0, 0, 'L');
        $this->Cell(self::WIDTH - 110, 4, $this->t($this->fit($this->scope, self::WIDTH - 112)), 0, 1, 'R');

        $this->SetY(self::MARGIN + self::HEAD_H - 2);
        $this->hairline();
        $this->SetY(self::MARGIN + self::HEAD_H);
    }

    /**
     * Not the field sheet's private sheetFoot(): FPDF declares a public
     * Footer() and calls it at every page end, and it is the right hook here
     * because the list can run to several pages. Drawn at the Letter limit,
     * not at FPDF's own -14 mm, which is A4's foot and Letter's floor.
     */
    public function Footer(): void   // phpcs:ignore -- FPDF's name, not ours
    {
        if (!$this->started) {
            return;
        }
        $this->SetY(self::BOTTOM - 8);
        $this->hairline();
        $this->Ln(1.5);
        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(self::WIDTH / 2, 4, $this->t('Carl - ' . $this->monthName), 0, 0, 'L');
        $this->Cell(self::WIDTH / 2, 4, $this->t('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'R');
    }

    // -- The grid ---------------------------------------------------------------

    /**
     * @param list<list<array{date:string,in_month:bool}>> $weeks
     * @param array<string,list<array<string,mixed>>> $byDate
     */
    private function grid(array $weeks, array $byDate, string $today): void
    {
        $top = $this->GetY();

        // The day names, boxed like the cells under them.
        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(0, 0, 0);
        foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $name) {
            $this->Cell(self::COLUMN, self::DAYS_H, $this->t($name), 1, 0, 'C');
        }
        $this->Ln();

        // A week's height is what fits: six weeks share the room above the
        // floor, and five weeks are capped rather than stretched, because a
        // 30 mm cell with two lines in it is mostly paper.
        $rows = \max(1, \count($weeks));
        $rowH = \min(self::ROW_MAX, (self::GRID_FLOOR - $top - self::DAYS_H) / $rows);
        $capacity = \max(1, (int) \floor(($rowH - self::NUM_H - 1.0) / self::CHIP_H));

        $todayBox = null;
        foreach ($weeks as $r => $week) {
            $y = $top + self::DAYS_H + $r * $rowH;
            foreach ($week as $c => $cell) {
                $x = self::MARGIN + $c * self::COLUMN;
                $this->Rect($x, $y, self::COLUMN, $rowH);
                $this->dayNumber($x, $y, $cell);
                $this->chips($x, $y + self::NUM_H, $byDate[$cell['date']] ?? [], $capacity);
                if ($cell['date'] === $today) {
                    $todayBox = [$x, $y];
                }
            }
        }

        // Today, in a heavier line -- drawn last so no neighbour's hairline
        // sits on top of it. Weight, not tone: a grey fill is what the mono
        // laser drops.
        if ($todayBox !== null) {
            $this->SetLineWidth(0.7);
            $this->Rect($todayBox[0], $todayBox[1], self::COLUMN, $rowH);
            $this->SetLineWidth(0.2);
        }

        $bottom = $top + self::DAYS_H + $rows * $rowH;
        $this->deepest = \max($this->deepest, $bottom);
        $this->SetY($bottom + 2);
    }

    /** @param array{date:string,in_month:bool} $cell */
    private function dayNumber(float $x, float $y, array $cell): void
    {
        $day = (int) \substr($cell['date'], 8, 2);
        $this->SetXY($x + 1.2, $y + 0.6);
        if ($cell['in_month']) {
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->Cell(self::COLUMN - 2.4, 3.6, (string) $day, 0, 0, 'L');
            return;
        }
        // A day from the neighbouring month is named with its month rather
        // than greyed: "30 Aug" prints the same on every engine and a grey
        // "30" prints on none of them reliably.
        $this->SetFont('Helvetica', 'I', 7.5);
        $this->Cell(self::COLUMN - 2.4, 3.6, $this->t(Units::shortDate($cell['date']) === ''
            ? (string) $day
            : $day . ' ' . \date('M', (int) \strtotime($cell['date'] . ' 00:00:00 UTC'))), 0, 0, 'L');
    }

    /**
     * The entries in a cell, repeats collapsed to one line with a count, the
     * way the screen does it -- nine waterings on a Tuesday is one line
     * reading "Watered x9". Past the cell's capacity the last line says how
     * many more there were, so the paper never quietly loses a date.
     *
     * @param list<array<string,mixed>> $entries
     */
    private function chips(float $x, float $y, array $entries, int $capacity): void
    {
        if ($entries === []) {
            return;
        }
        $rolled = [];
        foreach ($entries as $entry) {
            $key = (string) $entry['kind'] . '|' . (string) $entry['label'];
            $rolled[$key] ??= ['entry' => $entry, 'count' => 0];
            $rolled[$key]['count']++;
        }

        $groups = \array_values($rolled);
        $shown = $groups;
        $more = 0;
        if (\count($groups) > $capacity) {
            $shown = \array_slice($groups, 0, \max(0, $capacity - 1));
            $more = \count($groups) - \count($shown);
            $this->overflowCells++;
        }

        $this->SetFont('Helvetica', '', 7);
        $line = 0;
        foreach ($shown as $group) {
            $entry = $group['entry'];
            $lineY = $y + $line * self::CHIP_H;
            // Filled for what happened, open for what is worked out: shape,
            // never tone, so it survives any printer and any eye.
            $this->Rect($x + 1.3, $lineY + 0.75, 1.9, 1.9, (bool) $entry['projected'] ? 'D' : 'F');
            $text = (string) $entry['label']
                . ($group['count'] > 1 ? ' ' . "\u{00D7}" . $group['count'] : '');
            $this->SetXY($x + 3.9, $lineY);
            $this->Cell(self::COLUMN - 4.6, self::CHIP_H, $this->t($this->fit($text, self::COLUMN - 5.2)), 0, 0, 'L');
            $line++;
        }
        if ($more > 0) {
            $this->SetFont('Helvetica', 'I', 7);
            $this->SetXY($x + 1.3, $y + $line * self::CHIP_H);
            $this->Cell(self::COLUMN - 2.6, self::CHIP_H, $this->t('+' . $more . ' more'), 0, 0, 'L');
        }
    }

    /**
     * @param list<list<array{date:string,in_month:bool}>> $weeks
     */
    private function legend(array $weeks, string $today): void
    {
        $y = $this->GetY();
        $this->SetFont('Helvetica', '', 7.5);

        $this->Rect(self::MARGIN + 0.3, $y + 1.0, 1.9, 1.9, 'F');
        $this->SetXY(self::MARGIN + 3, $y);
        $this->Cell(30, 4, $this->t('logged: it happened'), 0, 0, 'L');

        $x = self::MARGIN + 34;
        $this->Rect($x + 0.3, $y + 1.0, 1.9, 1.9, 'D');
        $this->SetXY($x + 3, $y);
        $this->Cell(self::WIDTH - 37, 4, $this->t(
            'ahead: worked out from days to maturity, a hardening duration, or your county\'s windows'
            . ($this->onGrid($weeks, $today) ? '. Today is boxed in a heavier line.' : '.')
        ), 0, 1, 'L');

        $this->deepest = \max($this->deepest, $this->GetY());
        $this->Ln(3);
    }

    /** @param list<list<array{date:string,in_month:bool}>> $weeks */
    private function onGrid(array $weeks, string $date): bool
    {
        foreach ($weeks as $week) {
            foreach ($week as $cell) {
                if ($cell['date'] === $date) {
                    return true;
                }
            }
        }
        return false;
    }

    // -- The list -----------------------------------------------------------------

    /**
     * Every worked-out date on the grid, in full: the day, what it is, and
     * why Carl thinks so. This is what the chips abbreviate and what the
     * paper cannot be tapped for.
     *
     * @param list<array<string,mixed>> $coming
     */
    private function comingUp(array $coming): void
    {
        $this->sectionHead();

        if ($coming === []) {
            $this->SetX(self::MARGIN);
            $this->SetFont('Helvetica', '', 9);
            $this->Cell(self::WIDTH, 5, $this->t('Nothing is worked out for the days on this grid.'), 0, 1, 'L');
            $this->deepest = \max($this->deepest, $this->GetY());
            return;
        }

        $dateW = 26.0;
        $textW = self::WIDTH - $dateW;

        foreach ($coming as $entry) {
            $this->SetFont('Helvetica', 'B', 8.5);
            $titleLines = $this->wrap((string) $entry['title'], $textW - 1);
            $this->SetFont('Helvetica', '', 8);
            $detailLines = $this->wrap((string) ($entry['detail'] ?? ''), $textW - 1);
            $needed = (\count($titleLines) + \count($detailLines)) * self::LIST_H + 2.2;

            // Break above the Letter limit, never at FPDF's own trigger.
            if ($this->GetY() + $needed > self::BOTTOM - 10) {
                $this->AddPage();
                $this->sectionHead(true);
            }

            $top = $this->GetY();
            $this->SetXY(self::MARGIN, $top + 0.8);
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->Cell($dateW, self::LIST_H, $this->t(Units::shortDate((string) $entry['date'])), 0, 0, 'L');

            $y = $top + 0.8;
            foreach ($titleLines as $line) {
                $this->SetXY(self::MARGIN + $dateW, $y);
                $this->Cell($textW, self::LIST_H, $this->t($line), 0, 0, 'L');
                $y += self::LIST_H;
            }
            $this->SetFont('Helvetica', '', 8);
            foreach ($detailLines as $line) {
                $this->SetXY(self::MARGIN + $dateW, $y);
                $this->Cell($textW, self::LIST_H, $this->t($line), 0, 0, 'L');
                $y += self::LIST_H;
            }

            $this->SetY($y + 1.2);
            $this->hairline();
            $this->deepest = \max($this->deepest, $this->GetY());
            $this->Ln(0.2);
        }
    }

    private function sectionHead(bool $continued = false): void
    {
        $this->SetX(self::MARGIN);
        $this->SetFont('Helvetica', 'B', 10.5);
        $this->Cell(30, 5.5, $this->t($continued ? 'Coming up, continued' : 'Coming up'), 0, 1, 'L');
        if (!$continued) {
            $this->SetX(self::MARGIN);
            $this->SetFont('Helvetica', '', 8);
            $this->MultiCell(self::WIDTH, 3.8, $this->t(
                'Every date on this grid that is worked out rather than logged, in full. '
                . 'Days to maturity is a guide, not a promise - go and look.'
            ), 0, 'L');
        }
        $this->Ln(1);
        $this->hairline();
        $this->Ln(0.5);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function hairline(): void
    {
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $y = $this->GetY();
        $this->Line(self::MARGIN, $y, self::MARGIN + self::WIDTH, $y);
    }

    /**
     * Greedy word wrap against the current font, so a row's height is known
     * before it is drawn and the page break can be decided above the Letter
     * limit rather than discovered below it. A word longer than the line is
     * cut rather than allowed to run into the margin.
     *
     * @return list<string>
     */
    private function wrap(string $text, float $width): array
    {
        $text = \trim(\preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }
        $lines = [];
        $current = '';
        foreach (\explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->GetStringWidth($this->t($candidate)) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $this->GetStringWidth($this->t($word)) <= $width ? $word : $this->fit($word, $width);
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /** Trim to what a cell can show, with an ellipsis. */
    private function fit(string $text, float $width): string
    {
        if ($this->GetStringWidth($this->t($text)) <= $width) {
            return $text;
        }
        while ($text !== '' && $this->GetStringWidth($this->t($text . '...')) > $width) {
            $text = \mb_substr($text, 0, \mb_strlen($text) - 1);
        }
        return \rtrim($text) . '...';
    }

    /**
     * FPDF's core fonts are Windows-1252 and Carl's text is UTF-8: the same
     * conversion the field sheet and the report make, for the same reason.
     */
    private function t(string $text): string
    {
        $converted = @\mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return \is_string($converted) ? $converted : $text;
    }
}
