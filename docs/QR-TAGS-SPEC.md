# Carl — QR plant tags

**Status: BUILT, Phase 8 (2026-09-01).** Written 2026-08-31 against the Phase 5
handoff; built against Phase 7, after the planting split its §10.1 asked for.
Read `docs/hosting.md` first; it overrides this file.

**Read §12 before reading anything else as a description of the code.** It is
the seven places the build diverged from this document, and why. Everything
else below is left exactly as written, including the parts §12 corrects — a
spec edited to match what was built stops being a record of what was decided.

5a and 5b (§11) are both in. Deferred, and still deferred: an in-app scanner
(never — §7), NFC (§1.6), and a scan log (§3.1).

A physical tag that lives with a plant from the seed cell to the end of the
season, carrying a code that a phone camera turns into a one-tap logging
screen for that plant.

---

## 0. Why this is worth building

Logging a watering today costs: sign in → View Plants → find the right one
among forty → Log → choose the type → submit. Six interactions to record a
fact that took three seconds to perform, done standing in a garden holding a
hose.

A scan collapses that to two: point the camera, tap "Watered".

Everything else in this document is in service of that, and every design
decision below is made in favour of the person standing in the mud rather
than the person at the desk.

---

## 1. The physical tag

### 1.1 What the requirement actually is

| | |
| --- | --- |
| **Lifespan** | Seed start (Feb, indoors, wet, under lights) → hardening (outdoors, wind) → in-ground (sun, rain, irrigation, soil splash) → end of season. **6–9 months**, most of it in UV. |
| **Fits a seed cell** | A 72-cell 1020 tray has ~1.5 in cells. The stake may be at most **1 in wide**, and must not shade the seedling. |
| **Carries a code** | See §2. The scannable area needs **≈ 24 mm of tag width**. |
| **Moves with the plant** | The same physical object goes into the ground at transplant. No re-labelling step. |
| **Batch printable at home** | Forty tags in one pass, not forty trips to a label maker. |
| **Cheap** | Target ≈ $0.20 per plant, reusable across seasons. |

### 1.2 The constraint that eliminates most of the market

**A 5/8 in (16 mm) nursery stake cannot carry a scannable code.** That is the
standard plant-label width and it is what almost every 500-for-$12 pack on
Amazon is. After a 4-module quiet zone there is no room left.

**1 in (25.4 mm) is the minimum viable stake width**, and it is also close to
the maximum that fits a seed cell. The whole design sits in that ~9 mm band.

### 1.3 Recommended kit

Two parts: a rigid stake, and a printed label stuck to it.

