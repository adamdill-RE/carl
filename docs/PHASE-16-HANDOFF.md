# Carl The Garden Helper — Phase 16 handoff

**Phase 15 is five small asks from the owner's walk through the screens, and
one decision.** The calendar could not be printed; a chip on it three months
out said "Transplant" and nothing else; a tray of six seed starts was six
trips back through the menu; the menu's own tiles were a scroll below the
weather on the one page that has them; and the seed-start form counted a
tray as twelve. None of the five touches the schema, the crons or a third
party. The decision — whether the fix for the fourth was to move the tiles
or to make the top bar's Menu pill open them from everywhere — is §2.3, and
it is the one thing in this phase that changes every screen.

The two candidates for Phase 15 that the last handoff researched — an MCP
server per user, and a watering timer that reaches a phone — were **not
built**. They are carried in §3 exactly as `PHASE-15-HANDOFF.md` §3 wrote
them, and they are still the next thing.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — the authorities. Phase
   15 annotates neither and depends on nothing new from the host.
2. **`docs/CARL-HANDOFF.md`** — the specification. **Phase 15 adds one
   bullet to §4.2** (the Menu pill is a drawer), **two sentences to §4.3**
   (quantity sown defaults to 6; "Start another" on the page a plant lands
   on) and **three sentences to the Phase 9 calendar entry in §14** (a chip
   opens its day; `/calendar.pdf`). Nothing else changes.
3. **`docs/PHASE-15-HANDOFF.md`** §2 (what Phase 14 established: `data://`,
   the drip arithmetic), §3 (**the two candidates, MCP and the timer,
   researched in enough detail to build from**), §4, §5 and §7. All current
   in full; §3 is the build plan for the next phase and is not repeated
   here.
4. **`docs/deploy.md`** — the runbook. **Phase 15 adds no migration and no
   cron change.** The deploy is the file copy of §6.2 and nothing else:
   `.cpanel.yml` copies `app/`, `public/` and `vendor/` whole, so the one
   new class, the one new partial and the one new script ride along. No
   `/setup?key=` is needed.
5. **§8 below is the working agreement**, unchanged, with one addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | **25** (`001`–`025`), 42 tables — Phase 15 added **none** |
| Routes | **110** — Phase 15 added **one**, `GET /calendar.pdf` |
| Source / views | 113 PHP classes (**+1**, `Reports\CalendarSheet`), 59 templates (**+1**, `partials/menu_links`) |
| Tests | **657 tests, 7,062 assertions**, green under `--strict` on MariaDB 10.11 (and PHP 8.4 locally; CI is 8.2) |
| Static CI checks | 8, unchanged |
| Client shell | 35.6 KB gzipped (**+1.5 KB**: the drawer and chip CSS, and `nav.js`) |

Five things, and a sixth found on the way:

**The calendar prints.** `GET /calendar.pdf` carries the page's own month
and filter and returns the grid, a legend, and every worked-out date on the
grid written out in full — under the field sheet's rules, not the report's.
§2.1.

**A chip opens its day.** Every chip on the grid is a link to
`?day=YYYY-MM-DD#day`, and a panel under the grid lists that day's entries
with title, reason and a link to the plant. No script. §2.2.

**"Start another" on the page a new plant lands on.** The same kind of
start — seed start, direct sow, transplant — with the date carried in. Drawn
only on the redirect from a save, by a query marker. §2.4.

**The Menu pill is a drawer.** On every signed-in page the pill opens the
same ten destinations as the main menu's tiles, plus the main menu itself.
§2.3.

**A seed start counts six.** The indoor seed-start form's quantity box
starts at 6, a tray. Direct sow (12) and transplant (1) did not move.

**A link dressed as a button inside a card was unreadable.** `.card a`
outranks `.btn` on specificity and painted the new button's text dark green
on dark green. §2.5. Found by a screenshot, not by the suite (§8).

Pull request: `adamdill-RE/carl#21`, branch
`claude/calendar-plant-ux-tweaks-h0mqwb`, one commit.

---

## 2. What Phase 15 established that Phase 16 should not re-derive

### 2.1 A calendar sheet is a field sheet, not a report

Carl now has **two families of PDF**, and a new print piece has to choose.
`Reports\Document` reads the palette from `tokens.css` and is for
*documents* — the plant and garden reports, things read at a desk.
`Reports\FieldSheet`, and from this phase `Reports\CalendarSheet`, read no
token at all and are for *sheets* — paper that goes out to a bed or up on
a shed wall, through whatever printer is nearest. The design notes are
explicit about that class of page (`docs/DESIGN-NOTES.md` §6): black on
white, no grey, because a 600 dpi mono laser halftones a grey rule into a
broken line and drops a light fill altogether.

