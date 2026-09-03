# Carl The Garden Helper — Phase 17 handoff

**Phase 16 built the two things every handoff since Phase 14 said were next,
and corrected one thing the owner found with a label sheet in his hand.**
Claude Code can now read Carl directly, through a Model Context Protocol
server with a bearer token per machine; a watering timer reaches a phone,
through Web Push written against the RFCs and tested against their own
vector, with the mail outbox behind it; and the self-laminating Avery sheet
is printed as one column of ten with the flap beside each label, which is
what the sheet actually is, rather than the two columns of five that fifteen
phases had derived from "10 per sheet at 3½ in wide". Two migrations, one
new cron (every minute), one setup step (the push key pair), and nothing new
from the host.

Both features were built as `PHASE-15-HANDOFF.md` §3 specified them. Where
the build departed from that plan, §2 says so and why.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — the authorities. Phase
   16 annotates neither. It depends on two things §3.2 of the Phase 15
   handoff already named and the deploy runbook now repeats: outbound HTTPS
   to the push services (open, but never spiked) and a cron that may run
   every minute (cPanel's does).
2. **`docs/CARL-HANDOFF.md`** — the specification. Phase 16 adds a bullet to
   §4.2 (the MOTD's timer button), a paragraph to §4.7 (the timer), a
   paragraph to §13.3 (Connect Claude Code), a paragraph to §7 (the bearer
   access level), a Phase 16 entry in §14, and one row of §15.
3. **`docs/PHASE-16-HANDOFF.md`** §2 (what Phase 15 established), §4, §5, §7
   — all current in full. Its §3.1, §3.3 and §3.4 are still open (§3 below);
   its §3.2 and §3.5 are done.
4. **`docs/deploy.md`** — the runbook. **Phase 16 adds TWO MIGRATIONS, 026
   and 027, ONE CRON JOB and ONE SETUP STEP**, and the "Redeploying" section
   leads with them. The garden actions page and the main menu are 500 until
   027 runs.
5. **§8 below is the working agreement**, unchanged, with one addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | **27** (`001`–`027`), 46 tables — Phase 16 added **two** (026 `api_token`; 027 `water_timer`, `push_subscription`, `push_key`) |
| Routes | **121** — Phase 16 added **eleven**: `/connect` (3), `/mcp`, `/timers` (4), `/push` (2), and `/tasks/timers-fire` |
| Source / views | 127 PHP classes (**+14**), 61 templates (**+2**: `connect/index`, `timers/show`) |
| Tests | **703 tests, 7,399 assertions**, green under `--strict` on MariaDB 10.11 locally (PHP 8.4; CI is 8.2 on MySQL 8.0 and MariaDB) |
| Static CI checks | 8, unchanged |
| Client shell | 38.5 KB gzipped (**+2.9 KB**: `push.js`, the `sw.js` handlers, four CSS rules) |
| Cron jobs | **7** (+1, every minute) |

**Claude Code reads Carl.** `POST /mcp`, Streamable HTTP, stateless, JSON
only; eight read-only tools and the season summary as a resource; a bearer
token per machine from **Connect Claude Code** under Reports. §2.1.

**A timer reaches a phone.** "Start the timer" on garden actions and a
one-tap "Start 55 min on Drip east" under the MOTD's watering line;
`bin/timers_fire.php` every minute; Web Push first, the outbox second; the
notification opens `/timers/{id}`. §2.2.

**The 00757 sheet is one column of ten.** `LabelStock` carries the observed
layout and a `flap_w`; the registration sheet draws the flap and the fold.
§2.3.

**Two small ones from the Phase 16 handoff's §3:** the pest detail now has
its full stop (§3.2 there), and the menu drawer closes when keyboard focus
leaves it (§3.5 there).

Pull request: `adamdill-RE/carl#22`, branch
`claude/qr-mint-label-template-nk01ys`, two commits, merged 2026-09-02. The
second commit is §2.4: the suite was green on the wrong PHP.

---

## 2. What Phase 16 established that Phase 17 should not re-derive

### 2.1 The MCP server is one request per message, and that is the design

`PHASE-15-HANDOFF.md` §3.1 was right in every particular and the build
follows it: the transport never opens a stream, never issues a session id,
and answers every POST with `application/json`; a GET is 405 from the
router; the Origin header is checked when present; an unsupported
`MCP-Protocol-Version` is 400 and an absent one is assumed. Five things
worth knowing beyond that:

- **`Route::BEARER_ACCESS` is a fifth access level, resolved in
  `App::guard()`**, and it signs the request in through a new
  `Auth::assume()` that loads the user WITHOUT touching the session — no
  cookie is ever set on an MCP response, and `28_mcp_test.php` asserts
  that. Every repository then scopes on the user id as it does for a page.
  A tool that wrote its own SQL is the one thing that could break that;
  none does, and `Mcp\Tools` says not to add one.
- **The token is `carl_<selector>.<verifier>`**, hashed like the login
  token and the invite, not rotated (a config file cannot follow a
  rotation), prefixed so a secret scanner can spot it in a pasted file.
  **The rate limit lives on the token row** — two window columns updated
  in the same statement that writes `last_used_at` — so resolving a token
  is two statements whatever the traffic. A refused call is not counted.
- **The body is read raw.** `Request::rawBody()` reads `php://input` once
  and keeps it; the test `Client` gained `postRaw()` with headers. And
  `.htaccess` gained the two lines that re-export `Authorization` to PHP,
  because LiteSpeed drops it — without them every token is "unauthorised"
  on the live site and nothing in the suite can see it. `Request::header()`
  also reads the `REDIRECT_` copy.
- **Every result is bounded before it leaves.** Lists take a limit with a
  ceiling, timelines are capped, and the encoded text is measured against
  `mcp.max_response_bytes` (256 KB): over it, the result is `isError` with
  a sentence saying how to narrow it, never a truncated document that
  parses. A five-year account's raw export is 3.3 MB; the resource that
  serves the whole season is `Analysis\Document`, 140 KB for that account.
- **"Not one of your plants" is a tool result, not a JSON-RPC error.** The
  conversation can act on the first; the second ends it. The message never
  says whether the id exists for somebody else, because the repository
  never knew.

What it deliberately is not: OAuth. Claude Code takes a bearer header
natively; claude.ai's custom connectors want OAuth 2.1 with dynamic client
registration, which is a larger job and a separate decision (§3.4).

### 2.2 The timer is a row, the cron is the clock, the phone is optional

Everything §3.2 of the Phase 15 handoff said was possible is built the way
it said, and the three things it said only the walk could answer are still
the walk's (§5). What the build settled:

- **Web Push is 250 lines against the RFCs, and the RFC's own vector is the
  test.** `Push\WebPush::encrypt()` reproduces RFC 8291 Appendix A byte for
  byte — every intermediate is returned beside the body so a failing step
  is the diagnosis — and `decrypt()` is the receiver side, which is what
  lets the live path be asserted end to end: the suite subscribes with the
  RFC's receiver keys, fires a timer through a transport that records the
  request, and decrypts the recorded body with the receiver's private key.
  `Push\Vapid` is the key pair and the ES256 token; openssl signs DER and a
  JWT wants raw `r||s`, and the conversion both ways is in the class.
- **The pair is made at `/setup`, once, and lives in `push_key`.** No shell
  to run `openssl ecparam` in, and a new pair invalidates every phone, so
  `Vapid::ensure()` never overwrites. Until it exists the garden actions
  page says so and timers fall back to email.
- **The payload is declarative Web Push** — `{"web_push":8030,
  "notification":{...}}` — which iOS 18.4+ shows with no service worker,
  and which `sw.js` now shows from a `push` handler for every other
  browser. `push.js` tries `window.pushManager` first and registers the
  worker only on the tap that subscribes; nothing else in Carl registers
  it, and nothing did before.
- **The cron writes no run row.** It fires every minute and most minutes
  nothing is due; `/status` reads the timers instead — running, overdue
  (more than three minutes late), last fired — and "overdue" is the line
  that says the cron entry is missing. The claim is a compare-and-swap on
  `fired_at`, so the cron and its browser twin cannot fire a timer twice.
- **Logging is the form's own write.** `TimerService::logWatering()` calls
  the same `recordGardenEvent()` the garden actions form does, with the
  zone's method, the minutes, and the fan-out to the zone's rows; a
  whole-garden timer fans out to nothing, exactly as the form's
  whole-garden watering does not (handoff §4.7). The event date is the
  user's local today at firing time.
- **Push, then mail, never both.** A push that any live subscription took
  is the notification; only when none did is the outbox queued, with
  `timer:<id>` as the dedupe key. A subscription the push service calls
  gone (404 or 410) is marked and not tried again; subscribing from the
  same phone brings it back. `notified_via` on the row says which happened,
  and the landing page prints it.
- **The one-tap button reads the row, not the season.** The MOTD's minutes
  come from `TimerService::refillOptions()`: the stored `deficit_mm` of the
  morning's recommendation, the zone's emitter figures and the garden's
  row spacing (which `WateringRepository::forDate()` now joins), through
  the same `DripLine::minutesFor()` the sentence used — so the button says
  what the sentence says. One extra statement for all zones of all listed
  gardens, one for the timers counting.

### 2.3 The label sheet was wrong and no test could have said so

`LabelStock` said 00757 was two columns of five with the flap folding up
from below; the real sheet is ten labels down the left, each with its clear
flap to the right, folding over sideways. Every assertion the suite could
make — fits the page, rows do not overlap, the printable area is not taller
than the label — was true of both layouts. The acceptance test was always
the registration sheet against a window, and it had never been printed.

The class now marks the layout `[observed]` beside `[published]` and
`[derived]`, carries a `flap_w` per stock, and `fitsPage()` measures the
flap too. The registration sheet draws the flap as a second outline with the
fold line between, and its instructions moved INTO the flap column because
the first label now starts 8.7 mm from the top of the page. `placeText()`
is the one place that decides "row 3" versus "row 3, column 2", so a
one-column sheet never says "column 1" on any of the three screens that
name a position.

**The owner's box says 00767.** Avery's site has no 00767; 00757 is the
1-1/32 × 3-1/2 in, 10-per-sheet Easy Align, and the stock key
`avery_00757` is on every batch already minted, so it stayed. If the number
on the box really is 00767, the display name in `LabelStock` is one string
to change, and the geometry is the same sheet.

### 2.4 The suite passed on PHP 8.4 and failed on PHP 8.2, and the host is 8.2

The first push of this phase was red on both database jobs in CI and green
here, on the same code and the same suite, for one reason: the machine this
phase was built on runs PHP 8.4 and the host runs 8.2.33, which is also
what CI pins (hosting §10). `openssl_pkey_new(['ec' => ['x' => …, 'y' =>
…]])` returns a key object on both; on 8.2 OpenSSL then refuses to use it
("Don't know how to get public key from this private key"), and every
ECDH derivation and every VAPID verification failed there while the RFC
vector matched byte for byte on 8.4.

`Push\Vapid` now builds both halves as DER wrapped in PEM — RFC 5480
SubjectPublicKeyInfo for the public key, RFC 5915 ECPrivateKey for the
private one — which is a few fixed bytes around the point and the scalar
and is read by every OpenSSL. The only `openssl_pkey_new()` left is the
one that generates a fresh key from a curve name, which is fine
everywhere. The vector still reproduces exactly.

The lesson is the Phase 15 handoff's §8 addition, read literally: **a
failure that only happens under a setting the development environment
does not share survives every test that runs in the development
environment.** PHP's minor version is such a setting. Until a session can
run 8.2 locally, the first push of anything touching `openssl_*`,
`mb_*` or date handling should be treated as the test, and CI's verdict as
the one that counts.

---

## 3. Phase 17 — what is left

Everything the Phase 16 handoff's §3 carried from earlier phases still
stands (the tag's unshown history, the identical named labels, the silent
truncations, the cell number, the batch log form), and of its own five,
three remain: **§3.1** (a chip at 320 px reads "Wa…" — a design question),
**§3.3** (two meanings of "coming up" — documented, not changed) and
**§3.4** (what else "Start another" should carry — the walk decides). Phase
16 adds four of its own.

### 3.1 The walk, for the timer

Three questions only a phone can answer, unchanged from the Phase 15
handoff: does iOS ask for permission from the home-screen app and not from
Safari; does the subscription survive a week of the app not being opened;
and do `web.push.apple.com` and `fcm.googleapis.com` answer from sh193.
The first timer with a phone subscribed is the test, and a failure lands in
three places (§5). If the push never arrives and `fire_error` is empty, the
subscription is dead and the service did not say so — mark it by hand and
re-subscribe, and consider whether `push_subscription.last_used_at` older
than a month should be treated as gone.

### 3.2 A timer for a container

A timer takes a garden and, optionally, a zone. A container has a
recommendation of its own on the MOTD but no zone and no "garden actions"
page to start from, so it gets no button. The row could carry a
`container_id` the way `watering_recommendation` does; the question is what
the notification should log, because a container watering is a plant
event, not a garden event.

### 3.3 The MCP server could take a scope

`carl://export/summary` is the whole season. `Analysis\Document` already
takes a `Scope` (one garden, one planting); a resource template
`carl://export/summary/garden/{id}` would be twenty lines. Nobody has
asked, and a conversation can reach the same place through `plant` and
`garden_actions`, so it waits for the ask.

### 3.4 OAuth, if claude.ai's connectors are ever wanted

Claude Code is served by the bearer. Claude.ai's own custom connectors —
Desktop, web — want OAuth 2.1 with dynamic client registration, PKCE and a
consent screen, which is a phase's work and a separate decision about
whether Carl wants to be an authorisation server at all. Not started;
nothing in the bearer design precludes it.

---

## 4. What must not regress

Everything in `PHASE-16-HANDOFF.md` §4 still applies. Phase 16 adds ten.

1. **The MCP endpoint never opens a stream and never sets a cookie.** A
   GET is 405; a response has `Content-Type: application/json` and no
   `Set-Cookie`. `28_mcp_test.php` pins both, and hosting §3 is why.
2. **Every tool reads through a user-scoped repository.** The isolation
   test tries every tool that takes an id with a stranger's id and expects
   an `isError` result that names nobody. A tool with its own SQL is the
   bug this exists to catch.
3. **The bearer is resolved in `App::guard()`, not in the controller**, and
   `Auth::assume()` touches no session. Moving it into the controller
   would put a session start in front of it.
4. **`.htaccess` re-exports `Authorization`.** Remove those two lines and
   every token is "unauthorised" on LiteSpeed, and only on LiteSpeed; the
   suite cannot see it.
5. **`WebPush::encrypt()` reproduces RFC 8291 Appendix A exactly**, and the
   receiver side decrypts the live path. A change to the HKDF info strings,
   the record size, the delimiter or the header layout fails the vector,
   which is the point of having one.
6. **The timer cron compares `ends_at` against the application clock**, so
   a frozen clock in the suite fires exactly the timers it means to; and
   the claim is a compare-and-swap on `fired_at`. `29_timers_test.php`
   fires the same instant twice and expects one firing.
7. **A whole-garden timer fans out to nothing** — the form's rule (handoff
   §4.7). Making it fan out "for consistency" double-counts every plant on
   the next report.
8. **`Vapid::ensure()` never overwrites**, and nothing but `/setup` calls
   it. A key regenerated on a page load would silently orphan every phone.
9. **`LabelStock` 00757 is one column of ten with `flap_w` equal to
   `label_w`**, pinned in `21_tags_test.php`. "Correcting" it back to two
   columns prints five codes into the flaps.
10. **EC keys are built as PEM, never from raw coordinates.** §2.4. An
    `openssl_pkey_new(['ec' => [...]])` with `x` and `y` in it passes the
    suite on PHP 8.4 and fails on the host's 8.2, and CI is the only place
    that will say so.

---

## 5. Owner actions outstanding

The lists in `PHASE-16-HANDOFF.md` §5 and its predecessors stand — apply
025, read `allow_url_fopen` off `/status`, enter the emitter figures on a
real zone, print `/calendar.pdf`, open the drawer in sunlight, enter a real
tray with "Start another". Phase 16 adds six, and the first three are the
deploy.

1. **Apply migrations 026 and 027** at `/setup?key=` straight after the
   file copy. The garden actions page and the main menu are 500 until 027
   runs. The same press generates the push key pair; the flash says so.
2. ~~**Add the per-minute cron entry** from `deploy.md` §7.~~ **Done
   2026-09-02.** The cPanel cron table now matches §7's seven rows exactly:
   the per-minute timer job is in, the hourly `analysis_run.php` at minute
   40 is in (it had been missing since Phase 5, so the Recommendations
   queue had never drained), and the two stale rows every handoff since
   Phase 4 asked to delete — the `15 6` duplicate weather sync and the
   `17 8` spike-3 `--verbose` job — are gone. `carl-app/var/cron-test.log`
   can go too. After the deploy, open `/status?key=`: `TIMERS` should say
   `push key present` and, after the first timer finishes, a `last fired`
   time. `overdue` above zero means the timer entry has stopped running.
3. **Comment `setup_key` back out.**
4. **Print the 00757 registration sheet** — mint one sheet, open its
   registration test, plain paper, 100% — and hold it against the film. The
   right-hand outline of each pair must sit on the clear flap and the line
   between the two on the fold. If the ten outlines drift down the sheet
   the row pitch is wrong; if they all sit low or high the top margin is;
   if the pair sits left or right of the film the 0.75 in side margin is.
   Each is one number in `LabelStock`, marked `[derived]`. **This is the
   first time this stock's constants will have been measured.**
5. **Start a timer with a phone subscribed.** From the home-screen app:
   Garden actions, "Notify this phone", allow, then a two-minute timer. The
   push should arrive within a minute of the end; if it does not, the timer's
   landing page says how you were told and what went wrong, `fire_error`
   on the row says the same, and `bin/timers_fire.php --verbose` from a
   browser twin (`/tasks/timers-fire?key=`) prints the log. The email goes
   regardless.
6. **Mint a token on Connect Claude Code, paste the `claude mcp add`
   line, and ask Claude Code what is growing.** `list_plants` is the first
   thing it should reach for. Revoke the token afterwards or keep it; the
   screen shows when it was last used either way.

---

## 6. Claude Design outstanding

Unchanged from `PHASE-16-HANDOFF.md` §6. Phase 16 adds three CSS rules and
no colour and no token: `.token` and `.snippet` on the Connect screen
(surface-sunk, scrolling), `.timer-start` for the row of one-tap buttons
under a watering line, and `.push-controls .btn[hidden]`. Two things to
put in front of Claude Design:

- **The one-tap timer buttons are secondary buttons in a list item.** Two
  or three zones make a row of them under the sentence; on a 380 px screen
  they wrap. Whether a timer wants a control of its own — the countdown or
  progress element the Phase 15 handoff §6 foresaw — is theirs.
- **The token box.** A 103-character string that must be copied whole, in
  a scrolling mono box, on a phone. It works; it is not designed.

---

## 7. Where the bodies are buried

Everything in `PHASE-16-HANDOFF.md` §7 still applies. Phase 16 adds nine.

- **`Client::send()` now clears every `HTTP_*` key in `$_SERVER` between
  requests** except the user agent and the host. Before Phase 16 nothing
  set one; now a bearer left behind would sign the next page in as a
  program.
- **`App::handle()` skips the truncated-POST check for bearer routes.** A
  JSON body arrives with `$_POST` empty and `CONTENT_LENGTH` set, which
  is exactly what hosting §4's heuristic reads as an over-size form.
- **The MCP `instructions` string and every tool description are read by
  a model.** They are prose about what the data means, not about the code;
  editing one for style changes what Claude infers about a column.
- **`Series` is constructed by hand in `Mcp\Tools::weather()`**, because
  the `Controller::series()` accessor is protected and Tools is not a
  controller. If Series grows a constructor argument, both places change.
- **`WebPush::pointFromScalar()` builds an RFC 5915 key with no public
  point** so openssl completes it. Only the suite reaches it (the RFC's
  sender scalar); the live path generates an ephemeral key with the point
  already known.
- **`Vapid::derToRaw()` assumes a short-form DER length**, which every
  P-256 signature has (70–72 bytes). It is not a general DER parser and
  must not be reused as one.
- **The push `Topic` header is `carl-timer` for every timer**, so a phone
  that was off for an hour gets the latest, not all six. If two timers end
  a minute apart and the phone was on, it gets both; if it was off, one.
- **`Vapid::derToRaw()` and the PEM builders assume P-256.** The SPKI
  prefix and the ECPrivateKey parameters both carry the prime256v1 OID as
  fixed bytes. A different curve is a different constant, and nothing
  checks the curve of a point it is handed beyond its length.
- **The screenshot user.** The three screens were looked at in a browser at
  380 px, light and dark, against `php -S … dev-router.php` and a user made
  by a throwaway script, as §8 asks; that is how the two-buttons-at-once
  bug was found. Neither the script nor Playwright is in the repo.

---

## 8. Working agreement

Unchanged from `PHASE-16-HANDOFF.md` §8, including every earlier phase's
addition. One addition, from §2.3:

> When a constant describes a physical thing, the test is the thing. A
> suite can prove a layout fits a page; only a sheet against a window can
> prove it fits the sheet. Say in the code which numbers were measured and
> which were derived, and treat a derived one as a hypothesis until
> somebody holds the paper up.

And the Phase 10 test, answered "no" once more — for fifteen phases of a
label sheet nobody had printed on the stock it was for:

> **Would anybody find out?**

Not from the suite, and not from the screen. The owner found out with the
label in his hand, and said so in one sentence, which was enough.
