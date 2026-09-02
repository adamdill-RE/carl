# Carl The Garden Helper — Phase 15 handoff

**Phase 14 is one bug, one piece of arithmetic and two questions.** The bug
was every "Download PDF" on the live site returning "Something went wrong"
while the field sheet and the label sheets, which are also PDFs, were fine.
The arithmetic is what a drip line actually puts down, from the three
numbers printed on the emitter packet. The questions — can Claude Code talk
to Carl directly, and can Carl ring a phone when a zone has run long enough
— are answered in §3 and are the two candidates for what Phase 15 builds.

The bug is worth the first section, because it was live for four phases and
the reason nobody could see it is a property of the platform that the next
one will share.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — the authorities.
   **Phase 14 annotates neither**, but it adds one row to what §12 of the
   hosting document calls unverified: `allow_url_fopen`. See §2.1. `/status`
   now prints it; the owner action in §5 is to read it once.
2. **`docs/CARL-HANDOFF.md`** — the specification. **Phase 14 adds one
   bullet to §11** (the drip zone's emitter figures as a source of
   `irrigation_applied`). Nothing else changes.
3. **`docs/PHASE-14-HANDOFF.md`** §3 (what is left), §4 (what must not
   regress) and §7 (bodies). All three are current in full. §3 is
   reproduced by reference only: nothing there was touched.
4. **`docs/deploy.md`** — the runbook. **Phase 14 adds ONE MIGRATION,
   `025_zone_emitters.sql`**, four columns on `water_zone`, all nullable or
   defaulted. The deploy is the file copy of §6.2 **followed by
   `/setup?key=`** for the migration, and until that runs the garden page's
   zone form will 500 on save (the operator hint on the error page now says
   so, to an admin). No cron change.
5. **§8 below is the working agreement**, unchanged, with one addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | **25** (`001`–`025`), 42 tables — Phase 14 added **one**, no new table |
| Routes | **109** — Phase 14 added none |
| Source / views | 112 PHP classes (**+1**, `Domain\DripLine`), 58 templates (+0) |
| Tests | **649 tests, 6,999 assertions**, green under `--strict` on MariaDB 10.11 |
| Static CI checks | 8, unchanged |
| Client shell | 34.1 KB gzipped, unchanged — no CSS or JS in this phase |

Three things:

**Every PDF report embeds its images from memory.** §2.1. Nothing in the PDF
path opens a URL wrapper any more, and the measurement child in
`11_reports_test.php` now runs with `allow_url_fopen=0` to prove it.

**A water zone knows what it puts down.** Emitter flow, emitter spacing,
line spacing and an efficiency, all optional; the nightly model turns a
zone watering of *n* minutes into millimetres from them; and the
recommendation says how full the root zone is and how many minutes on which
zone would refill it. §2.2–2.4.

**A 500 tells an admin what broke.** The error page, to an admin only, now
carries the exception class, message and file:line — the one line the
error log would show, for an account with no way to read the error log.
§2.1 is why.

---

## 2. What Phase 14 established that Phase 15 should not re-derive

### 2.1 `data://` is a URL, and the host may refuse URLs

`Document::embed()` handed FPDF each chart and photograph as a
`data:image/jpeg;base64,…` path, so that nothing was written to disk
(handoff §13.2, "stream it and keep nothing"). That is a stream wrapper,
and PHP gates every URL wrapper — `data://` included — behind
`allow_url_fopen`. With it off, `getimagesize()` and `file_get_contents()`
both return false, FPDF throws *"Missing or incorrect image file: data://…"*
with the whole base64 body in the message, and the kernel renders
"Something went wrong. It has been logged."

Three facts made it invisible:

- **Locally and in CI the setting is on.** The suite built hundreds of PDFs
  and never met the failure. `dev/php.ini` now sets it **off**, because it
  is unverified on sh193 and off is the stricter answer; Carl reaches the
  network through curl and reads files by path, so nothing legitimate
  needs it.
- **The only PDFs with images in them are the two reports.** The field
  sheet and the label sheets are text and vector rectangles, so "some PDFs
  work" read as "PDF works".
