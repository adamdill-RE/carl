# Carl The Garden Helper — Phase 11 handoff

**Phase 10 is built and green, and the specification is finished.** §13.5 —
the logo and the colour scheme — has been outstanding since Phase 1, through
six handoffs that each said "still the only spec item left". It is built.

`CARL-HANDOFF.md` now has **nothing unbuilt in it at all**. That is a first,
and it changes what a handoff is for: everything below is work somebody chose
to leave, not work the spec is still waiting on.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 10 annotated neither.** It leaned on §8.5 constantly — the CSP is
   the reason the mark is inline SVG rather than an `<img>`, and the reason
   `tests/check_brand_assets.php` exists — and on §5.1 once, for why the web
   manifest resolves every URL relative to itself instead of naming
   `base_path`. Both times the document was right and complete.
2. **`docs/CARL-HANDOFF.md`** — the specification. §14 is the phasing.
   **There is nothing unbuilt left in it.**
3. **`docs/DESIGN-NOTES.md`** — new. Claude Design's own account of the
   palette: what each value is, why the confidence tiers stopped aliasing the
   status colours, why the focus ring is an off-palette magenta, and how the
   chart series were checked under colour-blind simulation. **It is the
   documentation for `tokens.css` and should be read before any colour in this
   application is changed.** §6 of it is written to the next designer.
4. **`docs/QR-TAGS-SPEC.md`** §4.2 — why two variables in `tokens.css` are not
   palette and must never be themed. Two separate tests now pin this.
5. **`docs/deploy.md`** — the runbook. §0 is every measurement taken. Phase 10
   adds **no migration**, no cron and no route, so the deploy is a file copy
   and nothing else. It does add a directory (`public/assets/img/`) and a file
   at the web root (`public/manifest.webmanifest`), both of which ship on the
   existing `cp -R public/.` with no `.cpanel.yml` change — and one line to
   `public/.htaccess`, an `AddType` for `.webmanifest`, because neither Apache
   nor LiteSpeed ships a mapping for that extension and a manifest served as
   octet-stream may simply not parse.