So the calendar sheet tells logged from projected by **shape** — a filled
square against an open one — never by tone, which is the same rule the
screen's chips follow for a greyscale print (a fill against a dashed
border). The neighbouring month's days are **named** ("30 Aug") rather than
greyed. Today is a **heavier line**, not a fill. There is exactly one line
weight for everything else. If a reviewer ever asks for the palette on it,
the answer is the field sheet's: you will have broken it.

Three mechanics worth knowing:

- **A4 and Letter from one page**, the field sheet's trick: A4 portrait,
  everything above 270 mm. `AutoPageBreak` is **off**, because FPDF's own
  trigger sits at A4's foot, which is below Letter's. The list under the
  grid breaks *itself*, above `BOTTOM − 10`, and `Header()`/`Footer()` are
  FPDF's own hooks — the footer is drawn at `BOTTOM − 8`, not FPDF's −14 mm.
  A test pins `contentBottom()` under the limit for a crowded month.
- **`wrap()` measures before it draws.** It is the first word-wrap in the
  PDF layer that returns lines rather than drawing them, which is what lets
  a row's height be known before the page-break decision. `MultiCell`
  cannot tell you its height until it has drawn. If the timer or the MCP
  work ever prints a list, reuse this rather than `MultiCell`.
- **A cell that cannot hold its day says so.** Repeats collapse to one line
  with a count, as on the screen; past the cell's capacity the last slot
  reads "+n more" and the sheet counts the cell (`overflowCount()`), so a
  test proves the overflow is visible rather than printed off the edge.

The page and the sheet read the same month through
`CalendarController::assemble()`. What is printed is what was on the
screen, filter and all, for the same statements.

### 2.2 A day is a URL

The ask was that "Transplant" on a chip three months ahead meant nothing.
The upcoming table looks ninety days out; paged past that, the grid had
one-word chips whose only explanation was a `title` attribute, which on a
phone is nowhere.

The answer is a **panel, not a popover**: every chip links to
`calendar?…&day=YYYY-MM-DD#day`, and the page draws a card under the grid
listing every entry on that date — not only the tapped chip's group — with
the kind, "logged" or "worked out", the title, the reason, and links to the
plant and the log form. No script, so it works on the walk; a URL, so it
can be bookmarked and sent; the back button is the way out. The day is
validated against the grid actually drawn (`readDay()`), so a date off the
grid or not a date is simply no panel, and the month's paging links do not
carry it — a picked day belongs to the month it was picked in.

The chips keep their own colours as links: `.card a` would otherwise paint
every projected chip in the link green, which is the one thing a chip's
colour is not allowed to say (§4.2).

### 2.3 The drawer, and why the tiles stayed where they were

The ask offered two fixes: move the main menu's tiles above the MOTD, or
make the top bar's Menu pill "explode" the menu from every page. **The
drawer**, for three reasons:

- Moving the tiles fixes the menu page and nothing else. Reaching Garden
  Actions from a plant page would still be the pill, a page load, and a
  tap. The drawer makes it two taps from any scroll position on any screen.
- On the menu page itself, the Phase 13 pill did nothing — it linked to the
  page it was on. Now it opens the list from the foot of the weather matrix.
- Handoff §4.2 puts the MOTD at the top of the menu because the glance at
  the weather is what the page exists for. The tiles above it would cost
  exactly that glance, on every visit, for every user.

How it is built matters for whoever touches the top bar next:

- It is a **`<details>`** with the pill as its `<summary>`, so it works with
  no script. `nav.js` (the one new script, loaded on every signed-in page)
  only closes it on an outside tap and on Escape, returning focus to the
  pill. The 25_size_test now asks for `>Menu</summary>` and for the drawer's
  rows on every screen.
- The panel is `position: absolute` against the top bar, which is `sticky`
  and therefore a containing block, and it inherits the bar's `z-index`. It
  is drawn in the *page's* colours (`--carl-surface`, `--carl-text`) rather
  than the bar's, because it is a piece of page hanging off the bar. The
  `.topbar a { color: inverse }` rule is overridden for it by
  `.topbar .nav-drawer-panel a`; anything new inside the panel that is not
  an `<a>` needs its own rule.
- **The tiles and the drawer are one partial**, `partials/menu_links`. Two
  copies would be two lists, and the drawer is the one that would go stale
  the next time a screen was added.
- `aria-current="page"` moved from the pill to the drawer's "Main menu" row,
  because `aria-current` belongs on an item in a set and a summary is not
  one. The pill keeps the `is-current` fill on the menu page.

### 2.4 `started=1` is a marker, not a flash

The plant page draws "Start another" only when the URL carries `started=1`,
which `PlantController::create()` puts on its redirect. A second flash
would be spent on the first render and gone when the reader pressed back
from the next form to look at what they had just recorded; a query flag
survives that, and a stale one costs a button that is true anyway. Only
`show()` reads it.