- **"It has been logged" was true and useless.** The log is
  `~/logs/php.error.log` on an account with no shell (hosting §1), and the
  page gave the admin nothing else. The Phase 3 deploy taught the same
  lesson for a missing table (`App::operatorHint()`), and the hint was
  narrowed to that one case. It is now general: an admin sees the class,
  the message (truncated to 300 characters, so an image body cannot fill
  the page) and file:line. The DSN never reaches an exception message
  (`Database` re-throws without it, hosting §7), so nothing here can echo a
  credential.

The fix is the boring one. `embed()` parks the bytes under a name FPDF has
never heard of and `_parsejpg()` is overridden to answer from memory for
that name, using `getimagesizefromstring()`. No wrapper, no temp file, no
setting to depend on, and the memory profile is what it was: FPDF keeps one
copy of the bytes in its own image table either way. The name is a hash of
the bytes, because FPDF caches by name — a fresh name per call would embed
the same photograph twice, and one name for all would print the first
photograph twenty times.

**This is inferred, not measured.** The owner has not yet read
`allow_url_fopen` off `/status` on the live site. Every other candidate was
eliminated by reading the code path (the same series, events and photo
reads render the page the button sits on), and the failure reproduces
exactly under the setting locally. If the live value turns out to be *on*,
the report is still fixed of one real bug and the admin error detail is
how the next one gets found in a minute rather than a phase.

### 2.2 The depth of a drip watering is three numbers and an area

Handoff §11 gets a depth from a flow rate typed in mm/h on the water
method, or a guess by the method's name. Nobody knows their drip line in
millimetres an hour. They know what the packet says — *0.5 gph, 12 in* —
and the one thing the packet cannot know is how far apart the lines are,
which is a fact about the zone, not the method. So the figures live on
`water_zone` (migration 025) and the arithmetic is the one every extension
service and manufacturer prints:

    rate (in/h) = 231 × gph / (emitter spacing in × line spacing in)

231 is the cubic inches in a US gallon; each emitter is responsible for the
rectangle between it and its neighbours; 25.4 turns inches into the
millimetres the checkbook is kept in. Rain Bird's worked example — 0.9 gph
every 12 inches, lines 18 inches apart, 0.96 in/h — is the first assertion
in the test. The net depth is `rate × hours × efficiency`.

**Efficiency is a gross-to-net factor and it defaults to 80.** Field
measurements of drip application efficiency sit at 80–95 per cent; 80 is
the conservative end and it errs the way `WaterMethod` already errs —
toward thinking the soil is drier than it is — because a suggestion to
water that was not needed is the mistake a gardener notices and ignores,
and the other one is a dead bed. The efficiency applies to the emitter
computation only. A flow rate typed in mm/h on a method is taken at its
word, as it always was.

**A missing spacing is assumed, not refused.** No emitter spacing means 12
inches (the common inline spacing sold). No line spacing means the garden's
own row spacing, derived from its width and row count (`DripLine::
rowSpacingIn()`: rows running north–south are spaced across the east–west
side), or, when the garden has no size, the emitter spacing — a square
grid. Every assumption is in the basis text the recommendation prints, and
the zone list on the garden page says the same thing in the gardener's
units, so a wrong assumption is a visible one. The alternative — refusing
the figure — falls back to a guess by method name, which knows nothing.

**Precedence, for a zone watering:** the zone's emitter figures, then the
event's method flow rate, then the zone's method flow rate, then the
per-method guess. `irrigationByDate()` carries `basis_kind` so the
correction hint points at the right screen: *"correct the emitter figures
on the zone"* rather than *"the flow rate under Lists"*.

### 2.3 "How full is it" is the deficit read the other way up

The reason text now opens with *"Root zone about 64% full"*. It is
`1 − D/TAW`, FAO-56's own fraction of available water still in the ground,
and it is the number a gardener was always mentally computing from
"deficit 18 mm of an allowed 25". Nothing new is stored: `deficit_mm` and
`taw_mm` were already on the row. **The "weather impact since the last
watering" the ask mentions is the checkbook itself** — every day's ET₀×Kc
and effective rain has been in `D` since Phase 3 — so the ask was satisfied
by naming what was already computed, not by computing something new.