6. **`docs/PHASE-10-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current in full; §4 gains eight entries in
   §4 below.
7. **§8 below is the working agreement.** One clause of it is now spent.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 23 (`001`–`023`), 42 tables — **Phase 10 added none** |
| Routes | 106 — **none added**; the manifest and the icons are static files |
| Source / views | 111 PHP classes (**+0**), 57 templates (**+2**, both logo partials) |
| Static CI checks | **7, up from 5** — `check_test_clocks.php` and `check_brand_assets.php` are new |
| Client shell | **24.6 KB** gzipped against 150 KB — up from 20.1, and all of it is CSS: the palette's comments and the dark file |

Phase 10 is the smallest phase in the project by code and the largest by
surface: it changed every screen without changing a single controller.

- **The palette.** `tokens.css` replaced wholesale. Still the only stylesheet
  that names a colour, now 40 tokens: the 33 that existed plus seven
  `--carl-chart-*`.
- **Dark mode.** `tokens-dark.css`, the same names under
  `prefers-color-scheme: dark`, loaded after `tokens.css` so it wins.
- **The mark**, inline in `partials/logo.php` and `partials/logo_lockup.php`.
- **Favicon, home-screen icons and a web manifest.** The `data:,` stopgap in
  `layout.php` is gone.
- **Charts on their own colours**, which freed `--carl-accent` to be the focus
  ring and nothing else.
- **The PDF and the digest email** carry the new palette, by two different
  mechanisms, for a reason worth remembering (§2.2).

---

## 2. What Phase 10 established that Phase 11 should not re-derive

### 2.1 "The only file that names a colour" was a claim, and it was checked

The architecture promised that a palette swap would be one file. It very
nearly was — but the promise was load-bearing enough to be worth *verifying*
rather than believing, and verifying it found two leaks that a one-file swap
would have left behind:

- **`DigestMessage.php` held eight hexes.** Mail clients honour inline styles
  and nothing else, so no stylesheet can reach the digest. A palette swap
  alone would have left the daily email in the placeholder green — on the one
  surface nobody looks at twice, because it arrives at 06:00 and is read on a
  phone.
- **`charts.js` and `Document.php` held fallbacks** for a failed token lookup.
  Cosmetic, but they were the *old* palette, so the failure mode was "degrade
  to a colour scheme that no longer exists anywhere".

Both are fixed. The lesson is the general one: **a rule that says "only one
place does X" is worth a grep every time somebody relies on it**, because the
exceptions are always in the surfaces that have no stylesheet — email, PDF, and
anything with a fallback.

### 2.2 The PDF and the email are deliberately not themed, for different reasons

`Carl\Support\Tokens` parses `tokens.css` and **never `tokens-dark.css`**, so
a report generated at midnight on a phone in dark mode is still black on
white. That is correct — paper is white — and it is why the dark palette is a
separate file rather than a media query inside `tokens.css`, where the PDF's
regex would happily have scraped the dark values.

The email is not themed either, but the mechanism is the opposite: it cannot
read a stylesheet at all, so its five colours are literal. Two of them are
deliberately **not** the app's tokens — the muted grey is two steps lighter
than `--carl-text-muted` and the link is `--carl-primary` lightened — because
they have to stay legible if a client renders them on near-black.
`--carl-primary-dark` would vanish there.

**Do not "correct" either of these to match the palette.** Both look like
inconsistencies and both are the considered answer.

### 2.3 An `<img>` cannot inherit `currentColor`, and that decided the logo

The mark is one drawing that has to be white on the topbar and green on login.
Every path is `currentColor`, which makes that free — but only if the SVG is
**inline in the HTML**. An `<img src="logo.svg">` renders in its own document
and inherits nothing, which would have meant two files, two colours, and a
drift waiting to happen.

Inline SVG is markup rather than a resource load, so the CSP is untouched.
That is what makes it viable, and it is also the trap: **the same CSP silently
drops a `style=` attribute inside that SVG**, so artwork pasted from a design
tool renders perfectly in the tool and wrong in the app, with no console error
anybody would notice. `tests/check_brand_assets.php` is the guard.

### 2.4 A maskable icon is a third drawing, not a resize

The favicon carries `rx="6"` and fills its frame. Both are correct for a
favicon and wrong for a launcher icon: the platform applies its own mask, so a
baked radius rounds twice, and anything outside a centred circle of 80% of the
canvas may be cropped.

The two maskable PNGs are drawn for that constraint. The `purpose: "any"` pair
is rendered from the favicon, because that composition is exactly what `any`
wants. **A manifest carrying only maskable icons is a bug** — the maskable one
then gets used as the plain icon and its safe-area padding reads as a small
mark adrift in a green square. `check_brand_assets.php` asserts both purposes
are present.

### 2.5 A test that mixes two timezones' idea of "today" is worse than a wrong test

`PHASE-10-HANDOFF.md` §2.4 said a test that pins a rendered count pins the
clock. Phase 10 found the sharper version of the same thing, in three more
places.

Four assertions compared a UTC `gmdate()` against dates the application
computes in `America/Chicago`. They were green all afternoon and red from
00:00 to 05:00 UTC. `c97dee9` fixed the four that had failed; the *pattern* was
still in the tree, and the one left in `23_calendar_test.php` was the worst of
them — at a month boundary the events land outside the drawn grid entirely and
the chip is simply absent, which is a much harder thing to diagnose at 2am
than an off-by-one date.

**The rule is not "never call `gmdate()`."** `04_weather_test.php` builds its
locations in UTC, so the server's day is the right one there. The rule is that
one test must not mix two timezones' idea of today, and
`tests/check_test_clocks.php` enforces exactly that: a case file that onboards
with ZIP 76692 may not take `$today` from the server clock unless the line
carries a `// utc-ok: <reason>` marker saying why. Four files legitimately do.

### 2.6 Two guards that cost nothing and run without a database

`check_test_clocks.php` and `check_brand_assets.php` join
`check_asset_budget.php`, `check_collation.php` and `lint_cpanel_yml.php`.
Four of the five now run in the **static** job — no MySQL, no network, seconds
— which matters because the failures they catch are all of the same kind:
**silent**. A dropped `style=`, a manifest icon that 404s, a QR tinted to
brand, a suite that only fails at night. None of them produces an error
anybody would see.

That is the test to apply to a future guard: not "is this likely" but "would
anybody find out".

---

## 3. Phase 11 — what is left

Nothing here is spec. Every item is something somebody chose to leave.

### 3.1 The whole-sowing report — now the oldest unbuilt thing in the project

Carried from Phase 10 §3.2, Phase 9 §3.2 and Phase 8 §3.3, and with §13.5
gone **this is the most obviously ready-to-build thing in the repository**.

`planting.root_planting_id` has existed since Phase 7,
`PlantingRepository::wholeSowing()` reads it in one indexed statement, and
**nothing calls either except a test.** "This tray produced 100 plants, 94
transplanted into three beds, 61 alive, 40 kg picked" is the report the column
was put there for. It is a page, not a plumbing change.

Phase 9 sharpened it once (the Calendar draws a month per planting, so a tray
split five ways draws five sets of projections and still has no screen saying
what the sowing did). Phase 10 sharpens it again, differently: **the charts now
have a colour set designed as a set**, so a report comparing five siblings has
five distinguishable series available to it for the first time.