**Stake — [GOETOR Plastic Plant Labels, 6 in × 1 in, 100 pack](https://www.amazon.com/Plastic-Labels-Nursery-Waterproof-Decoration/dp/B0915TZ7V8)**
(rigid PVC, ~$11). Alternative with the same geometry:
[SumDirect 100 pcs 1 × 6 in](https://www.amazon.com/SumDirect-Plastic-Waterproof-Markers-Nursery/dp/B00UT3A3AA).
The 1 in width is the whole reason for choosing these over the cheaper
5/8 in packs.

**Label — choose by the printer you own:**

| Printer | Product | Why |
| --- | --- | --- |
| **Laser** | [Avery UltraDuty GHS 60517, 1 × 2½ in, 600 labels](https://www.amazon.com/Avery-UltraDuty-Waterproof-Resistant-60517/dp/B07286CF3D) (~$50) | Polyester film, not paper. Waterproof, UV resistant, permanent adhesive rated to BS5609 §2 — 90 days in seawater. This is a chemical-drum label; a tomato bed is a gentle environment by comparison. 24 per sheet. |
| **Laser *or* inkjet** | [Avery Easy Align Self-Laminating ID labels 00757, 1‑1/32 × 3½ in, 250 pack](https://www.amazon.com/Avery-00757-Self-Laminating-Labels-Inkjet/dp/B00VS69HC2) (or [00753, 50 pack](https://www.amazon.com/Avery-Professional-Self-Laminating-Resistant-00753/dp/B00XOJ9SIE) to trial) | Half the label is printable white, half is a clear flap that folds over and seals the print under polyester. **The ink never touches water**, so ordinary inkjet output survives. Pick this if there is no laser printer. |

Cost per tag: label ≈ $0.09, stake ≈ $0.11. **≈ $0.20**, and the stakes are
reusable for years.

### 1.4 Practical notes that will otherwise be learned the hard way

- **Wipe each stake with isopropyl alcohol before applying.** PVC stakes carry
  a mould-release film from manufacture; adhesive on it lifts in a month.
- **A 1 in label on a 1 in stake is flush at both edges**, which is the
  classic peel-start. Burnish the edges hard with a thumbnail. If it still
  lifts, switch to the
  [1.5 × 3.75 in UltraDuty](https://www.amazon.com/UltraDuty-Waterproof-Rectangle-Printable-Printers/dp/B098BNCLRY)
  and **wrap it** — 1 in on the face, the remaining ½ in folded round onto the
  back, which seals both vertical edges against the stake.
- **Leave a write-on band** on the printed design (§5.3). On seed-starting day
  you want to scrawl "Cherokee Purple" with a garden marker before Carl has
  ever heard of the plant.
- **Print at 100 % scale.** "Fit to page" silently shrinks the sheet, which
  both misaligns every label and shrinks the code below the size §2.3 sizes
  it for. The sheet carries a calibration rule for exactly this reason (§5.2).

### 1.5 What not to buy

- **Direct-thermal label printers** (Rollo, Munbyn, Dymo LabelWriter stock).
  The print blackens with heat and fades in sunlight. A summer in a garden is
  the failure case these are worst at.
- **Dye inkjet on paper labels**, weatherproof-branded or not. Gone in two
  waterings unless sealed under laminate.
- **5/8 in nursery stakes.** §1.2.

### 1.6 The alternative worth knowing about

**Brother P-touch PT-D610BT + 24 mm TZe laminated tape.** Prints QR codes
natively; TZe tape is six-layer laminated with the print sealed inside, and
is the most durable thing in this document by a distance. It also sticks
straight onto any stake with no sheet-alignment problem.

Rejected as the primary recommendation because it prints **one label at a
time** through an app or a desktop mail-merge, which is the wrong shape for
"print thirty tags in January", and because it is ~$100 of hardware to solve
a problem a sheet of labels solves for $50. Worth it if one is already owned.

**NFC** deserves a sentence: ~$0.30 a tag, no line of sight, works in the
dark and through mud, and genuinely nicer to use. Rejected because it fails
in exactly the way that matters — a QR that will not scan still has a
six-character code printed under it that a human can read and type (§2.4);
an NFC tag that has failed is a blank sticker. Possible add-on later for
perennials.

### 1.7 Acceptance test before buying a hundred

Make five tags. One in full sun outdoors, one half-buried in wet soil, one
under grow lights, one in a car dashboard, one indoors as a control. Scan all
five weekly for four weeks. Then buy the rest.

---

## 2. The code, and what decides its physical size

This is the engineering heart of the feature. The tag width is fixed at
25.4 mm by §1.2, so everything else has to fit inside it.

### 2.1 The URL

```
HTTPS://WWW.RESHIFTMANAGER.COM/CARL/T/AB7K4M
└────────────── 38 chars ─────────────┘└ 6 ┘     = 44 characters
```

Six characters of **Crockford base32** — `0123456789ABCDEFGHJKMNPQRSTVWXYZ`,
which omits I, L, O and U precisely so that a human reading a faded tag
cannot confuse them. 32⁶ ≈ 1.07 billion codes. Generated randomly, never
sequentially, with a retry on the unique index.

### 2.2 Uppercase is not a style choice — it buys a smaller code with better error correction

QR's **alphanumeric mode** covers `0-9 A-Z space $ % * + - . / :` and packs
two characters into 11 bits. **Byte mode** costs 8 bits per character. A
lowercase URL forces byte mode. DNS is case-insensitive and we choose the
path, so an all-uppercase URL is free.

| Payload | Mode | Error correction | Version | Grid |
| --- | --- | --- | --- | --- |
| `https://…` (mixed case) | byte | M (15 %) | 4 | 33 × 33 |
| `https://…` (mixed case) | byte | L (7 %) | 3 | 29 × 29 |
| `HTTPS://…` (uppercase) | alnum | M (15 %) | 3 | 29 × 29 |
| **`HTTPS://…` (uppercase)** | **alnum** | **Q (25 %)** | **3** | **29 × 29** |

The last row is the choice. Uppercase pays for the jump from 15 % to 25 %
damage tolerance *and* keeps the grid a version smaller than the obvious
lowercase encoding. A tag that will be rained on, splashed with soil and
partly obscured by a leaf is precisely what a 25 % correction level is for.

Verified by capacity arithmetic rather than by memory: version 3 at level Q
carries 34 data codewords → 34 × 8 − 13 bits of header = 259 bits →
⌊259 ÷ 11⌋ = 23 character pairs plus a 9-bit remainder = **47 alphanumeric
characters**. 44 fits, with three to spare.

**Consequence for the router.** `/t/{code}` must match case-insensitively —
constraint `[0-9A-Za-z]+`, `strtoupper()` before lookup — while every link
Carl *emits* into a QR is uppercase, including the `/T/` path segment.

### 2.3 The physical size that falls out

- 29 modules + a 4-module quiet zone each side = **37 modules across**.
- Tag face 25.4 mm, less ~0.7 mm each side for application slop = **24.0 mm**.
- **0.649 mm per module.** Symbol proper ≈ **18.8 mm**.

Two sanity checks on that number:

- ISO 18004's practical floor for print is ~0.25 mm per module. This is 2.6×
  it. A 600 dpi laser puts ~15 dots in each module, so the printed edge is
  essentially exact.
- Common advice says "20 mm minimum, 25 mm safer". That advice is about
  *scanning a poster at arm's length*. This tag is read deliberately at
  15–20 cm. **The binding constraint on a phone is minimum focus distance,
  not sensor resolution** — a 12 MP camera at 15 cm resolves ~0.05 mm per
  pixel, thirteen pixels per module. The failure mode to warn users about is
  holding the phone *too close* to focus, not too far.

Nonetheless §1.7's scan test is the acceptance criterion, not this
arithmetic.

**If 18.8 mm proves marginal in the field, three levers, in order:**

1. **A short domain.** `HTTPS://CARL.GARDEN/T/AB7K4M` is 28 characters, which
   fits **version 2 (25 × 25) at level Q** — 0.727 mm modules on the same tag,
   12 % larger, for the price of a domain registration and no code change.
   This is by far the biggest lever available and it is not an engineering
   task. Dropping just the `WWW.` saves four characters and changes nothing.
2. **A wider tag** for in-ground use — but that breaks the seed-cell
   constraint and therefore the one-tag-for-life property. Only worth it if
   §1.2 turns out to be wrong.
3. **Drop to level M.** Least preferred: it trades the mud tolerance that is
   the reason for the whole encoding choice.

### 2.4 The human-readable fallback

The six characters are printed under the code in a large mono font. When the
QR is caked in soil — and one will be — the recovery path is reading six
unambiguous characters off the tag and typing them into a "Find a tag" box.
This is why the alphabet excludes I, L, O and U, and it is why the code is
short.

---

## 3. What the code identifies

Three candidates: the planting, a position in a garden, or **the physical tag
itself**. This is the decision everything else hangs off.

**The code identifies a reusable physical tag, bound to a planting.**

Why:

- **Printing is decoupled from planting.** You print a stack of blank tags in
  January, at a desk, next to the printer. In April you are in the garage
  with a tray of wet soil and no printer, and you need a tag *now*. A
  planting-specific tag cannot exist until the planting does, which forces a
  trip indoors in the middle of the one task where that is most annoying.
- **Tags outlive plantings.** A hundred stakes are a one-time purchase that
  gets reused every season. That only works if the code is not welded to one
  plant.
- **The binding is data worth keeping.** "This tag was Cherokee Purple in
  2026 and Provider beans in 2027" is a real fact about a real object, and it
  means an old photograph of a tag does not lie about what it was.

### 3.1 Data model — migration `016_qr_tags.sql`

Two tables, plus two columns on `user`: the label-stock preference (§5.3) and
the tagging-session flag (§6.5). `utf8mb4_unicode_ci`, like everything else.

```
qr_tag
  id            INT UNSIGNED PK
  user_id       INT UNSIGNED  FK user ON DELETE CASCADE
  code          CHAR(6)       UNIQUE   -- Crockford base32, uppercase
  batch_id      INT UNSIGNED NULL      -- which printed sheet it came from
  printed_at    DATETIME NULL
  retired_at    DATETIME NULL          -- physically destroyed / lost
  created_at    DATETIME
  KEY (user_id, retired_at)

qr_tag_binding
  id            INT UNSIGNED PK
  tag_id        INT UNSIGNED  FK qr_tag
  planting_id   INT UNSIGNED  FK planting ON DELETE CASCADE
  bound_at      DATETIME
  unbound_at    DATETIME NULL
  KEY (tag_id, unbound_at)             -- the live binding is unbound_at IS NULL
  KEY (planting_id, unbound_at)
```

`code` is globally unique because it appears in a URL that must resolve
before the user is known. `user_id` scopes it after resolution, through the
repository base class like every other table.

**No scan log in v1.** A row per scan is a write on every page view for a
fact nothing yet reads. The event the user records *is* the trail. Revisit if
"when did I last walk this bed" turns out to be a question worth answering.

---

## 4. Generating the code: no library, no image, no third-party call

**Under no circumstances call a QR-image web service.** It would put a
third-party call on the request path — forbidden outright by
`PHASE-3-HANDOFF.md` §5 — and it would hand every plant URL in the account to
a stranger.

### 4.1 The encoder

`Carl\Qr\Encoder`, producing a boolean matrix. **Scoped deliberately**:
alphanumeric mode with a byte-mode fallback, error correction M and Q,
versions 1–4 only. That is a small fraction of ISO 18004 — no Kanji, no
versions 5–40 and therefore no version-information block, four alignment
pattern layouts instead of forty. Realistically ~500 lines including the
GF(256) Reed–Solomon.

This is in character for a repository that hand-rolled an SMTP client rather
than take a dependency, and it is *more* attractive than vendoring here: the
maintained PHP QR libraries are all Composer packages with PSR-4 trees, and
this project has no Composer (`hosting.md` §3).

**How it is tested.** Generate the module matrices for a set of known
payloads offline with an independent implementation, check the fixtures in,
and assert the encoder reproduces them bit for bit. A PHP decoder does not
exist to round-trip against, so an external oracle captured once is the
honest test. Include at minimum: the shortest and longest payload that fits
version 3 level Q, one that forces a version bump, and one that forces the
byte-mode fallback.

### 4.2 On screen — inline SVG

One `<svg>` with a single `<path>`. No image request, no file, nothing added
to the 150 KB client shell budget, sharp at any zoom, and no JavaScript, so
the inline-script rule (`PHASE-4-HANDOFF.md` §3.2) is untouched.

**Colour.** A QR must be near-black on near-white to scan; it cannot follow
the palette. Phase 5 handoff §4.5 says `tokens.css` is the only file that
names a colour, and that rule should be kept rather than broken: add
`--carl-qr-ink` and `--carl-qr-paper` to `tokens.css` **with a comment saying
these are contrast-critical, not palette, and must not be themed.** A
designer who tints them to brand green has silently broken every tag in
every garden, and nothing will report it.

### 4.3 On paper — FPDF rectangles, not an image

Draw each module run with `FPDF::Rect($x, $y, $w, $h, 'F')`. Merge runs along
each row first, so a 29 × 29 code costs ~150–250 calls rather than 841.

Three things this buys:

- **Vector output.** The code is exact at whatever DPI the printer has, with
  no resampling — which matters when the module is 0.65 mm.
- **No GD.** The `memory_get_peak_usage()` trap of Phase 5 handoff §2.1 does
  not apply to label printing at all, because nothing decodes an image.
- **No temp files**, on a host where the writable directories are few and
  the deploy does not own them.

Fill only, never stroke: `SetLineWidth` is irrelevant with `'F'`, but a
stroked rect would bleed adjacent modules together and is the obvious way to
get this subtly wrong.

Check any new method name on `Carl\Reports\Document` against
`get_class_methods('FPDF')` before adding it — PHP method names are
case-insensitive and FPDF is a wide surface (Phase 5 handoff §7).

---

## 5. Printing sheets

### 5.1 Two print jobs, and only one of them is common

**This is the flow question, and the answer is that blank sheets are always
printed whole.**

| | **Blank sheet** (Phase 5a) | **Named label** (Phase 5b) |
| --- | --- | --- |
| What it is | A sheet of anonymous codes | A label for one planting, with its name on it |
| How many | **Always a full sheet**, or several | Exactly as many as there are plantings queued |
| When | Once, in January, at a desk | Occasionally, when the queue is worth a sheet |
| Partial sheets | **Never** | Yes — and this is the only place they occur |

The pre-printed pool (§3) exists precisely so the common job has no count to
choose. You print a sheet, the codes go in a box, and you take one out
whenever a plant needs a tag. The form asks **how many sheets**, not how many
tags, and there is no start-position control on it at all.

**The first draft of this section got that wrong.** It carried a
start-at-position control on the blank-sheet path, reasoning that "a sheet
holds 24 and you will never want exactly 24" — which is true of *named*
labels and false of blank ones, because blank ones are never printed to
demand. The control is real; it belongs on the 5b path, and it is one more
reason that path is 5b.

**A useful property falls out of this: the physical sheet is its own state.**
Nothing needs to track which labels have been peeled, because you can see the
empty positions. Carl tracks bound and unbound, which is a different question
and is the one the scan answers.

### 5.2 Activation, and it works from either end

A printed tag is inert until it is bound to a planting. "Activate" and "bind"
are the same act; the tables use *bind* (§3.1).

Both directions must work, because both happen:

- **Tag first** — the seed-starting case. Stick a tag in the cell, scan it,
  Carl says the tag is not assigned, choose a planting or start a new one
  (§6.2). This is the one that matters and it is why the pool exists.
- **Planting first** — the desk case. You planned the season in Carl in
  February. At the foot of Start a New Plant, and on any plant's page:
  "assign a tag" — scan one, or type the six characters. Same binding, other
  direction.

### 5.3 The label stock preference

Both recommended stocks are first-class, because the choice follows the
printer the user owns (§1.3) and neither is right for everyone.

```
Carl\Domain\LabelStock          the vocabulary + geometry, one entry per SKU
  avery_60517   1 × 2½ in polyester, laser only          most durable
  avery_00757   self-laminating, laser or inkjet         works with any printer

user.label_stock  VARCHAR(32) NOT NULL DEFAULT 'avery_00757'
```

Three decisions in that:

- **A Domain class, like `EventType` and `SoilType`.** It carries the display
  name, the printer requirement, the per-sheet count and the layout constants
  of §5.5. Adding a third stock is then one entry, and every screen that lists
  stocks picks it up.
- **`VARCHAR(32)` validated in code, not an `ENUM`.** The repository uses both
  — `onboarding_step` is the VARCHAR precedent — and the deciding fact is that
  the list will grow. An `ENUM` makes adding a label stock an `ALTER TABLE`,
  and migrations are immutable, so it would cost a migration every time.
- **The default is the self-laminating stock**, because it is the one that
  cannot fail on an unknown printer. A user who has never opened the setting
  gets the safe answer; a user with a laser printer changes it once.

A column on `user`, following `email_digest_enabled`, rather than a settings
table — the repository does not have one and this does not justify inventing
it. Set in Lists or a small Preferences screen, shown on the print form as a
**per-print override**, so trying the other stock for one sheet does not mean
changing a setting and changing it back.

### 5.4 Routes

```
GET  /tags                       the tag pool: printed, bound, free, retired
GET  /tags/print                 how many sheets, which stock
POST /tags/batches               mint one sheet's worth × n → redirect
GET  /tags/batches/{id}.pdf      render that batch — idempotent, re-printable
POST /tags/batches/{id}/retire   a lost or ruined sheet, back out of the pool
GET  /tags/{code}/label.pdf      one named label for a bound tag (5b)
```

The mint is a POST because it writes; the render is a GET because **a paper
jam must not cost you thirty codes**. `stock_sku` is recorded on the batch, so
the render stays a pure function of the batch row and reproduces the identical
sheet forever — including after the user has changed their stock preference,
which is exactly when a re-render would otherwise come back subtly wrong.

**Retire covers the sheet you lost**, which under a pre-printed pool is a real
event rather than a hypothetical: twenty-four codes that will never be
scanned, cluttering the pool count. Retiring is not deleting — the rows stay,
so a tag that turns up in a drawer next spring still resolves and can be
un-retired.

### 5.5 `Carl\Reports\Document` cannot be reused, and the reason will bite

**`Document` is hard-coded to A4** — `parent::__construct('P','mm','A4')`, with
`MARGIN = 15.0` and `WIDTH = 180.0` as private constants derived from it.
**Every Avery template is US Letter.**

A4 is 210 × 297 mm; Letter is 215.9 × 279.4 mm. Render a Letter template onto
A4 and every column sits ~3 mm off horizontally while the sheet runs 17.6 mm
short vertically — the bottom row falls off the page. Nothing errors. You find
out when you hold the print against a real label sheet.

So the label sheet is a **sibling** of `Document`, not a subclass of it:

- `parent::__construct('P', 'mm', 'Letter')` — FPDF 1.86 has `letter` in
  `StdPageSizes` (612 × 792 pt), so this needs no patching.
- `SetMargins(0, 0, 0)` — labels are absolutely positioned from the template's
  own origin, not laid out in a text flow.
- **`SetAutoPageBreak(false)`.** With it on, a label positioned near the foot
  of the sheet silently throws itself onto a second page.
- Empty `Header()` and `Footer()`. `Document` draws a running header and
  "page n of m"; on a label sheet those print across the labels.

The two classes share `t()` and the module-drawing routine of §4.3 and nothing
else. Do not try to parameterise `Document`'s page size — `MARGIN` and `WIDTH`
are consts used in twenty places and every one of them assumes a text column.

### 5.6 Layout constants, and how they get verified

Per SKU, on the `LabelStock` entry: page size, origin of the first label,
label width and height, column and row pitch, columns, rows, labels per sheet.
**Taken from each manufacturer's published template, never from arithmetic
here** — Avery's pitches are not always label width plus gutter, and a 0.5 mm
error compounds across eight rows into a visibly crooked sheet.

Two checks, both cheap, both catching failures that are otherwise invisible
until the labels are on stakes:

- **A 100 mm calibration rule** across the foot of every sheet, labelled "this
  line must measure 100 mm". Scaled printing is the single most likely cause
  of a batch that will not scan.
- **A registration test sheet** — the same layout with position outlines and
  numbers instead of codes, meant for plain paper. Hold it against a real
  label sheet up to a window before committing $2 of polyester. This is the
  acceptance test for this section's constants; it is not optional the first
  time a SKU is added, and **it is how both stocks get verified** rather than
  one being trusted because the other worked.

### 5.7 How the user prints it, which is where scaling actually happens

**Download the PDF and print it from a PDF viewer with scaling set to
"None" / "Actual size" / 100 %.** Chrome's built-in print preview defaults to
*Fit to printable area*, which shrinks the page by a few per cent to clear the
printer's unprintable margin. That is enough to both misregister every label
and take the module below the size §2.3 sized it for.

Say this on the print screen, next to the download button, in words — not in a
help page. The calibration rule is the backstop, not the instruction.

### 5.8 Minting: one statement, and the collision that will never happen until it does

Generate the codes in PHP, then **one multi-row `INSERT`** for the whole batch.
The codes are already in hand, so nothing needs reading back — which matters
because MySQL has no `RETURNING` (`hosting.md` §2.2) and the database is on
other hardware (§9).

On a duplicate-key failure, regenerate the colliding code and retry, bounded
to a few attempts. At 32⁶ and a pool of a few hundred this will not fire in
this application's lifetime, and it must still be written, because the
alternative failure is a 500 on the one screen that mints things.

### 5.9 What is on a label

**Blank tag** (the batch sheet): the code, the six characters beneath it in a
large mono font, one small line of text so that a stranger who finds a stake
knows what it is, and **a blank write-on band** for a garden marker.

**Named label** (5b, for a tag already bound): the same code — the *same*
code, this is a reprint, not a new tag — plus the plant name, the variety and
the start date. Applied over or beside the blank one. This is the path that
carries the queue and the start-at-position control of §5.1.

The two-stage split is the point. Blank tags get you through seed-starting
week; named labels get applied in June when you are back at a desk and can
print a sheet's worth for everything started that spring. Plant names go
through `Document::t()`: FPDF's core fonts are Windows-1252 and a curly
apostrophe in a variety name is silent mojibake (Phase 5 handoff §7).

## 6. The scan: `GET /t/{code}`

### 6.1 The token is an identifier, not a credential

**`Route::USER_ACCESS`, not `PUBLIC_ACCESS` and not `TOKEN_ACCESS`.**

A tag on a stake in a front garden is readable by anyone walking past and
photographable from the pavement. A bearer token there would let a stranger
read the owner's whole garden history, or log a harvest that never happened.

`Route::TOKEN_ACCESS` exists for exactly one route and its docblock says why:
the unsubscribe link is safe because *"a forged request achieves precisely
what the link it forged was for."* That reasoning does not transfer here, and
the docblock already says not to reuse it.

**This costs nothing in practice.** `Carl\Auth\TokenStore` issues a 30-day
rotating `CARLAUTH` cookie (`hosting.md` §8.3), so the gardener's own phone
is signed in essentially always. Requiring auth is free for the person it is
meant to serve and total for everyone else.

**Dependency:** a signed-out scan needs login to return to where it was
going. If `?next=` does not already exist on `/login`, it must be added, and
it must accept only same-app relative paths — an open redirect on a URL
printed on a physical object that anyone can photograph is worse than the
usual kind.

### 6.2 What the scan lands on

| State | Response |
| --- | --- |
| Code unknown | **404.** Not "invalid code" — a 404 leaks nothing about which codes exist. |
| Not signed in | Login, then the target. |
| Tag belongs to another user | **404.** The same page as unknown, deliberately. |
| Tag unbound | **Bind screen:** "Tag AB7K4M isn't assigned yet." Every *untagged* living planting, most recent first (§6.4), plus "Start a new plant with this tag." |
| Tag bound, plant living | **The field screen** (§7). |
| Tag bound, plant ended | Read-only season summary, plus "Release this tag." |

Not `/plants/{id}`. That is the report page with charts — the right page at a
desk, the wrong page in a garden.

### 6.3 Budget

`/t/{code}` must cost **no more than three statements**: the tag joined to
its live binding and the planting, the event list for the header, and
whatever the action list needs. The database is on separate hardware and
latency scales with statement count (`hosting.md` §9). This is a page that
gets hit forty times in one walk around a garden.

### 6.4 The bind screen lists *untagged* plants, not recent ones

The first draft of §6.2 said the bind screen offers "a list of recent living
plantings". **That is wrong**, and it breaks precisely the case this section
now exists for: a tomato that went in the ground in May has no tag and is not
recent, so a recency filter hides the plant you are standing in front of.

The predicate is **untagged**, and recency is only the *sort*:

```
living plantings for this user
  with no qr_tag_binding where unbound_at IS NULL
  ORDER BY start_date DESC, id DESC
```

Recently started first, because the common case is tagging something you sowed
this week — but nothing is filtered out, so the May tomato is on the list, four
screens down, and a search box finds it by name when the list is long.

**Nothing in the model ties binding to a life stage.** `bound_at` is a
timestamp and no more; a tag bound to a `yielding` plant behaves exactly like
one bound at `seed_started`. Tagging an established plant is not a special case
in the code and must not become one in the UI — the only question ever asked is
whether the plant already has a tag.

The screen therefore offers three things, in this order:

1. **The untagged list**, most recent first, searchable.
2. **Start a new plant with this tag** — the sow-as-you-go case, which lands on
   Start a New Plant with the tag bound on submit.
3. **Assign to a plant that already has a tag** — behind a confirmation, because
   it silently unbinds the old tag. This is the replacement-for-a-destroyed-tag
   path, not an everyday one.

### 6.5 Tagging a stack of tags in one pass

Twelve plants and twelve tags is the seed-starting reality, and scan → pick →
scan → pick is twelve list-picks on a phone with wet hands. Worth removing.

**A tagging session inverts it: Carl holds the cursor and the scan is the
confirm.**

1. From the Tags screen or the untagged list: **Start tagging**.
2. Carl shows "Next: Cherokee Purple — scan a tag."
3. Scan. The tag binds to that plant, the screen says
   **"Bound to Cherokee Purple. Undo · Assign to a different plant"**, and names
   the next untagged plant.
4. Scan the next tag. Repeat until the list is empty or you stop.

Twelve scans, zero taps. The scan is a full page load — the camera navigates to
`/t/{code}` — so the session cannot live in the page. It lives on `user`:

```
user.tagging_started_at   DATETIME NULL     -- session active while unexpired
```

Three properties that make this cheap and hard to get wrong:

- **Zero extra statements.** `Auth::user()` already runs a `SELECT` over the
  user row on every request (`app/src/Auth/Auth.php`), so the session flag rides
  along in a query that was happening anyway. §6.3's three-statement budget
  survives intact.
- **The cursor is computed, never stored.** "Next untagged plant" is the §6.4
  query with a `LIMIT 1`. A stored pointer would go stale the moment a plant got
  tagged by another route or ended; a computed one cannot. It also means the
  session survives the phone being locked, the browser being closed, and the
  page being reloaded — none of which is true of anything held client-side.
- **An expiry, an explicit stop, and a visible banner.** Two hours, a **Stop
  tagging** control, and a persistent "Tagging · 4 bound · stop" strip on every
  page while it runs. A silent binding mode that outlives the potting session is
  a way to attach a tag to the wrong plant a week later and never find out.

Two behaviours that make it safe to use quickly:

- **Optimistic bind with undo, not confirm-then-bind.** For a repetitive
  physical task, confirming every scan is the whole cost you were removing.
  Undo is one tap and only wanted when something went wrong.
- **Scanning an already-bound tag does not consume the cursor.** It shows that
  plant's field screen and leaves the session where it was. So scanning the same
  tag twice, or scanning a tag already on a stake to check what it is, is
  harmless mid-session.

**There is no skip.** Skipping needs per-session state — a list of deferred
plantings — which is a table, for a case the confirmation screen's "assign to a
different plant" already covers in one tap. If the order is wrong often enough
to hurt, that is evidence for the worklist, not a reason to build it now.

When the untagged list empties, the session ends itself and says so. When it is
empty to begin with, **Start tagging** is not offered — the answer there is
"start a new plant with this tag."

**One caveat, and it is Q1 wearing a different hat.** All of the above assumes
your twelve plants are twelve *plantings*. If they are one planting with
`quantity_initial = 12` — twelve cells of one variety — then the question is not
how to scan twelve tags but whether twelve tags may point at one planting, and
that is §10.1.


---

## 7. The field screen

The payoff. Everything on it assumes: one hand, sunlight on the screen, mud,
and no patience.

- **Header:** plant name, variety, days since start, current state, the tag
  code.
- **One row of large tap targets**, being exactly the actions the plant's
  current state allows — `PlantingState` already computes this and
  `LogController` already enforces it. Water, Photo, Note, Harvest, Pest,
  Died.
- **One tap records one event, dated today, and returns to this screen** with
  a confirmation. No date picker. No dropdown. The defaults are "today, this
  plant", which is right ~95 % of the time because you are standing next to
  it.
- **Photo is one tap:** `<input type="file" accept="image/*"
  capture="environment">` opens the camera directly. Photos already exist;
  this makes them free.
- **Secondary, below the fold:** the full plant page, the detailed log form
  (`/log/{id}`, already built, for backdating and narrative), change tag.

There is no in-app scanner and there should not be one. The phone's camera
app already reads QR codes from the lock screen, it is better at it than
anything that could be vendored inside the 150 KB budget, and it needs no
JavaScript.

---

## 8. Integration points with what Phase 5 is already doing

- **End Growing Season** (Phase 5 handoff §3.3) should offer **"release the
  tags"**, returning every tag on the ended plantings to the free pool. These
  two features want to be built in the same phase, in that order.
- **Crop rotation warnings** (§3.4) get better with tags: the bind screen for
  a tag going into a known row is a natural place to say "this bed grew a
  Solanaceae last year".
- **The per-garden field sheet** (§13.4, blocked on Claude Design) and the
  tag sheet are the same FPDF layer and the same "print at 100 %" problem.
  Whoever designs one should design both.

---

## 9. Constraints this must not break

1. **No third-party call on the request path.** The encoder is local. §4.
2. **Three statements on `/t/{code}`.** §6.3. Remote database, `hosting.md` §9.
3. **`max_input_vars` is 1000 and truncates silently.** A 24-tag sheet form is
   fine; a "bind forty tags at once" form is not. Submit per row.
4. **Client shell stays inside 150 KB gzipped.** This feature adds zero bytes:
   inline SVG, no scanner, no library.
5. **No inline `<script>`, no off-site `src` or `href`.** Both are now
   test-enforced (Phase 5 handoff §2.5). Nothing here needs either.
6. **`tokens.css` is still the only file that names a colour** — including the
   two new QR tokens, which must be marked un-themeable. §4.2.
7. **Migration `016` is immutable once applied.**
8. **Every string printed by FPDF goes through `Document::t()`.** §5.9.
9. **Label sheets are US Letter; `Carl\Reports\Document` is A4** and cannot
   be parameterised into one. A separate FPDF subclass, margins zero, auto
   page break off, empty header and footer. §5.5.
10. **276 tests green under `--strict` on MySQL 8.0 and MariaDB 10.11** before
   any push.

---

## 10. Open questions for the owner

1. ~~**Does a planting ever need to split?**~~ **Answered, and it became its
   own proposal: [`PLANTING-SPLIT-SPEC.md`](PLANTING-SPLIT-SPEC.md).** A
   planting is a group with *one location*, so a hundred seedlings that go to
   two gardens over three weekends cannot be described at all today. The
   answer is a split that produces a real planting, which keeps every planting
   location-singular — and that dissolves the tag question rather than
   answering it: one tag per planting becomes correct by construction.
   **That proposal sequences before this one** (its §7): building tags against
   a unit about to change shape means designing the rules twice, and the
   moment to bind a tag to the six plants you are moving is the transplant
   screen the split rebuilds.
2. **Laser or inkjet?** Decides §1.3 outright.
3. **Is a short domain worth $10 a year?** §2.3 lever 1. It is the only
   change that makes the code meaningfully bigger on the same tag, and it is
   not an engineering task.
4. **Scan log: yes or no?** §3.1 says no for v1.
5. **How many tags in year one?** Sets the batch size and whether 600 labels
   is a sensible first purchase or three seasons' worth.

---

## 11. Phasing

**5a — the whole useful thing.** Encoder + fixtures, migration 016, both label
stocks and the preference, the full-sheet PDF, `/t/{code}` with bind in both
directions (§5.2), the untagged-plant bind list and the tagging session
(§6.4, §6.5), the field screen. Nothing here depends on anything unbuilt
except `?next=` on login. **No partial-sheet handling anywhere in 5a** — that
is what printing whole sheets buys.

**5b — the polish.** Named labels — the queue, the partial sheet and the
start-at-position control that goes with them — the tag pool screen, "find a
tag by code", retire a lost sheet, release-on-end-season, rebind.

**Deferred.** In-app scanner (never — §7). NFC (§1.6). Scan log (§3.1).
Anything that assumes a planting can split (§10.1).


---

## 12. Where the build diverged from this spec, and why

Seven, in the order they will bite somebody reading the spec as if it were the
code.

### 12.1 The migration is `021`, not `016`

§3.1 names `016_qr_tags.sql`. 016 is the analysis queue, applied in Phase 5;
this spec was written when 015 was the last migration, and migrations are
immutable once applied (hosting §7). Nothing else about §3.1 changes. §9 rule 7
should read "migration 021 is immutable once applied".

### 12.2 There is a third table, and §5.4 already required it

§3.1 says "two tables, plus two columns on `user`". §5.4 then asks for
`/tags/batches/{id}.pdf`, `/tags/batches/{id}/retire`, and says "`stock_sku` is
recorded on the batch". That is a `qr_tag_batch` row, and it is built:
`id`, `user_id`, `stock_sku`, `tag_count`, `retired_at`.

It has to exist for the reason §5.4 gives for the GET/POST split. The mint is a
POST and the render a GET so that a paper jam costs a sheet of paper rather
than thirty codes — and a reprint is only identical to the first print if the
stock comes from the batch rather than from the user's current preference. A
user who printed on 60517 in January and has since switched to 00757 must get
60517 back, or the reprint is misaligned against the half-used sheet in their
hand.

### 12.3 `qr_tag_binding` carries `user_id`

§3.1 gives it `tag_id` and `planting_id` and no scope column. Handoff §5 says
every user-owned table carries `user_id` and every query filters on it,
enforced in one base class — and a binding table that can only be scoped by
joining through `qr_tag` is a table whose next query is the one that forgets
to. It is `NOT NULL` with the same `ON DELETE CASCADE` as everything else.

### 12.4 THE UPPERCASE URL IS OFF BY DEFAULT, AND §2.2 IS HALF WRONG

This is the one that matters, and it is the one place the spec would have
produced tags that do not work.

§2.2's encoding argument is exactly right: alphanumeric mode packs two
characters into 11 bits against byte mode's 8, so an all-uppercase URL buys
version 3 at level Q where lower case needs version 4, and 0.649 mm modules
against 0.585 on the same 24 mm tag face. The measurements in
`21_tags_test.php` reproduce every number in §2.2 and §2.3.

Its conclusion does not follow. **"DNS is case-insensitive and we choose the
path"** — half of that path is not ours to choose. Carl is served from
`public_html/carl/` (hosting §5.1), and Apache and LiteSpeed map URL paths onto
filesystem paths case-sensitively on Linux. `/CARL/T/AB7K4M` looks for a
directory named `CARL`, does not find one, and gets the web server's own 404:
the `.htaccess` inside `carl/` is never consulted and `index.php` never runs.
The scheme and the host are case-insensitive by specification; **the mount
point is a directory.**

So `Carl\Qr\TagUrl` owns the decision and makes it once:

- `tags.uppercase_url` is `null` by default, which upper-cases only when
  `base_path` is the domain root — where there is no directory segment to get
  wrong and every part of the path is one Carl routes itself.
- Set it `true` after opening the upper-case URL in a browser and seeing a
  page. `deploy.md` has the check.
- The Tags screen shows which encoding is in force, the version, and the
  millimetres per module, measured by encoding a real code rather than quoted
  from this document — so the difference is never silent.

**Lower case is not a failure.** 0.585 mm is 2.3x ISO 18004's practical print
floor and gives a 600 dpi laser fourteen dots per module, and §2.3 already says
the binding constraint on a phone is minimum focus distance rather than module
size. It costs one symbol version.

**§2.3's lever 1 gets better, not worse.** A short domain was already "by far
the biggest lever available and not an engineering task". It now fixes this as
well: at `carl.garden` even lower case fits version 3, and upper case fits
version 2 at 0.727 mm — 12% larger than the best case this spec imagined on the
current domain.

The other half of §2.2's consequence for the router IS built, and more of it
than asked: `/t/{code}` and `/T/{code}` are both registered, the code is
matched `[0-9A-Za-z]+` and upper-cased before lookup, and `Request` now strips
the mount point case-insensitively so that an upper-case address works the
moment a server answers one.

### 12.5 Half of each label sheet's geometry is derived, and the registration sheet is what settles it

§5.6 says to take the layout constants from each manufacturer's published
template and never from arithmetic, because Avery's pitches are not always
label width plus gutter. That is right, and it was only half followable: Avery
publishes the label size, the sheet size, the per-sheet count and (for 60517) a
0.325 in margin, and does not publish the pitches or the origin anywhere
reachable.

`Carl\Domain\LabelStock` marks every number `[published]` or `[derived]`, the
derived ones being the grid centred on the sheet at the published margin.

§5.6 already named the acceptance test and the build takes it literally: the
**registration test sheet** is built, it is linked from every batch page, the
print screen puts it in front of the user before the first sheet on real stock,
and `deploy.md` makes it step 2 of the deploy. A correction is one edit to one
class — no migration, and every batch already minted re-renders correctly,
because a batch stores only which stock it was for. `21_tags_test.php` asserts
that every stock's geometry fits on Letter, which is the failure the sheet
cannot show you: `SetAutoPageBreak(false)` means a row past the paper does not
error and does not spill to page two, it simply does not print.

### 12.6 `?next=` on login was already there, and is better than `?next=`

§6.1 lists "a signed-out scan needs login to return to where it was going" as
a dependency, with a warning that an open redirect on a URL printed on a
physical object is worse than the usual kind.

It needs nothing. `App::guard()` already stores the intended path in the
session and `AuthController` already returns to it, requiring it to start with
`/`. That is strictly safer than the query parameter the spec asked for,
because the value never leaves the server and there is no open-redirect surface
to get wrong at all.

### 12.7 The field screen offers fewer actions than §7 lists, on purpose

§7 says "exactly the actions the plant's current state allows — Water, Photo,
Note, Harvest, Pest, Died".

`PlantingState::actionsFor()` is still the authority and the field screen still
starts from it, but it drops the actions that need a **second answer**: how
many died, where it was transplanted to, which pest, how heavy the pick. A
one-tap screen cannot ask, and a default guessed on the user's behalf writes a
number nobody said — which is worse in a log that everything else is derived
from. They are one tap away on `/log/{id}`, linked under the fold, which §7
already puts there.

Photo went the same way for a duller reason: `<input type="file"
capture="environment">` is one tap to open the camera and several more to
choose and upload, so it is not a one-tap action and it belongs with the form
that already handles uploads.

`21_tags_test.php` asserts both halves — that the buttons are absent, and that
the POST refuses the type even if the form is forged.

### 12.8 One thing §6.5 asked for costs three statements, so it is on two screens instead

§6.5 wants "a persistent `Tagging · 4 bound · stop` strip on every page while
it runs", and argues correctly that it costs nothing because
`tagging_started_at` rides along on the user row `Auth::user()` already
selects.

That is true of the *flag* and not of the *cursor*. "Next: Cherokee Purple" and
the two counts are three more statements. So the strip is on every page,
rendered from the layout off the row Auth already loaded, and says a session is
running and offers the stop — which is what the rule is for. The cursor and the
counts appear on the tag screens, which were making those reads anyway.

---

## 13. What was built beyond the spec, and what was left

### 13.1 Beyond

- **A `Carl\Qr\Svg` renderer**, one `<svg>` with one `<path>`, from the same
  merged runs the PDF draws. §4.2 asked for it; it is worth naming because the
  merge is shared and a version 3 symbol is ~150 subpaths instead of 841
  elements.
- **`EventRepository::recentForPlanting()`**, so the field screen can answer
  "did I already water this today?" without the six-join timeline.
- **The encoder's fixtures are validated by a decoder as well as an encoder.**
  §4.1 says "a PHP decoder does not exist to round-trip against", which is true
  of PHP and not of the offline generator: `tests/fixtures/qr/generate.py`
  refuses to write a fixture unless an independent decoder reads the exact
  payload back out of it AND an independent encoder agrees module for module —
  or differs only in the one documented place where that encoder appends a pad
  codeword ISO 18004 §7.4.10 does not call for.

### 13.2 Left, deliberately

- **No scan log.** §3.1, unchanged: a row per scan is a write on every page
  view for a fact nothing yet reads. The event the user records is the trail.
- **No in-app scanner, ever.** §7.
- **No per-tag notes, no NFC, no tag groups.**
- **The named-label queue is every bound tag**, not a queue of ones that have
  not had a named label printed yet. Tracking "printed a named label for this
  one" is a column and a write path for a case the start-at-position control
  already covers: print the ones you want, start where the sheet is empty.
- **Retire is per sheet, not per tag.** §5.4 asks for the sheet, which is the
  thing that actually goes missing. A single ruined stake is thrown away and
  its code sits unbound in the pool, which is the correct state for it.