### 2.4 Minutes, not millimetres, are what a timer wants

Where a garden has a zone with an emitter figure, a *water* or *likely*
recommendation ends with *"About 40 min on Drip east, or 25 min on Beds
refills it."* — the deficit in that sentence over each zone's net rate,
rounded up to five minutes, at most three zones. `DripLine::minutesFor()`.
This is the number §3.2 below would put on a timer, and it exists so that
the timer, when built, has nothing to compute.

### 2.5 The zone form is "save the same name again"

`createZone()` was already an upsert on `(garden_id, name)`; Phase 14 makes
the upsert carry the four new columns too. There is still no edit screen
for a zone: re-saving the name with new figures is the edit, and the test
pins that it stays one row. A dedicated edit form is a small ask if the
owner finds this unintuitive on the walk.

---

## 3. Phase 15 — what is left, and the two questions

Everything in `PHASE-14-HANDOFF.md` §3 is carried unchanged: the tag's
unshown history (§3.1), the twenty-four identical named labels (§3.2), the
three silent truncations (§3.3), the cell number (§3.4), the batch log form
(§3.5), and everything those carried forward from Phase 12 and earlier.
Phase 14 touched none of them.

The two asks of this phase were research, and the answers are below in
enough detail to be built from. **Both are feasible on this host.** The
timer is the smaller of the two and the one with the more obvious payoff,
because §2.4 already computes the number it needs.

### 3.1 An MCP server, per user, that Claude Code can read Carl through

**Feasible, and cheaper than it sounds, because the transport was
designed for exactly this hosting.** The facts, checked against the
specification (2025-06-18) and the Claude Code documentation on
2026-09-02:

- **Streamable HTTP is one endpoint that answers POSTs.** Every client
  message is a new HTTP POST carrying one JSON-RPC message. For a request
  the server *"MUST either return `Content-Type: text/event-stream` … or
  `Content-Type: application/json`, to return one JSON object"* — and the
  client *must* support both. So a server may never open an SSE stream at
  all, which is the only thing hosting §3 forbids (no held-open
  connections under the LVE entry-process cap). The GET side, which exists
  for server-initiated messages, may answer **405** outright. Sessions are
  optional: a server that never issues `Mcp-Session-Id` is a stateless
  server, and Claude Code is fine with that. Each tool call is therefore
  one ordinary PHP request, with one TCP+auth handshake to the database and
  a statement count, exactly the shape everything else in Carl has.
- **Per-user segregation is a bearer token, and Claude Code supports one
  natively.** `claude mcp add --transport http carl https://www.reshiftmanager.com/carl/mcp --header "Authorization: Bearer <token>"`,
  or the same in a project's `.mcp.json` with `${CARL_TOKEN}` expanded
  from the environment. No OAuth is needed for Claude Code. (Claude.ai's
  own "custom connectors" — Desktop and web — want OAuth 2.1 with dynamic
  client registration; that is a larger job and a separate decision, and
  it is not what was asked.)
- **The isolation is the one Carl already has.** Every repository scopes
  every statement by `user_id` (handoff §5), so a tool that resolves a
  token to a user and instantiates the same repositories inherits the
  guarantee that the export, the reports and the pages already rely on.
  The tools do not need SQL of their own; the thing that would break
  isolation is a tool that assembled some.
- **The data shape exists.** `/export/claude.json` and
  `Analysis\Document` (the bounded summary the Recommendations job sends)
  are already "Carl, for Claude". The tool set is those two, cut into
  pieces a conversation asks for.

What to build, in order:

1. **Migration 026, `api_token`**: `user_id`, `label`, `selector`,
   `verifier_hash` (SHA-256), `created_at`, `last_used_at`, `revoked_at`.
   The `selector.verifier` shape of hosting §8.3, minus the rotation: this
   is a long-lived credential the user pastes into a config file once. A
   screen under Reports, "Connect Claude Code", that mints one, shows it
   **once**, lists the live ones with last-used dates, and revokes.
