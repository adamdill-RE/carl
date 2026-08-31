<?php

declare(strict_types=1);

namespace Carl\Qr;

/**
 * The four mask penalty rules of ISO 18004 Section 7.8.3.2.
 *
 * The mask is chosen by scoring all eight and keeping the lowest, so this
 * file does not decide whether a symbol scans -- it decides WHICH of eight
 * scannable symbols is printed. Getting a rule subtly wrong therefore does
 * not produce a broken tag; it produces a tag that differs from every other
 * encoder's output for the same payload, which is exactly what the fixture
 * test in 21_tags_test.php catches and nothing in the field would.
 */
final class Penalty
{
    private const N1 = 3;    // a run of five or more
    private const N2 = 3;    // a 2x2 block of one colour
    private const N3 = 40;   // a finder-like 1:1:3:1:1 sequence
    private const N4 = 10;   // every 5% the dark proportion is off 50%

    /**
     * The 1:1:3:1:1 run itself. The light area that has to sit beside it is
     * NOT part of this string -- see finderLikeInLine() for why.
     */
    private const FINDER_LIKE = '1011101';

    /** @param list<list<bool>> $m */
    public static function score(array $m, int $size): int
    {
        return self::runs($m, $size)
            + self::blocks($m, $size)
            + self::finderLike($m, $size)
            + self::balance($m, $size);
    }

    /**
     * Rule 1: five or more modules of one colour in a line score
     * 3 + (length - 5), in rows and in columns.
     *
     * @param list<list<bool>> $m
     */
    private static function runs(array $m, int $size): int
    {
        $score = 0;

        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $horizontal) {
                $run = 0;
                $previous = null;
                for ($j = 0; $j < $size; $j++) {
                    $cell = $horizontal ? $m[$i][$j] : $m[$j][$i];
                    if ($cell === $previous) {
                        $run++;
                    } else {
                        $score += self::runScore($run);
                        $previous = $cell;
                        $run = 1;
                    }
                }
                $score += self::runScore($run);
            }
        }

        return $score;
    }

    private static function runScore(int $run): int
    {
        return $run >= 5 ? self::N1 + $run - 5 : 0;
    }

    /**
     * Rule 2: every 2x2 block of one colour scores 3. Overlapping blocks
     * each count, which is what makes a 3x3 solid area worth 12 and not 3.
     *
     * @param list<list<bool>> $m
     */
    private static function blocks(array $m, int $size): int
    {
        $score = 0;
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $first = $m[$r][$c];
                if ($m[$r][$c + 1] === $first
                    && $m[$r + 1][$c] === $first
                    && $m[$r + 1][$c + 1] === $first) {
                    $score += self::N2;
                }
            }
        }
        return $score;
    }

    /**
     * Rule 3: a 1:1:3:1:1 dark-light run with a light area four modules wide
     * on one side of it -- the finder pattern's own proportions -- scores 40
     * wherever it appears, because a scanner can mistake it for a finder.
     *
     * @param list<list<bool>> $m
     */
    private static function finderLike(array $m, int $size): int
    {
        $score = 0;

        for ($i = 0; $i < $size; $i++) {
            $row = '';
            $column = '';
            for ($j = 0; $j < $size; $j++) {
                $row .= $m[$i][$j] ? '1' : '0';
                $column .= $m[$j][$i] ? '1' : '0';
            }
            $score += self::finderLikeInLine($row) + self::finderLikeInLine($column);
        }

        return $score;
    }

    /**
     * THE QUIET ZONE COUNTS AS THE LIGHT AREA, and that is the whole subtlety
     * of this rule.
     *
     * The obvious implementation searches each line for the eleven-module
     * strings "10111010000" and "00001011101" and is wrong at both ends of
     * every line: a symbol is surrounded by four light modules of quiet zone
     * by definition (ISO 18004 Section 6.3), so a 1:1:3:1:1 run that ENDS at
     * the right edge is preceded-or-followed by a light area just as much as
     * one in the middle -- there simply are not four modules inside the
     * symbol to spell it with. Requiring them scores those runs at zero,
     * which picks a different mask from every conforming encoder on roughly
     * one symbol in three. It is invisible in the field, because the symbol
     * still scans; it is caught by the fixture comparison in
     * 21_tags_test.php, which is the argument for having one.
     *
     * So the run is matched on its own and the light area is tested as
     * "however many modules are left, up to four, are all light" -- which is
     * vacuously true past the edge.
     *
     * The restart offset is the other half of the rule: a counted match
     * resumes past the run, an uncounted one resumes four modules in, because
     * "1011101" can overlap itself at that distance and the second copy may
     * have the light area the first lacked.
     */
    private static function finderLikeInLine(string $line): int
    {
        $score = 0;
        $offset = 0;

        while (($at = \strpos($line, self::FINDER_LIKE, $offset)) !== false) {
            $before = \substr($line, \max($at - 4, 0), \min($at, 4));
            $after = \substr($line, $at + 7, 4);

            if (\strpos($before, '1') === false || \strpos($after, '1') === false) {
                $score += self::N3;
                $offset = $at + 7;
            } else {
                $offset = $at + 4;
            }
        }

        return $score;
    }

    /**
     * Rule 4: 10 points for every 5% the dark proportion strays from half.
     *
     * The standard states it as the number of complete 5% steps between the
     * proportion and 50%, which is a floor -- not a round -- and the
     * difference shows up as a different mask choice on about one symbol in
     * ten.
     *
     * @param list<list<bool>> $m
     */
    private static function balance(array $m, int $size): int
    {
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }

        $total = $size * $size;
        $percent = $dark * 100 / $total;

        return (int) (\floor(\abs($percent - 50) / 5) * self::N4);
    }
}
