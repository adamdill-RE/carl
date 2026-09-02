<?php

declare(strict_types=1);

namespace Carl\Reports;

use Carl\Support\Tokens;

/**
 * FPDF is not a Composer package and there is no autoloader for global
 * classes, so the vendored file is required here -- before the class below
 * can extend it (hosting Section 3).
 */
require_once (\defined('CARL_ROOT') ? \CARL_ROOT : \dirname(__DIR__, 3)) . '/vendor/fpdf/fpdf.php';

/**
 * The PDF a report downloads as (handoff Section 13.2).
 *
 * A thin layer over FPDF that knows four things FPDF does not:
 *
 *  1. **Encoding.** FPDF's core fonts are Windows-1252 and Carl's text is
 *     UTF-8. Every string goes through t() on its way in; without it a
 *     degree sign or a curly apostrophe -- both of which Carl writes --
 *     comes out as mojibake, silently and only in the PDF.
 *  2. **The palette.** Colours are read from tokens.css so the PDF follows
 *     the one-file palette swap of handoff Section 13.5 rather than becoming
 *     a second place a colour is named.
 *  3. **Page furniture.** A running header and a "page n of m" footer, which
 *     is what makes a printed report survive being put down.
 *  4. **Tables that break.** FPDF has no table; a report has an event log
 *     that is longer than a page, so the header row has to be re-drawn after
 *     a break or the second page is a wall of unlabelled columns.
 *
 * Memory is the constraint (Section 13.2: under 10 s and 64 MB on a 20-photo
 * report), and it is spent on images, not on this file. Every image arrives
 * as an already-downscaled JPEG string and is handed to FPDF from memory --
 * see embed() -- so nothing is written to disk: Section 13.2 says stream it
 * and keep nothing, and a temp file is something kept.
 */
final class Document extends \FPDF
{
    private const MARGIN = 15.0;
    private const WIDTH = 180.0;         // 210mm A4 less both margins
    private const CHART_MAX_HEIGHT = 82.0;   // two to a page; see chart()

    /** @var array{0:int,1:int,2:int} */
    private array $ink;
    /** @var array{0:int,1:int,2:int} */
    private array $muted;
    /** @var array{0:int,1:int,2:int} */
    private array $rule;
    /** @var array{0:int,1:int,2:int} */
    private array $brand;
    /** @var array{0:int,1:int,2:int} */
    private array $tint;

    private bool $started = false;

    /**
     * In-memory images by the name embed() gave them; see _parsejpg().
     *
     * @var array<string,string>
     */
    private array $blobs = [];

    public function __construct(
        private string $documentTitle,
        private string $documentSubtitle,
        private string $generatedOn,
        Tokens $tokens,
    ) {
        parent::__construct('P', 'mm', 'A4');

        $this->ink   = $tokens->rgb('--carl-text', [25, 29, 25]);
        $this->muted = $tokens->rgb('--carl-text-muted', [84, 90, 82]);
        $this->rule  = $tokens->rgb('--carl-border', [211, 209, 197]);
        $this->brand = $tokens->rgb('--carl-primary', [38, 92, 55]);
        $this->tint  = $tokens->rgb('--carl-surface-sunk', [233, 232, 224]);

        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(true, 18);
        $this->AliasNbPages();
        $this->SetCreator('Carl The Garden Helper');
        $this->SetTitle($this->t($documentTitle));
        $this->SetCompression(true);
    }

    // -- Page furniture ---------------------------------------------------