### 3.2 Reminders: thirteen kinds, no pagination, no roll-up

Unchanged from Phase 10 §3.3, and Phase 10's palette work makes the argument slightly worse
for leaving it. `DigestMessage::grouped()` orders by priority and that is the
whole of the triage; nothing caps the count and nothing says "and six more
waterings".

The Calendar solved this exact problem on the grid and the rules are written
down in `Planting\Calendar`'s docblock. The digest is still the only screen
that says everything at once — and it is now also the only screen that cannot
follow the palette, so it is the one place where a wall of text has no visual
hierarchy to fall back on.

### 3.3 What Recommendations still wants

Unchanged from Phase 10 §3.4, all four still true: nothing displays
`analysis.scope` on the answer; the per-day cap is per account rather than per
cost (and `document_bytes` is stored per row, so a cost-weighted cap needs no
migration); an answer from March sits on the page in September with only its
date to say so; and the document carries lineage with nothing telling the model
what to do with it, still untested against a split account.

### 3.4 A second region

Unchanged from Phase 10 §3.5. Everything in the research schema is
region-agnostic and exactly one region has ever been imported. A second county
is the cheapest way to find out what quietly assumed one, and
`plantTypesForRegion()`'s overlay is the obvious suspect. The pest windows on
the calendar come from the research layer, so this is still the only way to
see whether they generalise past the county they were written for.

### 3.5 The catalogue's other half, and template version 3

Unchanged from Phase 10 §3.6. `pests.csv` carries seven columns and the
catalogue carries nineteen. Widening it means `TEMPLATE_VERSION = 3`, and the
rule stands: **a research zip that imported yesterday must import today**, and
there are zips in the owner queue (§5 item 9) built against version 2. Raise
the version after those are imported, not before.

### 3.6 Design items nobody has asked for, listed so they are not re-discovered

Small, all optional, and none of them is a defect:

- **`password_setup.php` has no lockup.** It is arguably the true first
  impression — a new account arrives there from an email link, before it ever
  sees login. The lockup went on login and onboarding step one because that is
  what Claude Design specified; this one is a judgement call nobody has made.
- **`--carl-approx` and `--carl-warn` are the same hex.** The confidence tiers
  are now separately *declared*, which was the point — they can be tuned
  without moving the brand — but two of them still happen to be equal. Fine as
  is; worth knowing before somebody "fixes" a duplicate.
- **The favicon hardcodes `#265c37` and `#f4f3ee`.** Correct, because a
  favicon needs its own background and is not themable, but it makes the
  favicon the one asset that must be redrawn by hand if the brand moves.
  `DESIGN-NOTES.md` §6 is where a future designer will look.
- **Dark mode is untested on a real phone at dusk**, which is the only test
  that counts for it. The arithmetic passes in both themes; the reason dark
  mode exists is a field one.
- **`--carl-border` is 1.53:1 and deliberately advisory.** Decorative only.
  Pushing it to 3:1 makes every card on a dense screen shout. If anything
  reads too quiet in real sunlight, `DESIGN-NOTES.md` §5 names this and
  `--carl-text-muted` on `--carl-surface-sunk` as the two to move first, and
  says neither would disturb anything else.

### 3.7 What Phase 9 and Phase 8 left undone, deliberately

