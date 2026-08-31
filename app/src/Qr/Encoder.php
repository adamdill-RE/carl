<?php

declare(strict_types=1);

namespace Carl\Qr;

use InvalidArgumentException;

/**
 * A QR encoder, deliberately a small fraction of ISO 18004
 * (docs/QR-TAGS-SPEC.md Section 4.1).
 *
 * **Why hand-rolled.** Section 4 forbids calling a QR-image web service --
 * it would put a third-party call on a request path (PHASE-3-HANDOFF.md
 * Section 5) and hand every plant URL in the account to a stranger. Vendoring
 * is not available either: every maintained PHP QR library is a Composer
 * package with a PSR-4 tree and this project has no Composer
 * (hosting Section 3). The SMTP client in Carl\Mail was written for the same
 * reason.
 *
 * **What is in scope, and what that buys.**
 *
 *  - Alphanumeric mode, with a byte-mode fallback. No numeric mode (a URL is
 *    never all digits) and no Kanji.
 *  - Error correction levels M and Q only. Q is what a tag prints at
 *    (Section 2.2); M exists because the size ladder needs a rung.
 *  - Versions 1-4 only. That is the whole reason this file is ~500 lines and
 *    not ~2000: no version-information block (versions 7+), one alignment
 *    pattern instead of a lattice, and -- the part that matters most -- at M
 *    and Q every one of the four versions has a SINGLE block group, so the
 *    interleave has no ragged second group to handle.
 *
 * A 44-character uppercase URL fits version 3 at level Q with three
 * characters to spare, which is the encoding the whole physical tag is sized
 * around (Section 2.3). Version 4 is the headroom.
 *
 * **How it is tested.** There is no PHP decoder to round-trip against, so
 * `21_tags_test.php` asserts the module matrix bit for bit against fixtures
 * captured from an independent implementation (segno) -- the honest test an
 * external oracle allows. tests/fixtures/qr/README.md carries the command
 * that regenerates them.
 */
final class Encoder
{
    public const EC_M = 'M';
    public const EC_Q = 'Q';

    public const MODE_ALNUM = 'alphanumeric';
    public const MODE_BYTE  = 'byte';

    /** The alphanumeric table of ISO 18004 Table 5; the index IS the value. */
    private const ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * Block structure per version and level: [ec codewords per block,
     * number of blocks, data codewords per block].
     *
     * A general encoder needs two groups here, because from version 5 the
     * blocks in one symbol are not all the same size. **Versions 1-4 at M and
     * Q are all single-group**, which is asserted by arithmetic rather than
     * assumed: blocks x (data + ec) is the version's total codeword count,
     * 26 / 44 / 70 / 100. adding support for a fifth version means checking
     * that again, and adding a second group if it fails.
     *
     * @var array<int,array<string,array{0:int,1:int,2:int}>>
     */
    private const BLOCKS = [
        1 => [self::EC_M => [10, 1, 16], self::EC_Q => [13, 1, 13]],
        2 => [self::EC_M => [16, 1, 28], self::EC_Q => [22, 1, 22]],
        3 => [self::EC_M => [26, 1, 44], self::EC_Q => [18, 2, 17]],
        4 => [self::EC_M => [18, 2, 32], self::EC_Q => [26, 2, 24]],
    ];

    /**
     * Remainder bits appended after the interleaved codewords (Table 1).
     * Zero for version 1; seven for versions 2-6.
     */
    private const REMAINDER_BITS = [1 => 0, 2 => 7, 3 => 7, 4 => 7];

