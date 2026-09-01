<?php

declare(strict_types=1);

namespace Carl\Qr;

/**
 * GF(256) arithmetic and the Reed-Solomon remainder, for QR error correction.
 *
 * The field is GF(2^8) with primitive polynomial x^8 + x^4 + x^3 + x^2 + 1
 * (0x11D) and generator 2, which is what ISO 18004 Section 7.5.2 specifies.
 * Two 256-entry tables -- antilog and log -- turn every multiplication into
 * two lookups and an addition, and they are built once per process.
 *
 * This is the only mathematics in the QR encoder that is not bookkeeping, and
 * it is also the only part with a cheap independent check: the generator
 * polynomial for 10 error correction codewords is published in the standard's
 * Annex A, and 21_tags_test.php asserts it coefficient by coefficient before
 * trusting anything built on top.
 */
final class Galois
{
    /** @var list<int>|null antilog: EXP[i] = 2^i in the field */
    private static ?array $exp = null;
    /** @var list<int>|null log: LOG[v] = i such that 2^i = v */
    private static ?array $log = null;

    private static function tables(): void
    {
        if (self::$exp !== null) {
            return;
        }
        $exp = \array_fill(0, 256, 0);
        $log = \array_fill(0, 256, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x >= 256) {
                // Reduce modulo the primitive polynomial the moment the
                // shift leaves the field.
                $x ^= 0x11D;
            }
        }
        // exp[255] closes the cycle, so a caller may add two logs (each up to
        // 254) and index without reducing first.
        $exp[255] = $exp[0];

        self::$exp = $exp;
        self::$log = $log;
    }

    public static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        self::tables();
        /** @var list<int> $exp */
        $exp = self::$exp;
        /** @var list<int> $log */
        $log = self::$log;

        return $exp[($log[$a] + $log[$b]) % 255];
    }

    /**
     * The generator polynomial for $degree error correction codewords:
     * the product of (x - 2^i) for i in 0..degree-1, coefficients descending.
     *
     * @return list<int>
     */
    public static function generator(int $degree): array
    {
        self::tables();
        /** @var list<int> $exp */
        $exp = self::$exp;

        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = \array_fill(0, \count($poly) + 1, 0);
            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::multiply($coefficient, $exp[$i]);
            }
            /** @var list<int> $next */
            $poly = $next;
        }
        return $poly;
    }

    /**
     * The Reed-Solomon remainder: the error correction codewords for one
     * block of data codewords.
     *
     * Long division of the data polynomial, shifted up by $degree, by the
     * generator. The remainder is what a decoder uses to repair the block,
     * and it is the whole of what level Q's 25% damage tolerance buys.
     *
     * @param list<int> $data
     * @return list<int> exactly $degree codewords
     */
    public static function remainder(array $data, int $degree): array
    {
        $generator = self::generator($degree);
        $residue = \array_merge(\array_values($data), \array_fill(0, $degree, 0));
        $dataLength = \count($data);

        for ($i = 0; $i < $dataLength; $i++) {
            $lead = $residue[$i];
            if ($lead === 0) {
                continue;
            }
            foreach ($generator as $j => $coefficient) {
                $residue[$i + $j] ^= self::multiply($coefficient, $lead);
            }
        }

        /** @var list<int> $out */
        $out = \array_slice($residue, $dataLength, $degree);
        return $out;
    }
}
