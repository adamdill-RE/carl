<?php

declare(strict_types=1);

namespace Carl\Support;

/**
 * The design tokens, read out of `public/assets/css/tokens.css`.
 *
 * Handoff Section 13.5 makes the palette a Claude Design deliverable and
 * `tokens.css` "the only file in the repository that names a colour, so the
 * palette is a one-file swap". A PDF cannot use a CSS variable -- it needs
 * three numbers -- so the choice was between hard-coding a second palette
 * here and reading the first one. Hard-coding it would quietly end the
 * one-file-swap promise: the day the real palette lands, the PDF would still
 * be the placeholder green and nobody would know until they printed one.
 *
 * So this parses the file. It is 2 KB, read once per PDF, and the format it
 * expects is the format a stylesheet has to be in anyway.
 *
 * When a name is missing -- which is what happens if the file is replaced by
 * one that does not define it -- the fallback is a grey, never an invented
 * colour. Improvising a palette is what Section 17 says not to do.
 */
final class Tokens
{
    /** @var array<string,array{0:int,1:int,2:int}> */
    private array $colours = [];

    public function __construct(string $tokensCssPath)
    {
        $css = \is_file($tokensCssPath) ? (string) @\file_get_contents($tokensCssPath) : '';
        if ($css === '') {
            return;
        }

        // Only 3- and 6-digit hex. tokens.css is written by hand and by
        // Claude Design; a token given as rgb() or a colour name simply does
        // not resolve, and the caller falls back to grey rather than guessing.
        \preg_match_all(
            '/(--carl-[a-z0-9-]+)\s*:\s*#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\s*;/',
            $css,
            $matches,
            \PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $this->colours[$match[1]] = self::hexToRgb($match[2]);
        }
    }

    /**
     * @param array{0:int,1:int,2:int} $fallback
     * @return array{0:int,1:int,2:int}
     */
    public function rgb(string $name, array $fallback = [90, 90, 90]): array
    {
        return $this->colours[$name] ?? $fallback;
    }

    public function has(string $name): bool
    {
        return isset($this->colours[$name]);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $hex): array
    {
        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) \hexdec(\substr($hex, 0, 2)),
            (int) \hexdec(\substr($hex, 2, 2)),
            (int) \hexdec(\substr($hex, 4, 2)),
        ];
    }
}
