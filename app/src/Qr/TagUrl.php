<?php

declare(strict_types=1);

namespace Carl\Qr;

/**
 * The URL a tag carries, and the one decision about it that is not obvious.
 *
 * ---------------------------------------------------------------------------
 * UPPERCASE IS WORTH A LOT, AND IT IS NOT ALWAYS SAFE
 * ---------------------------------------------------------------------------
 *
 * docs/QR-TAGS-SPEC.md Section 2.2 is right about the encoding. QR's
 * alphanumeric mode covers `0-9 A-Z space $%*+-./:` and packs two characters
 * into 11 bits; one lower-case letter anywhere forces byte mode at 8 bits
 * each. For the 44-character tag URL that is the difference between
 *
 *     HTTPS://WWW.RESHIFTMANAGER.COM/CARL/T/AB7K4M   alnum, version 3, 29x29
 *     https://www.reshiftmanager.com/carl/t/ab7k4m   byte,  version 4, 33x33
 *
 * and on a 24 mm tag face that is 0.649 mm per module against 0.585 mm. The
 * spec concludes "DNS is case-insensitive and we choose the path, so an
 * all-uppercase URL is free."
 *
 * **HALF OF THAT PATH IS NOT OURS TO CHOOSE.** Carl is served from
 * `public_html/carl/` (hosting Section 5.1), and Apache and LiteSpeed map a
 * URL path onto a filesystem path case-sensitively on Linux. A request for
 * `/CARL/T/AB7K4M` looks for a directory named `CARL`, does not find one, and
 * is answered with the web server's own 404 -- the `.htaccess` inside `carl/`
 * is never consulted and `index.php` never runs. The scheme and the host are
 * case-insensitive by specification; the mount point is a directory.
 *
 * The segments after the mount ARE ours: `/T/AB7K4M` reaches PHP as a route,
 * and `Routes.php` registers both cases of the `t` segment while the code
 * itself is matched `[0-9A-Za-z]+` and upper-cased before lookup.
 *
 * So the choice is real and it is binary -- one lower-case character loses
 * alphanumeric mode entirely -- and it is made HERE rather than at the print
 * screen, once, for every tag:
 *
 *   - **Default: lower-case, byte mode, version 4.** It is the URL the server
 *     actually answers. 0.585 mm per module is 2.3x ISO 18004's ~0.25 mm
 *     practical print floor and gives a 600 dpi laser fourteen dots per
 *     module; the binding constraint on a phone is minimum focus distance,
 *     not module size (Section 2.3). A tag that is 10% coarser than ideal
 *     scans. A tag whose URL 404s is landfill, and it is landfill on a
 *     hundred stakes before anybody notices.
 *
 *   - **`tags.uppercase_url = true`: uppercase, alphanumeric, version 3.**
 *     Turn this on only when the uppercase mount actually resolves -- either
 *     because Carl is at the domain root, where there is no mount segment at
 *     all, or because a rewrite has been added above `public_html/carl/` and
 *     somebody has opened the uppercase URL in a browser and seen a page.
 *     `deploy.md` carries the check; the print screen shows which mode is in
 *     force and what it costs, so this is never a silent difference.
 *
 * Section 2.3's own first lever is better than either: a SHORT DOMAIN. At
 * `carl.garden` even byte mode fits version 3, and at `CARL.GARDEN` it fits
 * version 2 -- 0.727 mm modules, 12% bigger than the spec's best case. That
 * is a domain registration, not an engineering task, and it is the thing to
 * do if a tag ever proves marginal in the field.
 */
final class TagUrl
{
    /** The route segment, lower case. Routes.php registers the other case too. */
    public const SEGMENT = 't/';

    /** Level Q, 25% damage tolerance: mud, soil splash and a leaf across it. */
    public const EC_LEVEL = Encoder::EC_Q;

    /**
     * The tag face, less application slop each side (Section 2.3): a 25.4 mm
     * stake, less ~0.7 mm a side for a label that is never applied perfectly
     * straight.
     */
    public const TAG_FACE_MM = 24.0;

    /** ISO 18004 Section 6.3: four light modules on every side. */
    public const QUIET_MODULES = 4;

    /**
     * Should the URL be upper-cased?
     *
     * @param bool|null $configured `tags.uppercase_url`; null means decide
     *        from the mount point, which is the only thing that can make it
     *        unsafe.
     */
    public static function uppercaseIsSafe(?bool $configured, string $basePath): bool
    {
        if ($configured !== null) {
            return $configured;
        }
        // At the domain root there is no directory segment to get wrong, so
        // every part of the path is one this application routes itself.
        return \trim($basePath, '/') === '';
    }

    /** The base every tag URL is built on, with its trailing slash. */
    public static function base(string $origin, string $basePath, bool $uppercase): string
    {
        $base = \rtrim($origin, '/') . '/' . \ltrim(\rtrim($basePath, '/') . '/', '/');
        $base .= self::SEGMENT;

        return $uppercase ? \strtoupper($base) : $base;
    }

    public static function for(string $origin, string $basePath, string $code, bool $uppercase): string
    {
        $url = self::base($origin, $basePath, $uppercase) . $code;
        return $uppercase ? \strtoupper($url) : $url;
    }

    /**
     * What the current setting actually produces, measured rather than
     * assumed -- the print screen shows this so the trade-off in the class
     * docblock is visible where the decision is made.
     *
     * @return array{
     *   uppercase:bool, sample:string, mode:string, version:int, size:int,
     *   module_mm:float, headroom:int
     * }
     */
    public static function describe(string $origin, string $basePath, bool $uppercase): array
    {
        // A real six-character code, so the length is the length a tag has.
        $sample = self::for($origin, $basePath, 'AB7K4M', $uppercase);
        $symbol = Encoder::encode($sample, self::EC_LEVEL);

        $extent = $symbol->size() + 2 * self::QUIET_MODULES;

        return [
            'uppercase' => $uppercase,
            'sample'    => $sample,
            'mode'      => $symbol->mode,
            'version'   => $symbol->version,
            'size'      => $symbol->size(),
            'module_mm' => \round(self::TAG_FACE_MM / $extent, 3),
            'headroom'  => Encoder::capacity($symbol->version, self::EC_LEVEL, $symbol->mode)
                - \strlen($sample),
        ];
    }
}