The button carries **the start date and nothing else** into the next form.
The next packet is a different variety, and prefilling the last one is how
a tray of six gets recorded as six of the same thing. Whether the seed
source or the vessel should ride along too is a walk question (§5).

### 2.5 `.card a` outranks `.btn`

`.card a, .timeline a, main p a { color: var(--carl-primary-dark) }` is
(0,1,1); `.btn` is (0,1,0). Any `<a class="btn">` inside a card had its
text painted in the link colour, which on a primary button is dark green on
dark green. Nothing before this phase put a primary button-link in a card,
so nobody found out; the calendar's month arrows are secondary buttons and
had been quietly reading in the link green rather than the text colour.
`.card a.btn` and `.card a.btn-secondary` now restore the button's ink. The
suite cannot see this class of bug — see §8.

---

## 3. Phase 16 — what is left

**Everything in `PHASE-15-HANDOFF.md` §3 is carried unchanged**: the MCP
server per user (§3.1) and the watering timer that reaches a phone (§3.2),
both researched in enough detail to build from, both still the candidates.
Nothing in Phase 15 touched them, and `DripLine::minutesFor()` still has
the number the timer would put on a clock. And everything that §3 carried
forward from Phase 14 and earlier — the tag's unshown history, the
identical named labels, the silent truncations, the cell number, the batch
log form — stands.

Phase 15 surfaces five small ones of its own, none started:

### 3.1 A chip at 320 px reads "Wa…"

Pre-existing, and now visible in a screenshot: on the narrowest phones the
cell is 45 px and the chip's `nowrap; text-overflow: ellipsis` leaves
"Wa…". The day panel is the answer to *what it means*, but a chip that
reads "Wa…" says nothing about being tappable. The cheap fix is a dot
instead of a label under 360 px, with the label in the panel. A design
question as much as a CSS one.

### 3.2 The pest detail has no full stop

`Calendar::pestWindows()` joins the pest's `signs` and "Active here until"
with a space: *"…webbing on the undersides Active here until 11-01."* It was
always so; the sheet's full-width list made it visible. One line, in code
this phase did not otherwise touch.

### 3.3 "Coming up" means two windows

On the sheet, "Coming up" is the projected entries **on the drawn grid**; on
the screen, "Upcoming actions" is **ninety days from today**. A printout of
October and the October screen therefore list different things under nearly
the same word. Deliberate — a printout of a month should be about that
month — and documented in the sheet's own sub-heading, but a reader with
both in hand may ask.

### 3.4 What else "Start another" should carry

Only the date rides along (§2.4). A tray from one packet of one vendor
into one vessel would want the seed source and the vessel too. The walk
decides; the form's `prefillFrom()` whitelist is where a field is added.

### 3.5 The drawer has no close control of its own

Outside tap and Escape close it (script), and the pill toggles it (no
script). A reader with a keyboard who tabs out of the panel leaves it open
behind them. A "Close" row at the foot, or closing on `focusout`, is small.

---

## 4. What must not regress

Everything in `PHASE-15-HANDOFF.md` §4 still applies. Phase 15 adds seven.

1. **`CalendarSheet` reads no tokens and that is correct.** Black on white,
   one hairline weight, shape not tone. §2.1. "Fixing" it to the palette
   breaks it on the printer it exists for.
2. **The sheet breaks its own pages above the Letter limit.** `AutoPageBreak`
   stays off; `comingUp()` breaks at `BOTTOM − 10`; the footer is at
   `BOTTOM − 8`. The heavy-month test in `23_calendar_test.php` pins
   `contentBottom()` under the limit and the page count to the file's.
3. **A chip keeps its own colour as a link.** The `.card a.cal-chip.cal-done`
   and `.cal-ahead` rules exist because `.card a` outranks the chip classes.
   Removing them paints projected chips green — the one thing a chip's colour
   must not say — and the greyscale-print promise in the CSS comment breaks
   with it.
4. **The tiles and the drawer are one partial.** A destination added to one
   and not the other is the drift `partials/menu_links` exists to prevent.
   `25_size_test.php` asks every screen for `>Garden Actions` in the drawer.
5. **"Start another" appears only on the create redirect.** The test pins
   both halves: with `started=1` the card is drawn, without it the page is
   the plant's report and nothing else.
6. **Quantity defaults are 6 / 12 / 1** for seed start / direct sow /
   transplant, pinned in `03_flow_test.php`. The tag picker's "tick the next
   N" reads the same default.
7. **`>Menu</summary>` on every signed-in screen, `nav-menu is-current` on
   the menu page, and nothing behind `nav-menu` for a stranger** — the Phase
   13 promise, re-pinned for the new element.