    public function Header(): void   // phpcs:ignore -- FPDF's name, not ours
    {
        if (!$this->started) {
            return;
        }
        $this->SetFont('Helvetica', 'B', 9);
        $this->colour($this->brand);
        $this->Cell(120, 6, $this->t($this->documentTitle), 0, 0, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->colour($this->muted);
        $this->Cell(60, 6, $this->t('Carl - ' . $this->generatedOn), 0, 1, 'R');

        $this->hairline();
        $this->Ln(2);
    }

    public function Footer(): void   // phpcs:ignore -- FPDF's name, not ours
    {
        if (!$this->started) {
            return;
        }
        $this->SetY(-14);
        $this->SetFont('Helvetica', '', 8);
        $this->colour($this->muted);
        $this->Cell(0, 6, $this->t('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
    }

    /** Start the document. Nothing draws before this. */
    public function open(): void
    {
        $this->started = true;
        $this->AddPage();

        $this->SetFont('Helvetica', 'B', 17);
        $this->colour($this->ink);
        $this->MultiCell(self::WIDTH, 8, $this->t($this->documentTitle), 0, 'L');

        if ($this->documentSubtitle !== '') {
            $this->SetFont('Helvetica', '', 10);
            $this->colour($this->muted);
            $this->MultiCell(self::WIDTH, 5, $this->t($this->documentSubtitle), 0, 'L');
        }
        $this->Ln(3);
    }

    // -- Blocks -----------------------------------------------------------

    public function heading(string $text): void
    {
        $this->breakIfShorterThan(24);
        $this->Ln(3);
        $this->SetFont('Helvetica', 'B', 12);
        $this->colour($this->ink);
        $this->Cell(self::WIDTH, 7, $this->t($text), 0, 1, 'L');
        $this->hairline();
        $this->Ln(1.5);
    }

    public function paragraph(string $text, int $size = 10): void
    {
        if (\trim($text) === '') {
            return;
        }
        $this->SetFont('Helvetica', '', $size);
        $this->colour($this->ink);
        $this->MultiCell(self::WIDTH, $size <= 8 ? 4 : 4.8, $this->t($text), 0, 'L');
        $this->Ln(1);
    }

    public function note(string $text): void
    {
        if (\trim($text) === '') {
            return;
        }
        $this->SetFont('Helvetica', 'I', 8.5);
        $this->colour($this->muted);
        $this->MultiCell(self::WIDTH, 4, $this->t($text), 0, 'L');
        $this->Ln(1);
    }

    /**
     * A label/value block. The label column is fixed so several of these read
     * as one table even when they are separate calls.
     *
     * @param array<string,string|null> $pairs
     */
    public function facts(array $pairs): void
    {
        $labelWidth = 52.0;
        foreach ($pairs as $label => $value) {
            $value = (string) ($value ?? '');
            if (\trim($value) === '') {
                continue;
            }
            $this->breakIfShorterThan(12);

            $top = $this->GetY();
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->colour($this->muted);
            $this->Cell($labelWidth, 5.5, $this->t((string) $label), 0, 0, 'L');

            $this->SetFont('Helvetica', '', 9.5);
            $this->colour($this->ink);
            $this->SetX(self::MARGIN + $labelWidth);
            $this->MultiCell(self::WIDTH - $labelWidth, 5.5, $this->t($value), 0, 'L');

            // MultiCell leaves y under the last wrapped line; a one-line value
            // must still advance by one line.
            if ($this->GetY() < $top + 5.5) {
                $this->SetY($top + 5.5);
            }
        }
        $this->Ln(1);
    }

    /**
     * A real table, with the header row repeated after every page break.
     *
     * @param list<string> $headers
     * @param list<float> $widths in mm, summing to WIDTH
     * @param iterable<list<string|null>> $rows
     * @param list<string> $align one of L, C, R per column
     */
    public function table(array $headers, array $widths, iterable $rows, array $align = []): void
    {
        $drawHead = function () use ($headers, $widths, $align): void {
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->colour($this->ink);
            $this->SetFillColor($this->tint[0], $this->tint[1], $this->tint[2]);
            foreach ($headers as $i => $header) {
                $this->Cell($widths[$i], 6, $this->t($header), 0, 0, $align[$i] ?? 'L', true);
            }
            $this->Ln();
        };

        $drawHead();
        $this->SetFont('Helvetica', '', 8.5);

        foreach ($rows as $row) {
            if ($this->GetY() > $this->PageBreakTrigger - 8) {
                $this->AddPage();
                $drawHead();
                $this->SetFont('Helvetica', '', 8.5);
            }

            $height = 5.0;
            $this->colour($this->ink);
            foreach ($row as $i => $cell) {
                $width = $widths[$i] ?? 20.0;
                $this->Cell(
                    $width,
                    $height,
                    $this->t($this->fit((string) ($cell ?? ''), $width - 2)),
                    'B',
                    0,
                    $align[$i] ?? 'L'
                );
            }
            $this->Ln();
        }
        $this->Ln(2);
    }

    /**
     * A chart, never taller than CHART_MAX_HEIGHT and always in proportion.
     *
     * The cap is not cosmetic. A chart canvas is shaped by the phone it was
     * drawn on -- 326x240 CSS pixels at 380px, so about 3:4 -- and printed at
     * the full 180mm column that is 132mm tall, which puts exactly one chart
     * on a page and leaves two thirds of each one blank. Measured by printing
     * one. Capping the height and centring what is left fits two to a page
     * and shrinks the chart's own axis labels back to a sensible size.
     *
     * The caption sits under it, because a chart that has broken onto the
     * next page without its caption is a picture of nothing.
     */
    public function chart(string $jpeg, string $caption = ''): void
    {
        $size = @\getimagesizefromstring($jpeg);
        if ($size === false || (int) $size[0] <= 0) {
            return;
        }

        $width = self::WIDTH;
        $height = $width * ((int) $size[1] / (int) $size[0]);
        if ($height > self::CHART_MAX_HEIGHT) {
            $width *= self::CHART_MAX_HEIGHT / $height;
            $height = self::CHART_MAX_HEIGHT;
        }
        $x = self::MARGIN + (self::WIDTH - $width) / 2;

        $this->breakIfShorterThan($height + 12);
        $this->embed($jpeg, $x, null, $width, $height);

        if ($caption !== '') {
            $this->Ln(1);
            $this->note($caption);
        }
        $this->Ln(3);
    }

    /**
     * Photographs, three across. Each is a JPEG string the caller has already
     * downscaled -- this never opens a file and never decodes anything.
     *
     * Laid out a row at a time: a row's height is its tallest photo, so a
     * portrait shot beside two landscape ones does not overlap the captions
     * under them. Working photo-by-photo and nudging y is how that bug gets
     * written.
     *
     * @param list<array{jpeg:string,caption:string}> $photos
     */
    public function photoGrid(array $photos): void
    {
        $columns = 3;
        $gap = 4.0;
        $cell = (self::WIDTH - $gap * ($columns - 1)) / $columns;
        $captionHeight = 4.5;

        foreach (\array_chunk($photos, $columns) as $row) {
            $drawable = [];
            $tallest = 0.0;

            foreach ($row as $photo) {
                $size = @\getimagesizefromstring($photo['jpeg']);
                if ($size === false || (int) $size[0] <= 0) {
                    continue;
                }
                $height = $cell * ((int) $size[1] / (int) $size[0]);
                $tallest = \max($tallest, $height);
                $drawable[] = $photo + ['height' => $height];
            }
            if ($drawable === []) {
                continue;
            }

            $this->breakIfShorterThan($tallest + $captionHeight + 4);
            $top = $this->GetY();

            foreach ($drawable as $i => $photo) {
                $x = self::MARGIN + $i * ($cell + $gap);
                $this->embed($photo['jpeg'], $x, $top, $cell, $photo['height']);

                if ($photo['caption'] !== '') {
                    $this->SetXY($x, $top + $tallest + 0.5);
                    $this->SetFont('Helvetica', '', 7);
                    $this->colour($this->muted);
                    $this->Cell($cell, 3.5, $this->t($this->fit($photo['caption'], $cell - 1)), 0, 0, 'L');
                }
            }

            $this->SetXY(self::MARGIN, $top + $tallest + $captionHeight + 2);
        }
        $this->Ln(1);
    }

    /** The finished document as bytes. Nothing is written to disk. */
    public function render(): string
    {
        if (!$this->started) {
            $this->open();
        }
        return $this->Output('S');
    }

    // -- Internals --------------------------------------------------------

    /**
     * Hand FPDF an in-memory JPEG.
     *
     * FPDF::Image() takes a path. Until Phase 14 that path was a data:// URL
     * carrying the bytes, which read as the obvious way to keep the Section
     * 13.2 promise that a report streams and keeps nothing. It is also the
     * reason every report with a chart or a photograph in it was a 500 on the
     * live site while the field sheet and the label sheets -- no images --
     * were fine: data:// is a URL wrapper, and PHP refuses URL wrappers when
     * `allow_url_fopen` is off, which on a cPanel host it commonly is. Both
     * getimagesize() and file_get_contents() then return false, FPDF throws
     * "Missing or incorrect image file", and the message it throws carries
     * the whole base64 body into the error log. Locally, and in CI, the
     * setting is on and nothing ever noticed.
     *
     * So the bytes are parked here under a name FPDF has never heard of, and
     * _parsejpg() below answers from memory when asked for that name. No
     * wrapper, no file, no setting to depend on. The name is a hash of the
     * bytes because FPDF caches by name: a different name per call would
     * embed the same photograph twice, and the same name for different
     * bytes would print the first photograph twenty times.
     */
    private function embed(string $jpeg, float $x, ?float $y, float $w, float $h): void
    {
        $key = 'carl-image-' . \sha1($jpeg) . '.jpg';
        $this->blobs[$key] = $jpeg;
        try {
            $this->Image($key, $x, $y, $w, $h, 'JPG');
        } finally {
            // FPDF keeps its own copy of the bytes in $this->images; this
            // one would be a second copy of every photograph for the life
            // of the document.
            unset($this->blobs[$key]);
        }
    }

    /**
     * FPDF's JPEG reader, answering from memory for a name embed() parked.
     *
     * The body is FPDF 1.86's own _parsejpg() with getimagesize() and
     * file_get_contents() replaced by their string forms. No type
     * declarations on the parameter, because the parent has none and PHP
     * will not let an override narrow one. Anything not parked falls
     * through to the parent, so a real path still works.
     *
     * @param mixed $file
     * @return array<string,mixed>
     */
    protected function _parsejpg($file)   // phpcs:ignore -- FPDF's name, not ours
    {
        if (!\is_string($file) || !isset($this->blobs[$file])) {
            return parent::_parsejpg($file);
        }
        $bytes = $this->blobs[$file];

        $a = @\getimagesizefromstring($bytes);
        if ($a === false) {
            $this->Error('Missing or incorrect image: ' . $file);
        }
        if ((int) $a[2] !== \IMAGETYPE_JPEG) {
            $this->Error('Not a JPEG image: ' . $file);
        }
        if (!isset($a['channels']) || (int) $a['channels'] === 3) {
            $colspace = 'DeviceRGB';
        } elseif ((int) $a['channels'] === 4) {
            $colspace = 'DeviceCMYK';
        } else {
            $colspace = 'DeviceGray';
        }

        return [
            'w'    => (int) $a[0],
            'h'    => (int) $a[1],
            'cs'   => $colspace,
            'bpc'  => isset($a['bits']) ? (int) $a['bits'] : 8,
            'f'    => 'DCTDecode',
            'data' => $bytes,
        ];
    }

    /** A horizontal rule in the border colour. */
    private function hairline(): void
    {
        $this->SetDrawColor($this->rule[0], $this->rule[1], $this->rule[2]);
        $y = $this->GetY();
        $this->Line(self::MARGIN, $y, self::MARGIN + self::WIDTH, $y);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function colour(array $rgb): void
    {
        $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    private function breakIfShorterThan(float $needed): void
    {
        if ($this->GetY() + $needed > $this->PageBreakTrigger) {
            $this->AddPage();
        }
    }

    /**
     * Truncate to fit a cell. FPDF's Cell does not clip -- an over-long value
     * simply prints over the next column, which looks like a rendering bug
     * rather than like a long value.
     */
    private function fit(string $text, float $width): string
    {
        $text = \str_replace(["\r", "\n"], ' ', $text);
        if ($this->GetStringWidth($this->t($text)) <= $width) {
            return $text;
        }
        $ellipsis = "\u{2026}";
        while ($text !== '' && $this->GetStringWidth($this->t($text . $ellipsis)) > $width) {
            $text = \mb_substr($text, 0, \mb_strlen($text) - 1);
        }
        return \rtrim($text) . $ellipsis;
    }

    /**
     * UTF-8 in, Windows-1252 out -- what FPDF's core fonts actually speak.
     *
     * mb_convert_encoding, not iconv: hosting Section 4 lists mbstring as
     * present on this host and does not list iconv at all, and a PDF route
     * that works locally and fatals on the server is exactly the class of bug
     * that section exists to prevent.
     *
     * Subscripts have no Windows-1252 equivalent and would become '?', so the
     * handful Carl writes are spelled out first. Everything else Carl emits --
     * the degree sign, curly quotes, en and em dashes, the ellipsis -- is in
     * cp1252 and survives.
     */
    private function t(string $text): string
    {
        $text = \strtr($text, [
            "\u{2080}" => '0', "\u{2081}" => '1', "\u{2082}" => '2',
            "\u{2083}" => '3', "\u{2084}" => '4',
            "\u{00A0}" => ' ',
        ]);
        $converted = @\mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return \is_string($converted) ? $converted : $text;
    }
}
