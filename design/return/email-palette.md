# Email palette — the five literal hex values

For `app/src/Reminders/DigestMessage.php`. Inline styles, no classes, no
webfonts, no images. These replace the eight placeholder hexes flagged in
BRIEF §3, Leak 1.

| Purpose | Placeholder | **Yours** | On white | On a #16181a client |
|---|---|---|---|---|
| Body text | `#1f2420` | **`#191d19`** | 17.1:1 | 1.0:1 |
| Muted text (section headings, secondary lines, footer) | `#5c635d` | **`#656b63`** | 5.5:1 | 3.3:1 |
| Link ("Open Carl") | `#24522f` | **`#377f47`** | 4.9:1 | 3.6:1 |
| Horizontal rule | `#d6d6cf` | **`#d3d1c5`** | 1.5:1 | 11.6:1 |
| Background | — | **`#ffffff`**, set explicitly | — | — |

## One line each on why

- **Body `#191d19`** — the same ink as `--carl-text`. The email should look
  like the app, not near it.
- **Muted `#656b63`** — *not* `--carl-text-muted` (`#545a52`). Two steps
  lighter, which trades a little contrast on white (still 5.5:1, well past AA)
  for legibility if a client renders it on a dark ground. A light grey would
  have been the wrong answer in both directions.
- **Link `#377f47`** — the one value deliberately off-palette. It is
  `--carl-primary` lightened until it clears AA on white (4.9:1) *and*
  stays above the 3:1 large-text/UI floor on near-black (3.6:1).
  `--carl-primary-dark` `#1b4227` would have vanished on black.
- **Rule `#d3d1c5`** — `--carl-border`. A rule, not a divider that shouts.
- **Background `#ffffff`** — see below.

## Set the background explicitly

The strongest thing you can do for dark-client legibility is stop leaving the
background to the client. On the outermost table or wrapping `<div>`:

```
style="background-color:#ffffff; color:#191d19;"
```

and in the HTML part's `<head>`:

```
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
```

Apple Mail, Outlook.com and most iOS clients honour that and leave the email
light. Gmail's forced dark mode ignores it and inverts the whole block — which
is survivable, because it inverts text and background together. The value that
has to survive a *partial* inversion is the link, which is why it is pitched
mid-tone rather than at brand strength.

## Checked

Rendered all five against `#ffffff`, `#f6f6f6`, `#1c1c1e` and `#16181a`.
On the two dark grounds the body ink and the rule invert or dim as expected;
the link holds at 3.6:1 and the muted grey at 3.3:1 — readable rather
than comfortable, which is the bar BRIEF §4.6 sets. Nothing in the set becomes
invisible.

Plain text stays the primary version. None of this changes it.