---

## 5. Owner actions outstanding

The list in `PHASE-15-HANDOFF.md` §5 stands in full — apply migration 025,
read `allow_url_fopen` off `/status`, enter the emitter figures on a real
zone — and none of it was done from here. Phase 15 adds four, all on the
walk:

1. **Deploy is the file copy alone.** No `/setup?key=`, no cron change.
2. **Print `/calendar.pdf` on the printer that will actually print it.**
   Check that today's heavier box survives, that "30 Aug" in italics reads
   as a neighbouring day, and that a "+n more" cell is legible. The sheet
   was rasterised and looked at during the phase (§7), never printed.
3. **Open the drawer in sunlight.** Its edge is `--carl-border`, the
   palette's one advisory (1.53:1), and its shadow is `--carl-shadow`. If
   the panel does not separate from the page outdoors, that is a token
   question for Claude Design (§6), not a hex to nudge in `carl.css`.
4. **Enter a real tray with "Start another"** — six varieties, back to back
   — and say whether the seed source and the vessel should carry over
   (§3.4), and whether the date carrying over ever surprised you.

---

## 6. Claude Design outstanding

Unchanged from `PHASE-15-HANDOFF.md` §6. Phase 15 adds CSS but no colour
and no token: the drawer (`.nav-drawer*`), the chip-as-link and picked-day
rules, `.cal-tools`, `.start-another`, and `.card a.btn`. Three things to
put in front of Claude Design when the next brief goes out:

- **The drawer is Carl's first overlay.** It uses `--carl-surface` on top
  of page content with `--carl-shadow`, which was tuned for a card on the
  page ground, not for a panel over text. If it reads too weak, the answer
  is a second shadow token, not a stronger value inline.
- **The picked day borrows `--carl-info-soft`** because `--carl-primary-soft`
  is the logged chip's own fill and a chip would vanish into it. A dedicated
  selection token is theirs to decide.
- **The 320 px chip** (§3.1) is a design question: a dot, a letter, or the
  label, under a thumb's width.

---

## 7. Where the bodies are buried

Everything in `PHASE-15-HANDOFF.md` §7 still applies. Phase 15 adds seven.

- **The pill is not an `<a>` any more.** `.topbar a { … }` does not reach
  it; `.topbar .nav-menu` does, and `.nav-drawer[open] > summary` is the
  open state. `list-style: none` *and* `::-webkit-details-marker { display:
  none }` are both needed to lose the triangle; drop either and one engine
  draws it.
- **`assemble()` runs the same statements for the page and the PDF.** The
  calendar's screen tests do not count statements. If a statement-count
  assertion is ever added to `/calendar`, `/calendar.pdf` must match it,
  and the route-walk test in `03_flow_test.php` already smoke-tests the PDF
  route with every other literal GET.
- **The sheet's `×` is U+00D7**, which is in Windows-1252 and survives
  `t()`. The view uses `&times;`. A different glyph — a real multiplication
  sign is fine, an emoji is not — would print as `?`.
- **`Calendar::monthName()` is shared.** The view no longer computes
  "September 2026" itself; the controller hands it over for both outputs.
- **`.card a.btn-secondary` changed the month arrows' colour** from the
  link green to the text colour. That is the intended reading of a secondary
  button; if anyone liked the green, it was an accident of §2.5.
- **`nav.js` is cached by `sw.js`** like every other script under
  `/assets/js/`, and busted by the `?v=` stamp like every other. Nothing to
  do; noted because it is the first script loaded on *every* page.
- **The sheet was checked by rasterising it, off-repo.** No PDF rasteriser
  is committed or required. The phase installed `pymupdf` locally, built a
  September with fixture plantings, a region, a window and a pest through
  `Calendar::build()` into `CalendarSheet`, and looked at the PNG; the
  screens were screenshotted with Playwright against `php -S … dev-router.php`
  and a user the suite had made. Neither tool is in the repo and neither
  should be; the suite's `contentBottom()` assertions are what CI has.

---

## 8. Working agreement

Unchanged from `PHASE-15-HANDOFF.md` §8, including every earlier phase's
addition. One addition, from §2.3 and §2.5:

> When the ask offers two designs, pick one, and write down why the other
> lost where the next person will read it — in the layout's own comment,
> not only in a handoff.

And the Phase 10 test, answered "no" once more — this time for a button
whose text was the same colour as its background:

> **Would anybody find out?**

The suite asserts on markup and never on paint. A CSS change that passes
every test can still be unreadable, and the only way this one was found was
a screenshot taken on purpose. When a phase changes `carl.css`, look at it
in a browser before pushing, at 380 px and in dark mode, and say in the
handoff that you did.