2. **`/mcp`**, `POST` only, a new `Route::BEARER_ACCESS` that resolves the
   token before the router (like `KEY_ACCESS`, unlike a session), and
   **validates the `Origin` header** — the specification's one MUST for
   this transport, against DNS rebinding. CSRF-exempt: the bearer is the
   credential. GET → 405. `DELETE` → 405. Reject an unsupported
   `MCP-Protocol-Version` with 400; assume `2025-03-26` when the header is
   absent, as the spec says. JSON-RPC batching was removed in 2025-06-18,
   so one message per POST is all it needs to parse.
3. **Methods**: `initialize` (capabilities: `tools`, `resources`),
   `notifications/initialized` (202, empty), `ping`, `tools/list`,
   `tools/call`, `resources/list`, `resources/read`. Read-only, every one.
4. **Tools**, each one bounded the way the export is:
   `list_gardens`, `list_plants` (living / ended / garden / row / a search
   term, the same search View Plants has), `plant` (the timeline, size
   readings, yield, tag codes, research card in force for the region),
   `weather` (garden or plant, date range, the `Series` document the
   charts read — one statement for the weather, one for the events),
   `watering` (today's recommendation per place, with the reason text and
   the refill minutes of §2.4), `garden_actions`, `research_card`,
   `pests`. And one **resource**: `carl://export/summary`, the
   `Analysis\Document` for a date range, which is the whole account at a
   size a context window can hold (140 KB for five years, deploy §0.9).
5. **Limits that are not optional**: a response cap per tool (the raw
   export of a five-year account is 3.3 MB and 918,000 tokens; a tool that
   returns it has failed), a rate limit per token (the login limiter's
   shape), `no-store` on every response, and the token's `last_used_at`
   written on every call so the Connect screen can show a token nobody has
   used in a year.

Cost: roughly the size of `ExportController` plus a screen, a migration
and a test file that drives the endpoint through the kernel the way
`11_reports_test.php` drives the PDF. Nothing new on the host. The
outstanding platform fact (§5.1 of every handoff since Phase 6, outbound
HTTPS to `api.anthropic.com`) is **not** involved: this is inbound, and
inbound already works.

What it is not: a way for Claude to *write* to Carl. Logging a watering
from a conversation is a different feature with the tag spec's two-tap
promise to keep, and it should be a decision, not a side effect of the
read-only server growing a verb.

### 3.2 A timer that reaches an iPhone when the zone has run long enough

"I want to water Zone 1 in the v3 garden for 60 minutes; ping me when it
is done." **Feasible, on this host, with what Carl already ships — and the
number to put on the timer is already on the MOTD (§2.4).** What the host
removes, and what it leaves:

- **Not possible:** anything that keeps a connection open (hosting §3 —
  no SSE, no WebSocket), a JavaScript timer in the page (iOS suspends a
  backgrounded Safari tab or home-screen app within seconds, and the timer
  with it), and the Notification Triggers API (never shipped). Nothing
  server-side can *push* except through a push service, and nothing can
  run except from cron.
- **Cron runs to the minute.** cPanel's Cron Jobs accept a `* * * * *`
  schedule (the weather job already proves cron works, deploy §0). A
  `bin/timers_fire.php` that wakes every minute, selects `timer` rows with
  `ends_at <= now AND fired_at IS NULL`, sends, and exits costs one short
  LVE entry process a minute. Granularity is therefore **up to a minute
  late**, which for a watering is nothing.

The options, best first:

1. **Web Push to the home-screen web app.** Carl has shipped a manifest
   and home-screen icons since Phase 10, which is the precondition: on
   iOS, push permission can only be requested from a web app **added to
   the Home Screen** (iOS 16.4+), and the subscription goes to
   `web.push.apple.com`, which is outbound HTTPS, which is open (deploy
   §0.1). Server side needs the Web Push protocol: a **VAPID** key pair
   (P-256; Apple requires the `sub` claim to be a `mailto:` or an HTTPS
   URL, or it returns 403), an ES256 JWT per push, and RFC 8291 payload
   encryption — ECDH via `openssl_pkey_derive`, HKDF via `hash_hkdf`,
   AES-128-GCM via `openssl_encrypt`. **All of that is in the core
   `openssl` extension, which the host has** (hosting §4); the usual PHP
   library is a Composer package, which is out, and the crypto it wraps is
   about 250 lines when written directly, the way the QR encoder and the
   SMTP client were. **Declarative Web Push** (iOS 18.4+, Safari 18.5+)
   makes the client half smaller still: a JSON body
   `{"web_push":8030,"notification":{"title":…,"body":…,"navigate":…}}`
   shows a notification with **no service worker**, and the same push
   falls back to a service worker on older iOS. Carl has no service worker
   today; with declarative push it need not gain one for this. The
   notification's `navigate` URL is the garden actions form, prefilled
   with the zone and the minutes, so the watering is logged in one tap —
   or, if the timer was started with "log it when done", the cron logs
   the `watered` event itself and the tap just confirms.
2. **Email, through the outbox that exists.** Zero new infrastructure:
   the timer cron queues a message and `mail_send.php` drains it. Mail on
   an iPhone is a push. Latency is a minute of cron plus delivery,
   typically two to three minutes end to end. This is the fallback for any
   device that has not installed the web app, and it should ship with
   option 1 rather than instead of it, because a push subscription is a
   thing that quietly stops existing (a reinstalled app, a cleared site).
3. **A relay app** (ntfy, Pushover). A `curl` from cron to a third-party
   service with its own iPhone app. Simple, reliable, and it puts *"Zone 1
   is done"* through a stranger's server and asks the gardener to install
   someone else's app for one notification. Not recommended for Carl,
   which has avoided exactly this trade with the QR encoder and the weather.
4. **Hand the timer to the phone's Clock.** A Shortcuts URL can start a
   native 60-minute timer with native reliability — and Carl then knows
   nothing about it, because nothing runs when a Clock timer ends. It is a
   convenience button, not an integration, and it does not log anything.

What to build: a `timer` table (`user_id`, `garden_id`, `water_zone_id`,
`minutes`, `started_at`, `ends_at`, `fired_at`, `log_when_done`,
`logged_event_id`); a `push_subscription` table (`user_id`, `endpoint`,
`p256dh`, `auth`, `created_at`, `failed_at`); a "Start a timer" form on the
garden actions page (zone, minutes, "log the watering when it finishes")
and a one-tap version of it on the MOTD's *"About 40 min on Drip east
refills it"* line; `bin/timers_fire.php` on a one-minute cron with a
`/tasks/` twin; and the push sender as `Carl\Push\WebPush`, tested the way
the SMTP client is — against the arithmetic of RFC 8291's published test
vector, not a mock. The permission prompt, the home-screen requirement and
whether iOS keeps the subscription alive across a week of not being used
are the three things only the walk can answer.

---

## 4. What must not regress

Everything in `PHASE-14-HANDOFF.md` §4 still applies. Phase 14 adds four.

1. **No PDF path opens a URL wrapper.** §2.1. `Document::embed()` parks
   bytes and `_parsejpg()` reads them from memory; the measurement child
   in `11_reports_test.php` runs with `allow_url_fopen=0` and asserts all
   twenty photographs and three charts went in. Reintroducing `data://`,
   `php://memory` handed to FPDF by path, or a temp file, is the bug back
   — or a file on a shared host with the user's photographs in it.
2. **A zone with no emitter figure behaves exactly as before.**
   `DripLine::depth()` returns null for it and `irrigationByDate()` falls
   through to `WaterMethod`. The existing zone test (hose, ten minutes,
   6 mm) pins this. Applying the 80 % efficiency to the method path would
   silently change every recommendation in every account that never
   touched the new fields.