All carried unchanged: Phase 10 §3.7 (the camera question, which is the
owner's and is reversible), §3.8 (no photographs or diagnostic key on
`/pests`, the catalogue not linked from the log form, `affects_categories` as
a string, nothing writing back) and §3.9 (the tag origin written inline in
four places, no scan log, the named-label queue, retire-per-sheet, and half of
each label stock's geometry derived rather than measured).

**The four inline site URLs in §3.9 are worth promoting.** `config/app.php`
has `tags.origin`; `AdminController` and `Reminders\Digest` still spell
`https://www.reshiftmanager.com` out. Phase 10 touched `Digest`'s neighbour
`DigestMessage` and deliberately did not widen into it, but the next person in
that file should take them.

---

## 4. What must not regress

Everything in `PHASE-10-HANDOFF.md` §4 still applies. Phase 10 adds eight.

1. **`tokens.css` must keep the words DO NOT THEME.** Two tests assert it —
   `21_tags_test.php` and `check_brand_assets.php` — and they are not
   redundant: one needs a database and one does not, so the static job catches
   it first. Claude Design's delivered file said the same thing in different
   words, and swapping it in verbatim would have failed the suite.
2. **`--carl-qr-ink` and `--carl-qr-paper` stay `#000000` / `#ffffff` in both
   themes.** They do not invert for dark. A tinted QR stops scanning and
   nothing reports it.
3. **No `style=` inside any SVG**, in a partial or a file. The CSP drops it in
   silence.
4. **The logo stays inline, not an `<img>`.** `currentColor` is the whole
   design and an `<img>` cannot inherit it.
5. **`tokens-dark.css` loads after `tokens.css`**, and `Tokens.php` reads only
   `tokens.css`. Reversing either breaks a theme or a PDF.
6. **Manifest URLs stay relative.** `start_url`, `scope` and every icon `src`
   resolve against the manifest's own location, which is what lets the file
   know nothing about `base_path`. A leading slash silently resolves to the
   domain root and misses `/carl/`.
7. **The manifest keeps both icon purposes.** Maskable-only is a bug that
   looks like a design choice.
8. **`--carl-accent` is the focus ring and nothing else.** It was freed from
   the ET₀ chart line on purpose. A sixth data colour means a new
   `--carl-chart-*`, not borrowing this one back.

---

## 5. Owner actions outstanding

**Twelve, and none has been performed.** Unchanged from Phase 10 §5, which was
unchanged from Phase 9. Items 4–6 have now been outstanding since Phase 3.

The list is in `PHASE-10-HANDOFF.md` §5 and is not reproduced here, because
copying it a third time has not made anybody do it. Two are worth naming:

**§5.1, still the one platform fact not established.** Outbound HTTPS to
`api.anthropic.com` has never been tried from sh193 — carried unchanged
through Phase 6, 7, 8, 9 and 10. It is five minutes, it is safe (a failure
lands in `analysis_run.error_text` and on `/status`, never on a page), and it
is the only thing between Recommendations and "known to work on this host".

**Item 9, the research imports**, now blocks §3.5 as well as everything it
already blocked.

Phase 10 adds none. It is the first phase that asks the owner for nothing —
the icons, the manifest and the palette all ship in the repository.

---

## 6. Claude Design outstanding

**Nothing.** For the first time since Phase 1 this section is empty.

Delivered and wired: the palette, the dark palette, the mark, the lockup, the
favicon, the maskable icons, the chart set and the five email colours.
`design/return/` and `design/handoff-maskable/` are the deliveries as
received; `docs/DESIGN-NOTES.md` is the reasoning and belongs to whoever
changes a colour next.

Two standing constraints outlive the engagement, both in §4 above: the QR
variables are not palette, and `--carl-accent` is the focus ring only.

One thing was **part-declined** and Claude Design was told: they asked for
event markers as filled circles distinct in size from the temperature points,
and they are triangles. A different shape is better shape-encoding than the
same shape at a different size, and the request came from a mockup that
composited every series onto one chart where the app has three tabs. The ET₀
dash from the same note *was* taken, on the same reasoning inverted — it costs
one property and is already right if those panels ever merge.

---

## 7. Where the bodies are buried

Everything in `PHASE-10-HANDOFF.md` §7 still applies. Phase 10 adds four.

- **`getComputedStyle` on a custom property returns a leading space.**
  `charts.js` has trimmed it since Phase 4 and it is easy to lose in a
  rewrite; a colour string with a space in front fails silently in Chart.js
  and the series draws in its default palette rather than not at all.
- **`grep -c '<text'` over a `.php` partial matches the docblock.** Two of the
  guards in `check_brand_assets.php` would false-positive on prose describing
  the thing being banned, which is why it strips everything before `?>` before
  looking. A naive version of that check fails on its own comment.
- **A manifest icon that 404s produces no error anywhere.** Not in the
  console, not in the install flow. The install simply has no icon.
- **The suite's fixtures share a `weather_location` per ZIP.** Several tests
  onboard with 76692 and land on the same row, which is why
  `06_backfill_test.php` parks `backfill_from` in the future before it starts.
  A new test that writes weather for 76692 without doing that will make an
  older one fail somewhere else entirely.

---

## 8. Working agreement

Unchanged from `PHASE-10-HANDOFF.md` §8 except for one clause, which is now
spent:

> When the field sheet, logo or palette are needed, stop and request them from
> Claude Design rather than improvising.

All three have been requested, delivered and built. The clause stays in
`CARL-HANDOFF.md` §17 as the rule for anything *new* that needs designing —
and the two engagements are worth copying, because both worked the same way:
a brief that stated the constraints as constraints (the CSP, the hex-only PDF
parser, the 44 px touch target), a validator the designer could run before
sending, and a delivery that was checked rather than trusted. The checking
found two real things — a missing `DO NOT THEME` that would have failed the
suite, and a maskable icon spec that needed a picture rather than a paragraph.

One addition, from §2.1:

> A rule that says "only one place in the repository does X" is worth a grep
> every time somebody relies on it. The exceptions are always in the surfaces
> that have no stylesheet: email, PDF, and anything holding a fallback.
