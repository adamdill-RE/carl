<?php

declare(strict_types=1);

namespace Carl\Reminders;

use Carl\Domain\ReminderKind;

/**
 * The digest email's body: plain text first, with a simple HTML twin
 * (handoff Section 12).
 *
 * Plain text first is not a formality. It is what a phone shows in a
 * notification preview, what a screen reader reads, and what survives a mail
 * client that blocks remote content -- and it is the version that has to make
 * sense on its own, because the HTML is the alternative, not the message.
 *
 * The HTML is deliberately plain: a table-free, image-free, single-column
 * document with inline styles, because inline styles are the only thing mail
 * clients reliably honour. That is the exact opposite of the rule inside the
 * application, where the CSP refuses them (Phase 3 handoff Section 1.5) --
 * these strings never reach a page.
 */
final class DigestMessage
{
    /**
     * @param list<array<string,mixed>> $reminders
     * @param bool $hasResearchedRegion when false, the one line Section 9.4
     *        asks for: say what is missing rather than silently omitting it
     */
    public static function text(
        array $reminders,
        string $date,
        string $name,
        string $appUrl,
        string $unsubscribeUrl,
        bool $hasResearchedRegion = true,
    ): string {
        $lines = [];
        $lines[] = 'Good morning' . ($name !== '' ? ', ' . $name : '') . '.';
        $lines[] = '';
        $lines[] = \count($reminders) === 1
            ? 'One thing for today, ' . $date . ':'
            : \count($reminders) . ' things for today, ' . $date . ':';
        $lines[] = '';

        foreach (self::grouped($reminders) as $label => $group) {
            $lines[] = \strtoupper($label);
            foreach ($group as $reminder) {
                $lines[] = '  * ' . $reminder['title'];
                $body = \trim((string) ($reminder['body'] ?? ''));
                if ($body !== '') {
                    foreach (self::wrap($body, 68) as $wrapped) {
                        $lines[] = '    ' . $wrapped;
                    }
                }
            }
            $lines[] = '';
        }

        if (!$hasResearchedRegion) {
            $lines[] = 'Your county has no research loaded yet, so frost dates, planting';
            $lines[] = 'windows and pest timings are left out of this. Everything else --';
            $lines[] = 'watering, days to maturity, hardening -- works without them.';
            $lines[] = '';
        }

        $lines[] = 'Open Carl: ' . $appUrl;
        $lines[] = '';
        $lines[] = '--';
        $lines[] = 'You are getting this because your Carl account has the daily digest on.';
        $lines[] = 'Stop them: ' . $unsubscribeUrl;

        return \implode("\n", $lines) . "\n";
    }

    /** @param list<array<string,mixed>> $reminders */
    public static function html(
        array $reminders,
        string $date,
        string $name,
        string $appUrl,
        string $unsubscribeUrl,
        bool $hasResearchedRegion = true,
    ): string {
        $e = static fn (string $value): string
            => \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        // The palette here is LITERAL and deliberately not the app's tokens.
        // A mail client will not load tokens.css, so these five hexes are the
        // one place a palette swap cannot reach (handoff Section 13.5), and
        // two of them are pitched away from the app on purpose: #656b63 is
        // two steps lighter than --carl-text-muted and #377f47 is
        // --carl-primary lightened, both so they survive a client that
        // renders this on a near-black ground. --carl-primary-dark would
        // vanish there. Do not "correct" them to match the palette.
        //
        // The background is set explicitly rather than left to the client,
        // which is the single most useful thing available for dark-mode
        // legibility; Gmail's forced dark mode ignores it and inverts the
        // block whole, which is survivable because it inverts text and ground
        // together. The value that has to survive a PARTIAL inversion is the
        // link, which is why it is mid-tone rather than at brand strength.
        // A real document rather than a bare <div>, for the two meta tags
        // only: they are how Apple Mail, Outlook.com and most iOS clients are
        // told to leave the message light instead of inverting it. Gmail's
        // forced dark mode ignores them, which is why the colours above are
        // chosen to survive an inversion anyway rather than to rely on this.
        $out = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="color-scheme" content="light">'
            . '<meta name="supported-color-schemes" content="light">'
            . '</head><body style="margin:0;padding:0;background-color:#ffffff">';
        $out .= '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;'
            . 'font-size:15px;line-height:1.5;color:#191d19;background-color:#ffffff;'
            . 'max-width:620px">';
        $out .= '<p>Good morning' . ($name !== '' ? ', ' . $e($name) : '') . '.</p>';
        $out .= '<p>' . (\count($reminders) === 1 ? 'One thing' : $e((string) \count($reminders)) . ' things')
            . ' for today, ' . $e($date) . ':</p>';

        foreach (self::grouped($reminders) as $label => $group) {
            $out .= '<h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.04em;'
                . 'color:#656b63;margin:18px 0 6px">' . $e($label) . '</h3>';
            $out .= '<ul style="margin:0;padding-left:18px">';
            foreach ($group as $reminder) {
                $out .= '<li style="margin-bottom:8px"><strong>'
                    . $e((string) $reminder['title']) . '</strong>';
                $body = \trim((string) ($reminder['body'] ?? ''));
                if ($body !== '') {
                    $out .= '<br><span style="color:#656b63;font-size:14px">' . $e($body) . '</span>';
                }
                $out .= '</li>';
            }
            $out .= '</ul>';
        }

        if (!$hasResearchedRegion) {
            $out .= '<p style="font-size:13px;color:#656b63;margin-top:18px">Your county has no '
                . 'research loaded yet, so frost dates, planting windows and pest timings are '
                . 'left out of this. Everything else -- watering, days to maturity, hardening -- '
                . 'works without them.</p>';
        }

        $out .= '<p style="margin-top:20px"><a href="' . $e($appUrl)
            . '" style="color:#377f47">Open Carl</a></p>';
        $out .= '<hr style="border:none;border-top:1px solid #d3d1c5;margin:20px 0">';
        $out .= '<p style="font-size:12px;color:#656b63">You are getting this because your Carl '
            . 'account has the daily digest on. <a href="' . $e($unsubscribeUrl)
            . '" style="color:#656b63">Stop them</a>.</p>';
        $out .= '</div></body></html>';

        return $out;
    }

    /**
     * Grouped by kind label, in priority order -- so a freeze warning is
     * above a pest note whatever order the builder produced them in.
     *
     * @param list<array<string,mixed>> $reminders
     * @return array<string,list<array<string,mixed>>>
     */
    public static function grouped(array $reminders): array
    {
        $sorted = $reminders;
        \usort($sorted, static function (array $a, array $b): int {
            $byPriority = ReminderKind::priority((string) $a['kind'])
                <=> ReminderKind::priority((string) $b['kind']);
            return $byPriority !== 0 ? $byPriority : \strcmp((string) $a['title'], (string) $b['title']);
        });

        $out = [];
        foreach ($sorted as $reminder) {
            $out[ReminderKind::label((string) $reminder['kind'])][] = $reminder;
        }
        return $out;
    }

    /** @return list<string> */
    private static function wrap(string $text, int $width): array
    {
        return \explode("\n", \wordwrap($text, $width, "\n", false));
    }
}
