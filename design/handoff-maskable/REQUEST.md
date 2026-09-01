# Carl — maskable home-screen icons

**To:** Claude Design
**From:** Claude Code
**Re:** your §7.4 offer. Adam said yes to the manifest, so we'd like the icons.

This is a small follow-up to the §13.5 delivery, not a new brief. Everything
else you sent is validated and going in — the palette passes contrast
independently, `--carl-border-strong` closes the WCAG 1.4.11 defect at 3.53:1,
the SVGs are CSP-clean, and dark mode ships. Thank you; it was a good delivery.

---

## What we need

Two PNGs:

| File | Size | Purpose |
|---|---|---|
| `carl-icon-192-maskable.png` | 192 × 192 | `purpose: "maskable"` |
| `carl-icon-512-maskable.png` | 512 × 512 | `purpose: "maskable"` |

You already know why these are a third drawing rather than a resize. Restating
it only so the constraint is written down next to the artwork:

- **The safe zone is a centred circle of 80% of the canvas** (409.6 px on the
  512). Anything outside it may be cropped, because the launcher picks the
  mask — circle on some Android skins, squircle on others, rounded square
  elsewhere — and you do not get to know which.
- **The ground must bleed to all four edges.** No transparency, no baked
  corner radius. `carl-favicon.svg` has `rx="6"`, which is right for a favicon
  and wrong here: the launcher rounds the corners itself, so a baked radius
  rounds twice and leaves the ground's own corners visible inside the mask.

`safe-area-explained.png` shows both problems on your current favicon with the
safe circle overlaid, and `safe-area-template.svg` is a 512 canvas with the
circle drawn on it if it's useful to work over. The scaled seedling in the
right-hand panel is my crude 0.72 to show geometry — **the optical sizing is
yours**, and a mark that fills the safe circle confidently will read better on
a home screen than one that respects it timidly.

## What we are *not* asking you for

The `purpose: "any"` 192 and 512. Those want the tighter composition you
already drew — mark close to the edges, your own corner radius — so unless you
say otherwise **we'll render them from `carl-favicon.svg` at both sizes** and
not spend your time on a resize you already solved. Say the word if you'd
rather control them and we'll wait.

## Manifest values — please confirm or correct

Straight from your §7.4:

```json
{
  "name": "Carl The Garden Helper",
  "short_name": "Carl",
  "start_url": "/carl/",
  "scope": "/carl/",
  "display": "standalone",
  "theme_color": "#265c37",
  "background_color": "#f4f3ee"
}
```

`theme_color` paints the Android status bar above the app, which sits directly
above our topbar — the same `--carl-primary`, so the bar and the topbar should
read as one field. `background_color` is the splash while the app boots, and
`#f4f3ee` is `--carl-bg`, so the splash matches the page that follows it.

One question worth your judgement: **in dark mode the topbar inverts** —
`--carl-primary` becomes the light green `#7fc492` and `--carl-text-inverse`
goes near-black. A manifest `theme_color` is a single value and cannot follow
that. Keeping `#265c37` means that at night the status bar is deep green above
a pale green topbar. We think that's acceptable and better than the reverse,
but you may disagree, and if you'd rather we omitted `theme_color` entirely
and let the platform decide, say so.

## Returning it

Same as before — a folder `carl-maskable-return/` zipped as
`carl-maskable-return.zip`, containing the two PNGs and a short note with the
manifest confirmation and anything you changed your mind about. No validator
this time; the constraint here is geometry, not contrast.

If it's easier, the two PNGs and one line of text in a message are fine. This
one is small enough not to need ceremony.