3. **Efficiency is applied once, to the emitter rate, on the way to a
   depth.** Not to rain, not to a typed mm/h, and not again in
   `minutesFor()` on top of a rate that already has it — the rate the
   reason text carries is *gross*, and `minutesFor()` divides by
   `rate × efficiency`. Double-applying it is a 20 % error that looks like
   a plausible number.
4. **The reason text keeps "Root zone about … full" and a lower-case
   "deficit".** Two tests and the digest read it. The percentage is the one
   number a gardener reads first.

---

## 5. Owner actions outstanding

The list in `PHASE-10-HANDOFF.md` §5 stands (eleven, less the two Phase 13
struck off). **Phase 14 adds two and they are the first things to do after
the deploy.**

1. **Apply migration 025** at `/setup?key=` after the file copy. Until it
   runs, saving a zone is a 500 and the garden page's zone list still
   renders (it reads `z.*`, and the new columns are simply absent).
2. **Open `/status?key=` and read the `allow_url_fopen` line**, then press
   "Download PDF" on any plant or garden. Three outcomes:
   - *off*, and the PDF downloads: §2.1 was the cause, and it is fixed.
   - *on*, and the PDF downloads: §2.1 was wrong about the cause and the
     fix is a coincidence or the failure was something transient — record
     it and move on, the code is better either way.
   - **the PDF still fails**: sign in as admin and press it again. The
     error page now prints the exception. That one line is the whole of
     the next diagnosis; paste it into the next session.
3. **Enter the emitter figures on a real zone** and, the morning after the
   next zone watering, read the MOTD. The line should name the zone, the
   figures, the depth and the minutes to refill. If the minutes look wrong
   by a factor of two or three, the line spacing is the first thing to
   check — it is the assumption with the widest range.

And the walk (Phase 12's two items, Phase 13's tagging session) is still
outstanding, unchanged.

---

## 6. Claude Design outstanding

Unchanged from `PHASE-14-HANDOFF.md` §6. Phase 14 adds no colour, no token
and no CSS; the four zone fields use the existing `.field` and `.help`
shapes and the zone list's new line is `.muted .small`. If a timer is built
(§3.2), the "Start a timer" affordance on the MOTD is the first thing in
Carl that would want a countdown or a progress element, and that is a
design question rather than a CSS one.

---

## 7. Where the bodies are buried

Everything in `PHASE-14-HANDOFF.md` §7 still applies. Phase 14 adds four.

- **FPDF caches images by name and copies the bytes.** `$this->images`
  keeps the `data` of every image for the life of the document. That is
  fine — it is the memory profile that was measured — but it means a second
  copy in `Document::$blobs` would double it, which is why `embed()`
  unsets its entry in a `finally`. And `_parsejpg` has no parameter type
  in FPDF 1.86, so the override cannot declare one either.
- **`App::operatorHint()` is now never empty on a 500.** The error view
  only shows it to an admin, and the JSON error path does not include it
  at all. If a future non-admin surface renders `adminHint`, it prints an
  exception message to a user.
- **The metric path stores the packet's units.** A gardener on `units=si`
  types litres per hour and centimetres; `readEmitter()` converts on the
  way in and the view converts on the way out, and the row holds gph and
  inches whatever was typed. A test that inserts a `water_zone` row
  directly must write gph and inches, not L/h and cm.
- **The recommendation's "refills it" minutes are for the deficit in that
  sentence, on the day the row was computed.** A gardener reading the MOTD
  at 6 pm after a hot day is looking at a morning number; the timer of
  §3.2 should read the row, not recompute, and say the date it is from.

---

## 8. Working agreement

Unchanged from `PHASE-14-HANDOFF.md` §8, including every earlier phase's
addition. One addition, from §2.1:

> "It has been logged" is only a diagnosis if somebody can read the log.
> On an account with no shell, the error page is the log — for the admin,
> and for nobody else.

And the Phase 10 test, answered "no" once more, for a report that had been
failing on the live site since the phase that built it:

> **Would anybody find out?**

A failure that only happens under a setting the development environment
does not share survives every test that runs in the development
environment. The fix for that is not a bigger suite; it is running the
suite under the setting.

