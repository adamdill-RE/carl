# Carl The Garden Helper — Phase 18 handoff

**Phase 17 did the four things the owner asked for with the app in one hand
and a label sheet in the other**, and found, on the way, that a number every
listing prints had been read as the wrong thing for sixteen phases. A photo
no longer strands the home-screen app; days to maturity is drawn and spoken
as the window it is; the 00757 code is sized to a face that Avery's own
brochure says is 3/4 in tall, not 1-1/32; and push can be diagnosed from the
page that sets it up, with the push service's own answer on the screen. No
migration, no cron, no setup step, and nothing new from the host.

The owner's four sentences are in §2, each with what was found and what was
built. Where a fix could not be proven from here — a phone, a printer — §5
says what to do and what the page will then say.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — the authorities. Phase
   17 annotates neither, and depends on one thing they already allow: one
   deliberate outbound HTTPS call on a request path (§2.4), bounded and
   pressed by a person.
2. **`docs/CARL-HANDOFF.md`** — the specification. Phase 17 adds a paragraph
   to §10 (the photo page and viewer), a paragraph to §4.7 (the test button
   and the subscription list), two rows of the §12 table (the harvest
   reminders), and a Phase 17 entry in §14.
3. **`docs/QR-TAGS-SPEC.md` §12.9** — the 00757 face, and why every sheet
   before this one was sized to the laminate.
4. **`docs/PHASE-17-HANDOFF.md`** §2, §4, §7 — all current in full. Its §3.2
   (a timer for a container), §3.3 (an MCP scope) and §3.4 (OAuth) are still
   open; its §3.1 (the walk, for the timer) is what §2.4 and §5.2 below turn
   into a checklist with answers on the screen.
5. **`docs/deploy.md`** — the runbook. **Phase 17 adds nothing to run**; the
   "Redeploying" section leads with the two things to do with a phone and a
   printer afterwards.
6. **§8 below is the working agreement**, unchanged, with two additions.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | **27**, 46 tables — unchanged |
| Routes | **123** — Phase 17 added **two**: `GET /photos/{id}/view` and `POST /push/test` |
| Source / views | 127 PHP classes (unchanged), 62 templates (**+1**: `photos/view`) |
| Tests | **712 tests, 7,535 assertions**, green under `--strict` on MariaDB 10.11 locally (PHP 8.4; CI is 8.2 on MySQL 8.0 and MariaDB) |
| Static CI checks | 8, unchanged |
| Client shell | 43.1 KB gzipped (**+4.6 KB**: `gallery.js`, the larger `push.js`, the viewer and band CSS) |
| Cron jobs | 7, unchanged |

**A photo is a page, and a viewer.** Every thumbnail opens
`/photos/{id}/view` — the picture, the way back, previous/next — and
`gallery.js` opens the same set in a full-screen `<dialog>` where it runs.
§2.2.

**Days to maturity is a window.** "Harvest starts" and "harvest window
ends" on the calendar, with a band along every day between and "day 13 of
28" in the day panel; the same two sentences in the digest, which now reads
the county's override as the calendar always did. §2.3.

**The 00757 code is sized to the white face.** 3/4 × 3-1/4 in, from Avery's
brochure; a 15.55 mm symbol with 3.3 mm of white above and below the ink,
centred on the stake's width; the registration sheet draws the face, the
laminate, the flap and the ink's square. §2.1.

**Push is diagnosed from the page.** "Send a test notification" pushes now
and prints what the push service answered; every phone that ever subscribed
is listed with what it subscribed from; a timer that fell back to email says
why on its own page. §2.4.

Branch `claude/adoring-heisenberg-87wkov`, one commit.

---

## 2. What Phase 17 established that Phase 18 should not re-derive

### 2.1 The 00757 face is 3/4 in tall, and every listing says otherwise