    /**
     * Alignment pattern centre coordinates (Table E.1).
     *
     * Versions 2-6 have exactly one row and one column of centres, so the
     * only pattern that survives the finder-overlap rule is the middle one.
     * An empty list for version 1, which has none.
     *
     * @var array<int,list<int>>
     */
    private const ALIGNMENT = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26]];

    /** The two-bit level indicator that goes into the format information. */
    private const EC_INDICATOR = [self::EC_M => 0b00, self::EC_Q => 0b11];

    /**
     * Encode a payload, choosing the smallest version that fits.
     *
     * @param string $payload  the exact bytes to encode; case matters, and
     *                         uppercase is what keeps a URL in alphanumeric
     *                         mode (Section 2.2)
     * @param string $ecLevel  self::EC_M or self::EC_Q
     * @param int    $minVersion floor, so a caller can pin a physical size
     *
     * @throws InvalidArgumentException when the payload does not fit version 4
     */
    public static function encode(string $payload, string $ecLevel = self::EC_Q, int $minVersion = 1): Symbol
    {
        if (!isset(self::EC_INDICATOR[$ecLevel])) {
            throw new InvalidArgumentException('Unsupported error correction level: ' . $ecLevel);
        }
        if ($payload === '') {
            throw new InvalidArgumentException('Nothing to encode.');
        }

        $mode = self::isAlphanumeric($payload) ? self::MODE_ALNUM : self::MODE_BYTE;
        $version = self::chooseVersion($payload, $mode, $ecLevel, \max(1, $minVersion));

        $codewords = self::codewords($payload, $mode, $version, $ecLevel);
        $final = self::interleave($codewords, $version, $ecLevel);

        return self::plot($final, $version, $ecLevel, $mode);
    }

    /** Does every byte have an alphanumeric-mode value? */
    public static function isAlphanumeric(string $payload): bool
    {
        $length = \strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            if (\strpos(self::ALNUM, $payload[$i]) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * How many characters of the given mode fit a version at a level.
     *
     * Derived from the block table rather than transcribed from a capacity
     * table, so the two cannot disagree: total data bits, less the mode
     * indicator and the character count indicator, divided by what a
     * character costs. An alphanumeric pair is 11 bits and a lone trailing
     * character is 6, which is why the alphanumeric arm is not a plain
     * division.
     */
    public static function capacity(int $version, string $ecLevel, string $mode): int
    {
        [$ecPerBlock, $blocks, $dataPerBlock] = self::BLOCKS[$version][$ecLevel];
        unset($ecPerBlock);

        $bits = $blocks * $dataPerBlock * 8 - 4 - self::countBits($mode);

        if ($mode === self::MODE_BYTE) {
            return \intdiv($bits, 8);
        }

        $pairs = \intdiv($bits, 11);
        return $pairs * 2 + (($bits - $pairs * 11) >= 6 ? 1 : 0);
    }

    /** The character count indicator width, for versions 1-9 (Table 3). */
    private static function countBits(string $mode): int
    {
        return $mode === self::MODE_BYTE ? 8 : 9;
    }

    private static function chooseVersion(string $payload, string $mode, string $ecLevel, int $minVersion): int
    {
        $length = \strlen($payload);
        foreach (\array_keys(self::BLOCKS) as $version) {
            if ($version >= $minVersion && $length <= self::capacity($version, $ecLevel, $mode)) {
                return $version;
            }
        }
        throw new InvalidArgumentException(
            'Payload of ' . $length . ' characters does not fit a version 4 symbol at level '
            . $ecLevel . '. This encoder stops at version 4 by design (Section 4.1); a longer '
            . 'URL wants a shorter domain, not a bigger symbol.'
        );
    }

    // -- Bitstream --------------------------------------------------------

    /**
     * The data codewords for a payload: header, data, terminator, padding.
     *
     * @return list<int> exactly blocks x dataPerBlock bytes
     */
    private static function codewords(string $payload, string $mode, int $version, string $ecLevel): array
    {
        [, $blocks, $dataPerBlock] = self::BLOCKS[$version][$ecLevel];
        $capacityBits = $blocks * $dataPerBlock * 8;

        $bits = new BitWriter();
        $bits->write($mode === self::MODE_BYTE ? 0b0100 : 0b0010, 4);
        $bits->write(\strlen($payload), self::countBits($mode));

        if ($mode === self::MODE_BYTE) {
            $length = \strlen($payload);
            for ($i = 0; $i < $length; $i++) {
                $bits->write(\ord($payload[$i]), 8);
            }
        } else {
            $length = \strlen($payload);
            for ($i = 0; $i + 1 < $length; $i += 2) {
                $bits->write(
                    (int) \strpos(self::ALNUM, $payload[$i]) * 45
                    + (int) \strpos(self::ALNUM, $payload[$i + 1]),
                    11
                );
            }
            if (($length % 2) === 1) {
                $bits->write((int) \strpos(self::ALNUM, $payload[$length - 1]), 6);
            }
        }

        // Terminator: up to four zero bits, fewer if the stream is nearly
        // full. Then zeros to the next byte boundary.
        $bits->write(0, \min(4, $capacityBits - $bits->length()));
        $bits->pad();

        $data = $bits->bytes();
        // 0xEC 0x11 alternating -- the pad codewords of Section 7.4.10. They
        // are not arbitrary: a decoder that reaches them knows the message is
        // over, and they are chosen to keep the module distribution even.
        $pad = [0xEC, 0x11];
        $i = 0;
        while (\count($data) < $blocks * $dataPerBlock) {
            $data[] = $pad[$i % 2];
            $i++;
        }

        return $data;
    }

    /**
     * Split into blocks, compute each block's error correction, and
     * interleave both.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function interleave(array $data, int $version, string $ecLevel): array
    {
        [$ecPerBlock, $blocks, $dataPerBlock] = self::BLOCKS[$version][$ecLevel];

        $dataBlocks = [];
        $ecBlocks = [];
        for ($b = 0; $b < $blocks; $b++) {
            $block = \array_slice($data, $b * $dataPerBlock, $dataPerBlock);
            $dataBlocks[] = $block;
            $ecBlocks[] = Galois::remainder($block, $ecPerBlock);
        }

        $out = [];
        for ($i = 0; $i < $dataPerBlock; $i++) {
            foreach ($dataBlocks as $block) {
                $out[] = $block[$i];
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $out[] = $block[$i];
            }
        }

        return $out;
    }

    // -- The matrix -------------------------------------------------------

    /**
     * Lay the function patterns, place the data, mask, and stamp the format.
     *
     * @param list<int> $codewords
     */
    private static function plot(array $codewords, int $version, string $ecLevel, string $mode): Symbol
    {
        $size = 17 + 4 * $version;

        /** @var list<list<bool>> $m */
        $m = \array_fill(0, $size, \array_fill(0, $size, false));
        /** @var list<list<bool>> $fixed true where a function pattern lives */
        $fixed = \array_fill(0, $size, \array_fill(0, $size, false));

        self::finders($m, $fixed, $size);
        self::alignment($m, $fixed, $version, $size);
        self::timing($m, $fixed, $size);

        self::reserveFormat($fixed, $size);

        // The dark module is RESERVED here and written after the mask is
        // chosen, not before. Reserved, so the data placement steps over it;
        // written late, because it belongs to the format information and the
        // format information is not what the mask is scored against (see
        // chooseMask).
        $darkRow = 4 * $version + 9;
        $fixed[$darkRow][8] = true;

        self::placeData($m, $fixed, $codewords, $version, $size);

        $mask = self::chooseMask($m, $fixed, $size);
        self::applyMask($m, $fixed, $size, $mask);

        $m[$darkRow][8] = true;
        self::writeFormat($m, $size, $ecLevel, $mask);

        return new Symbol($m, $version, $ecLevel, $mode, $mask);
    }

    /**
     * @param list<list<bool>> $m
     * @param list<list<bool>> $fixed
     */
    private static function finders(array &$m, array &$fixed, int $size): void
    {
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            // The 7x7 finder, plus the one-module separator all round it.
            // Walking -1..7 draws both in one loop: the ring at -1 and 7 is
            // the separator, and is light by construction.
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $row = $top + $r;
                    $col = $left + $c;
                    if ($row < 0 || $row >= $size || $col < 0 || $col >= $size) {
                        continue;
                    }
                    $inRing = ($r === 0 || $r === 6) && $c >= 0 && $c <= 6;
                    $inRing = $inRing || (($c === 0 || $c === 6) && $r >= 0 && $r <= 6);
                    $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;

                    $m[$row][$col] = $inRing || $inCore;
                    $fixed[$row][$col] = true;
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $m
     * @param list<list<bool>> $fixed
     */
    private static function alignment(array &$m, array &$fixed, int $version, int $size): void
    {
        $centres = self::ALIGNMENT[$version];
        foreach ($centres as $r) {
            foreach ($centres as $c) {
                // The three that would sit on a finder are omitted.
                if (($r === 6 && $c === 6)
                    || ($r === 6 && $c === $size - 7)
                    || ($r === $size - 7 && $c === 6)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $m[$r + $dr][$c + $dc] = \max(\abs($dr), \abs($dc)) !== 1;
                        $fixed[$r + $dr][$c + $dc] = true;
                    }
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $m
     * @param list<list<bool>> $fixed
     */
    private static function timing(array &$m, array &$fixed, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = ($i % 2) === 0;
            if (!$fixed[6][$i]) {
                $m[6][$i] = $dark;
                $fixed[6][$i] = true;
            }
            if (!$fixed[$i][6]) {
                $m[$i][6] = $dark;
                $fixed[$i][6] = true;
            }
        }
    }

    /** @param list<list<bool>> $fixed */
    private static function reserveFormat(array &$fixed, int $size): void
    {
        for ($i = 0; $i < 9; $i++) {
            $fixed[8][$i] = true;
            $fixed[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $fixed[8][$size - 1 - $i] = true;
            $fixed[$size - 1 - $i][8] = true;
        }
    }

    /**
     * The zigzag of Section 7.7.3: two-module columns, right to left,
     * alternating up and down, skipping the vertical timing column.
     *
     * Bits run out before the modules do -- that is what the remainder bits
     * of Table 1 are -- and the leftovers stay light.
     *
     * @param list<list<bool>> $m
     * @param list<list<bool>> $fixed
     * @param list<int> $codewords
     */
    private static function placeData(array &$m, array $fixed, array $codewords, int $version, int $size): void
    {
        $bits = '';
        foreach ($codewords as $byte) {
            $bits .= \str_pad(\decbin($byte), 8, '0', \STR_PAD_LEFT);
        }
        $bits .= \str_repeat('0', self::REMAINDER_BITS[$version]);

        $at = 0;
        $total = \strlen($bits);
        $upward = true;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            // Column 6 is the vertical timing pattern; the pairs step around
            // it rather than through it.
            if ($col === 6) {
                $col--;
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;
                for ($j = 0; $j < 2; $j++) {
                    $c = $col - $j;
                    if ($fixed[$row][$c]) {
                        continue;
                    }
                    $m[$row][$c] = $at < $total && $bits[$at] === '1';
                    $at++;
                }
            }
            $upward = !$upward;
        }
    }

    /**
     * @param list<list<bool>> $m
     * @param list<list<bool>> $fixed
     */
    private static function applyMask(array &$m, array $fixed, int $size, int $mask): void
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($fixed[$r][$c]) {
                    continue;
                }
                if (self::maskAt($mask, $r, $c)) {
                    $m[$r][$c] = !$m[$r][$c];
                }
            }
        }
    }

    private static function maskAt(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => (($r + $c) % 2) === 0,
            1 => ($r % 2) === 0,
            2 => ($c % 3) === 0,
            3 => (($r + $c) % 3) === 0,
            4 => ((\intdiv($r, 2) + \intdiv($c, 3)) % 2) === 0,
            5 => ((($r * $c) % 2) + (($r * $c) % 3)) === 0,
            6 => (((($r * $c) % 2) + (($r * $c) % 3)) % 2) === 0,
            7 => (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0,
            default => false,
        };
    }

    /**
     * Try all eight masks and keep the lowest penalty, ties to the lowest
     * mask number (Section 7.8.3).
     *
     * **The format information and the dark module are not stamped before
     * scoring**, and this is the one place where two defensible readings of
     * the standard part company. Section 7.8 is evaluating the DATA masking,
     * and neither the format bits nor the dark module is masked; some
     * encoders (nayuki's, and everything that copied it) stamp them anyway
     * and score the finished symbol. The two agree on most payloads and
     * disagree on a good few, because those modules sit hard against the
     * finders where rules 1 and 3 are most sensitive.
     *
     * NEITHER IS A CORRECTNESS BUG: all eight masks decode, and the score
     * only picks the one least likely to trouble a scanner. What it decides
     * is whether this encoder's output is byte-identical to the oracle the
     * fixtures were captured from, and a fixture test that has to be told
     * "these rows may differ" is not a test. So Carl scores the bare masked
     * encoding region, and 21_tags_test.php holds it there.
     *
     * @param list<list<bool>> $m
     * @param list<list<bool>> $fixed
     */
    private static function chooseMask(array $m, array $fixed, int $size): int
    {
        $best = 0;
        $bestScore = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $m;
            self::applyMask($candidate, $fixed, $size, $mask);
            $score = Penalty::score($candidate, $size);
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $mask;
            }
        }

        return $best;
    }

    /**
     * The 15-bit format information, written twice (Section 7.9).
     *
     * @param list<list<bool>> $m
     */
    private static function writeFormat(array &$m, int $size, string $ecLevel, int $mask): void
    {
        $format = self::formatBits(self::EC_INDICATOR[$ecLevel], $mask);

        for ($i = 0; $i < 15; $i++) {
            // LEAST significant bit first. Both copies are indexed from bit 0
            // of the 15-bit format string, which is the one thing about this
            // layout that reads backwards: the module nearest the top-left
            // corner carries the LOW bit, not the high one. Writing it
            // most-significant-first produces a symbol whose finders, timing
            // and data are all perfect and which no scanner will read, and
            // the fixtures in 21_tags_test.php are what caught it.
            $bit = (($format >> $i) & 1) === 1;

            // Copy 1: down the left of the top-left finder, then along the
            // top. Column 6 and row 6 are timing, so both walks step over
            // their own index 6.
            if ($i < 6) {
                $m[$i][8] = $bit;
            } elseif ($i === 6) {
                $m[7][8] = $bit;
            } elseif ($i === 7) {
                $m[8][8] = $bit;
            } elseif ($i === 8) {
                $m[8][7] = $bit;
            } else {
                $m[8][14 - $i] = $bit;
            }

            // Copy 2: along the bottom-left, then down the top-right.
            if ($i < 8) {
                $m[8][$size - 1 - $i] = $bit;
            } else {
                $m[$size - 15 + $i][8] = $bit;
            }
        }
    }

    /** BCH(15,5) with generator 0x537, then the 0x5412 mask (Section 7.9.1). */
    private static function formatBits(int $ecIndicator, int $mask): int
    {
        $data = ($ecIndicator << 3) | $mask;
        $rest = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ((($rest >> $i) & 1) === 1) {
                $rest ^= 0x537 << ($i - 10);
            }
        }
        return (($data << 10) | $rest) ^ 0x5412;
    }
}
