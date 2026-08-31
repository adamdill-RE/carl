<?php

declare(strict_types=1);

namespace Carl\Qr;

/**
 * A most-significant-bit-first bit buffer, as an ASCII string of '0' and '1'.
 *
 * A string rather than an integer array because the longest thing this ever
 * holds is 800 bits and string concatenation is the cheapest append PHP has.
 * There is no reader: nothing in Carl decodes a QR symbol.
 */
final class BitWriter
{
    private string $bits = '';

    /** Append the low $width bits of $value, most significant first. */
    public function write(int $value, int $width): void
    {
        for ($i = $width - 1; $i >= 0; $i--) {
            $this->bits .= (($value >> $i) & 1) === 1 ? '1' : '0';
        }
    }

    public function length(): int
    {
        return \strlen($this->bits);
    }

    /** Zero-fill to the next byte boundary. */
    public function pad(): void
    {
        $over = \strlen($this->bits) % 8;
        if ($over !== 0) {
            $this->bits .= \str_repeat('0', 8 - $over);
        }
    }

    /** @return list<int> */
    public function bytes(): array
    {
        $out = [];
        foreach (\str_split($this->bits, 8) as $chunk) {
            $out[] = (int) \bindec($chunk);
        }
        return $out;
    }
}