The owner's sentence was "your current codes are just outside the upper and
lower boundary for the most part, and a few labels shift". The code was 18.8
mm of ink on a label the class believed was 26.2 mm tall: 3.3 mm clear above
and 4.1 below, which no inkjet feed could put over both edges. The only
geometry that fits the sentence is a label shorter than the ink, and Avery's
Easy Align brochure (GEN-0816-06, 2015 — fetched, its text extracted, its
buying-guide table read) is the one document that says so: **"LABEL SIZE 3/4
in × 3-1/4 in; LABEL SIZE WITH CLEAR LAMINATE 1-1/32 in × 3-1/2 in."** The
number on the box, on Amazon, on Staples and on Avery's own product page is
the size with the laminate. So the ink ran 18.8 mm down a 19.05 mm face:
exact on a perfect feed, over one edge on every real one. The owner's
sentence was the measurement.

What changed, and what to keep straight:

- `LabelStock` carries **two rectangles per stock**: the die (`die_w`,
  `die_h`; the laminate's footprint, what the sheet is cut to, what
  `diePosition()` returns) and the face (`label_w`, `label_h`; the white,
  what `position()` returns, all that may carry ink). The face is centred
  on the die, `[derived]`. 60517 has die = face and is unchanged to the
  millimetre.
- **`LabelStock::symbolBox()` is the one writer of where the code goes**:
  the face's height less a per-stock `edge` each side (00757: 1.75 mm, the
  sixteenth of an inch the owner saw labels land high or low, and a little
  over), capped at §2.3's 24 mm where the face is tall enough; centred on
  the stake's 25.4 mm width. `LabelSheet::label()` draws what it says; the
  registration sheet outlines the same box's ink square. On 00757 that is
  a 15.55 mm symbol: 0.379 mm modules for the lower-case URL the live site
  encodes (version 4), 0.42 mm upper case (version 3); 12.5 mm of ink;
  3.3 mm of white above and below it, 6.4 to its left.
- **0.38 mm is 1.5× ISO 18004's practical print floor** and seven pixels per
  module for a phone at 15 cm. It is smaller than the spec liked, and the
  spec's first lever — a shorter URL, or the upper-case one once the server
  is known to answer it — buys version 3 and 0.42 mm on the same face.
  §1.7's scan test is still the acceptance criterion; the print screen now
  shows the label's own module size, not the stake's.
- The write-on band on a blank label had never been drawn on either stock:
  its guard compared against a 24 mm symbol on a 25 mm face and always
  lost. It is drawn on 00757 now, inside the feed margin.

### 2.2 The home-screen app has no way back, so a photo is a page

A standalone web app on iOS has no address bar, no back and no forward. A
link to `/photos/{id}` — a JPEG response — was therefore a full-screen
photograph with no way out short of killing the app, and Safari gave no
hint of it because in Safari the back button is right there. Two answers,
layered:

- **`/photos/{id}/view`** is the photo on a page of Carl's: the picture, the
  plant or garden as the way back, previous/next through the same set the
  plant page shows (a planting's photos in `taken_on, id` order, or a
  garden's). Every thumbnail links to it; the upload preview does too. It
  works with no script, which is the point.
- **`gallery.js`** turns the same links into a `<dialog>` viewer: full
  screen on the photo ground (two new tokens; the viewer is black in both
  palettes, the way every photo app is), swipe and arrow keys along the set,
  a tap outside, Escape or the close button to leave, focus handed back to
  the thumbnail, the neighbours pre-fetched. A browser without
  `showModal()` — or a modified click — gets the page. The set is the
  gallery the thumbnail sits in: the timeline's per-event photos are one
  set, the Photos section is another.

Looked at in a browser at 380 px, light and dark, through Playwright: the
plant page, the open viewer on the first and second photo, the photo page,
Escape closing the dialog. That is how the count and caption were seen to
sit clear of the notch insets.

### 2.3 Days to maturity is one fact with two ends

"18 to 45 days" had been two "Harvest" chips with nothing between them: two
events, to a reader, and the first read as a promise. It is one fact — the
first radishes at 18 days, the last worth pulling at 45, every day between a
day to look — and Phase 17 draws and speaks it as one:

- **`Calendar::harvestEntries()`** names the ends: "Radish harvest starts"
  (chip *Harvest starts*) at anchor + min, "Radish harvest window ends"
  (chip *Harvest ends*) at anchor + max, each carrying the whole span in its
  detail: "Days to maturity 18 to 45 from sowing on 1 May 2026: the window
  runs 19 May 2026 to 15 June 2026." One figure, or two the same, is one
  date and is said as it always was, with a sentence saying why there is no
  window. Only the far end known is still one date.
- **`Calendar::harvestWindows()`** is the span itself, for the band the
  grid draws along the top of every day inside a window (dashed, so it is a
  pattern and not only a colour; one new token, `--carl-harvest`, the warn
  amber by value) and for the day panel's "In the harvest window" list:
  "day 13 of 28", the dates, the count, and the plant. The plant filter
  applies to windows as to entries. `datesInWindows()` and `windowsOpenOn()`
  are the two lookups; the controller calls them once.
- **The digest says the same.** `first_harvest_expected` is "harvest starts
  in a week / about now" where there is a window and "should be ready"
  where there is not; `harvest_window_closing` is "harvest window ends in a
  week / about now" at anchor + max, said whether or not anything was
  picked, and the fortnight-later "nothing harvested yet" nudge unchanged.
  Same kind, two due dates, so the unique key keeps them apart.
- **A bug the invariant hid.** The calendar's docblock promises it never
  disagrees with the digest about the date of the same harvest, and the
  test pinned the two to the same answer — for a planting with no region
  override. The two harvest rules in `ReminderBuilder` read the catalogue
  value only; `Calendar::dtm()` and the succession rule read the county's
  override. In a county whose research overrode a type's days to maturity
  the chip and the reminder disagreed. `ReminderBuilder::dtmRange()` now
  reads what the calendar reads, and the digest tests apply the override
  the way the rule does.
- The plant page's countdown rows read "Harvest window opens" and "Harvest
  window ends", or "Harvest expected" for one figure.

### 2.4 Push: nothing wrong in the send path, and no way to know that from the phone

The owner's report was "nothing is coming up" on an iPhone 17 Pro Max on
iOS 26.6 with the app on the home screen. Everything between "Notify this
phone" and the lock screen was audited against Apple's requirements —
`Authorization: vapid t=…, k=…` with the audience the service's origin, a
`mailto:` subject and a twelve-hour expiry; `TTL`, `Urgency`, `Topic`,
`Content-Encoding: aes128gcm`; the declarative body with `web_push: 8030`,
a title and a `navigate`; the key built as PEM for PHP 8.2 — and nothing in
the send path is wrong. Which is the finding: the failure is on the phone's
side of the wire or in the flow, and Carl had no way to say which. Six
things sit between the button and the lock screen, and every one of them
failed silently:

1. The page was opened in **Safari, not the home-screen app** — Safari
   itself cannot subscribe, and its user agent looks like any iPhone's.
2. **Permission was refused once** and iOS remembers it per icon.
3. The subscription was made but **the push service refused the push**
   (`BadJwtToken`, `VapidPkHashMismatch`, a 400 for a dead token) — the
   reason was in a cron log nobody reads, and the timer's row said only
   "email".
4. The service **accepted** the push and **the phone silenced it** —
   Settings › Notifications, or a Focus mode.
5. The subscription **died quietly** — a reinstalled icon — and nothing
   was retried.
6. **The cron never fired the timer** — `/status` says so, but only there.

What was built answers 1–5 from the garden actions page:

- **`POST /push/test`** pushes to this phone now and prints what the push
  service answered — "Apple (web.push.apple.com) answered 201: the
  notification is on its way" or "answered HTTP 403 BadJwtToken" — and
  marks a 404/410 as gone. `WebPush::reason()` reads the service's own
  reason out of its body (Apple's `{"reason":…}`, Google's
  `error.message`, Mozilla's `message`). The one third-party call on a
  request path in Carl, on purpose and bounded: five phones, a ten-second
  socket, a person pressing it.
- **The subscription list** names every phone that ever asked, live or not:
  the device and browser (`PushSubscriptionRepository::deviceName()` — a
  home-screen web app on iOS sends a user agent *without* the `Safari/`
  token, so "iPhone, home-screen app" and "iPhone, Safari" are told apart,
  and the second is the fault nine times in ten), the push service, when a
  push was last accepted, and why it stopped.
- **`fire_error` carries the push's failure** when the mail went instead —
  "push: Apple (web.push.apple.com): HTTP 403 BadJwtToken — emailed
  instead" — and the timer's landing page shows it. It is not counted as a
  failure of the timer, because the mail went.
- **`push.js` says what is actually wrong**: Safari-not-the-app, with the
  steps; permission denied, with the iOS fix (remove the icon and add it
  again); after subscribing, which service the phone is on; and the test
  button's answer, with the Settings and Focus reminder when the service
  said yes.

One thing happened during the build that is worth more than the audit: the
suite pushed to `web.push.apple.com` for real (§2.5), and Apple answered
`400 BadDeviceToken` for the fake token — through the reason parser, on
the page, in words. Outbound HTTPS to Apple's push service works from this
sandbox and Apple's answers come back readable. Whether it works **from
sh193** is still the owner's walk (Phase 17 handoff §3.1), and the test
button is now the instrument: a curl error in its answer is that question
answered.

### 2.5 The suite pushed to Apple for real, once

`App::pushTransport()` was first an instance property, set by the test on
the suite's `App` and read by `TimerService`. The test client builds a
fresh `App` for every request it sends (`tests/Client.php`), so the request
path never saw the override, `PushController::test()` fell through to curl,
and the suite made a real HTTPS POST to Apple from inside a test. It is
static now, and its docblock says why. The lesson is the Phase 15
handoff's §8 addition read the other way round: **a test that can reach the
internet will, one day, and the transport a suite installs has to be
installed where the code under test will look.**

---

## 3. Phase 18 — what is left

Everything the Phase 17 handoff's §3 carried still stands: a timer for a
container (§3.2 there), the MCP scope (§3.3), OAuth (§3.4), and the older
ones (the tag's unshown history, the identical named labels, the silent
truncations, the cell number, the batch log form). Its §3.1 — the walk —
is §5.2 below with answers on the screen. Phase 17 adds five of its own.

### 3.1 A chip that reads "Ha…" now says less than it did

Pre-existing (Phase 16 handoff §3.1), and the longer labels make it plainer:
at 380 px a cell is 47 px wide and *Harvest starts* and *Harvest ends* both
truncate to "Ha…". The band says which days are inside; the panel says
everything; but two chips that read the same on the grid are the old
problem with a new cause. The cheap fix stands: a dot under 360 px with the
label in the panel. A design question as much as a CSS one.

### 3.2 The calendar PDF has no band

The sheet's "Coming up" list carries the new titles and details, so the
window is on paper in words, but the grid on paper has no band between the
two chips. `CalendarSheet` is black-on-white and shape-only; a dashed rule
along the top of a cell would follow its rules and is about twenty lines.

### 3.3 A phone that says "iPhone, Safari"

The list names the fault; nothing yet removes it. A Safari subscription is
never going to ring, and "Notify this phone" from the home-screen app makes
a second row rather than replacing the first. Whether Carl should drop a
subscription whose user agent says Safari on iOS, or say so beside it, is
the walk's to decide.

### 3.4 Old subscriptions

Carried from the Phase 17 handoff §3.1 and still true: a subscription whose
`last_used_at` is older than a month and whose service never said 410 is
probably dead. The test button will say so on demand; nothing says so
unasked.

### 3.5 The lightbox does not pinch

`<dialog>` shows the picture at the viewport's size and `object-fit:
contain`; a pinch zooms the page, not the photo. The photo page
(`/photos/{id}/view`) zooms as any page does, so a reader who wants detail
has it one tap away, but the viewer itself is look-only.

---

## 4. What must not regress

Everything in `PHASE-17-HANDOFF.md` §4 still applies, with one entry
rewritten: its **9** ("`LabelStock` 00757 is one column of ten with `flap_w`
equal to `label_w`") is now "with `flap_w` equal to `die_w`", because the
label's own width is the face and the flap is the laminate's size. Phase 17
adds nine.

1. **The 00757 face is 19.05 × 82.55 mm inside a 26.194 × 88.9 mm die.**
   "Correcting" `label_h` back to 1-1/32 in prints the code onto the
   laminate again. `21_tags_test.php` pins both rectangles and that the
   face is centred on the die.
2. **`LabelStock::symbolBox()` is the only place that decides where the code
   goes.** `LabelSheet::label()` draws it and the registration sheet
   outlines it; a sheet that computed its own size would put the outline
   and the ink in different places, which is the one thing the
   registration sheet exists to rule out. The test pins 3 mm of white
   round the ink on 00757 and 24.0 mm / 0.7 mm on 60517, unchanged.
3. **A thumbnail never links to the raw JPEG.** `/photos/{id}` is served
   for the viewer's `<img>` and nothing else; the link is `/photos/{id}/view`
   and `gallery.js` falls back to it. The flow test pins the plant page's
   links and the page's 404 for a stranger.
4. **The harvest window's two ends are the digest's two dates.** `23` pins
   the chips to anchor + min and anchor + max through `dtmAnchor()`; `10`
   pins the digest's wording on the same dates with the override applied.
   A rule that re-derived either — or read the catalogue value only, as
   the digest did until Phase 17 — is the drift the calendar's docblock
   promises against.
5. **`ReminderBuilder::dtmRange()` reads the override.** §2.3. Putting the
   catalogue value back reintroduces a silent disagreement in every county
   whose research overrides a type.
6. **`App::pushTransport()` is static.** §2.5. An instance property passes
   every test and pushes to Apple for real from the suite.
7. **`fire_error` records the push service's answer when the mail goes
   instead**, and it is not a failure of the timer. `29` pins the row, the
   landing page, and that the subscription stays live after a 403.
8. **`POST /push/test` is the one third-party call on a request path**, and
   it stays bounded (five phones, ten seconds) and behind a button. It is
   not a precedent for weather, mail or analysis on a page (Phase 3
   handoff §4.1).
9. **`deviceName()` tells a home-screen iPhone from Safari by the absence of
   `Safari/`.** A "fix" that names every iPhone's browser Safari removes the
   one line on the page that explains most of the silence.

---

## 5. Owner actions outstanding

The lists in `PHASE-17-HANDOFF.md` §5 and its predecessors stand — apply
025, read `allow_url_fopen` off `/status`, enter the emitter figures on a
real zone, print `/calendar.pdf`, mint a token on Connect Claude Code.
Phase 17 replaces its §5.4 and §5.5 with these, and adds three.

1. **Print the 00757 registration sheet again** — plain paper, 100% — and
   hold it against the film. The heavy outline is the white face and must
   sit on the white; the thin one round it is the laminate's footprint;
   the small square inside is where the ink lands, and it should have
   white all round it (3.3 mm above and below by the numbers). If the
   outlines drift down the sheet the pitch is wrong; all high or low, the
   top margin; left or right of the labels, the side margin. The sheet
   names each number. **The face's position inside the laminate is
   `[derived]`** — centred — and this is its first measurement.
2. **Push, on the phone, in this order**, and read what the page says at
   each step. Remove any earlier Carl icon from the Home Screen first if
   notifications were ever refused on it. Safari › Share › Add to Home
   Screen › open Carl from the icon › sign in there › Garden actions ›
   "Notify this phone" › Allow › "Send a test notification". The page then
   prints Apple's answer. `201` and no notification within a minute:
   Settings › Notifications › Carl, and any Focus mode. Anything else is
   named, and the list under the button says which phone subscribed from
   where: **"iPhone, Safari" is the wrong place.** A curl error in the
   answer is the Phase 16 question — does `web.push.apple.com` answer from
   sh193 — answered no, and the host's to fix.
3. **Print one 00757 sheet and scan a code with the phone**, from 15 cm,
   in the garden, after a week. §1.7 of the tag spec is still the
   acceptance test, and the module is smaller than it was (0.38 mm). If it
   is marginal, the lever is the upper-case URL (`tags.uppercase_url`,
   after checking the server answers `/CARL/T/…`), which buys 0.42 mm on
   the same face.
4. **Open the calendar during a harvest window** — any planting whose type
   gives two figures — and tap a day inside it. The band, the panel's "day
   n of m" and the two named ends are what §2.3 built; whether the chip's
   truncation (§3.1) is worth a dot instead is the walk's call.
5. **Open a photo from the home-screen app.** The thumbnail should open
   the viewer; the × should close it into the page; swiping should move
   along the plant's photos. Kill the app and open a photo's own page from
   a link to see the no-script path.

---

## 6. Claude Design outstanding

Unchanged from `PHASE-17-HANDOFF.md` §6. Phase 17 adds three tokens —
`--carl-photo-ground` and `--carl-photo-ink` (the viewer; black in both
palettes, deliberately) and `--carl-harvest` (the band; the warn amber by
value, a name of its own so it can move) — and the CSS for the viewer, the
photo page and the band. Three things to put in front of Claude Design:

- **The viewer's chrome.** Four opaque 44 px circles in the viewer's own
  ink on its own ground, inset from the notch. They are legible over any
  photo and they are not designed.
- **The band and the two chips.** Amber, dashed, 4 px along the top of a
  cell, with *Harvest starts* / *Harvest ends* truncating to "Ha…" at 380
  px (§3.1). The pattern is deliberate; the colour is borrowed.
- **The subscription list.** A `.list` of "iPhone, home-screen app, through
  Apple (web.push.apple.com)" rows with a hint line. It says what it needs
  to; it reads as a diagnostic.

---

## 7. Where the bodies are buried

Everything in `PHASE-17-HANDOFF.md` §7 still applies. Phase 17 adds ten.

- **`LabelSheet::inches()` rounds to thirty-seconds** for the registration
  sheet's text (19.05 → "3/4", 26.194 → "1-1/32"). It is for that text and
  not for arithmetic.
- **`LabelStock::STAKE_W` (25.4) and `TagUrl::TAG_FACE_MM` (24.0) are two
  numbers about one stake**: the width the code is centred on, and the
  cap on its size. They are not the same number and should not be merged.
- **The registration sheet's instructions are painted last, on a white
  panel** in the flap column, so they cover three flap outlines rather than
  print across them. The first flap keeps its "fold" label.
- **`WebPush::reason()` prefers `reason`, then `message`, then
  `error.message`**, then the stripped text of the body, cut to 120
  characters. A service whose error is a bare string gets the string.
- **`TimerService::sendTo()` touches and marks as it goes**, so a test push
  that gets a 410 kills the subscription exactly as a timer's would. The
  test button's answer says so.
- **The push transport override is process-wide** (§2.5); a test that
  installs one must uninstall it in a `finally`, and `29` does.
- **`Calendar::countedFrom()` delegates to `ReminderBuilder::countedFrom()`**
  so there is one writer of "sowing on 1 May 2026" — the same reason
  `dtmAnchor()` lives on the digest's class.
- **The photo page's placeholder buttons** (`.photo-nav-off`) are
  `visibility: hidden` spans so "Next" does not jump across the page on the
  first photo. They are not focusable, on purpose.
- **`gallery.js` closes on a click on the `<figure>`** as well as on the
  dialog's backdrop, because the figure fills the dialog and the backdrop
  is only ever a few pixels of it.
- **The screenshot user and Playwright** — a throwaway seed script and a
  node script driving the pre-installed Chromium at 380 px, light and dark
  — are not in the repo, as §8 asks. The subscription list, the timer
  landing page, the calendar band and panel, the viewer and the photo page
  were all looked at that way, and the two-buttons-at-once class of bug
  was looked for and not found.

---

## 8. Working agreement

Unchanged from `PHASE-17-HANDOFF.md` §8, including every earlier phase's
addition. Two additions.

From §2.1:

> A published size is the size of *something*, and a listing does not say
> what. Before a number from a box goes into a constant, find the document
> that says which edge it measures — the brochure, the die drawing, the
> template — and put the document's name in the comment beside it.

From §2.5:

> A test that can reach the internet will, one day. Where the suite
> installs a stand-in for a network call, it installs it where the code
> under test will look — not on the object the test happens to be holding
> — and it uninstalls it in a `finally`.

And the Phase 10 test, answered "no" once more — for a sixteen-phase
constant that every listing agreed with:

> **Would anybody find out?**

Not from the suite, which proved the layout fit the page. Not from the
registration sheet, which drew the laminate's outline on the laminate. The
owner found out with the label in his hand, said so in one sentence, and
the one document that agreed with him was a brochure from 2015.
