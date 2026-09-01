# Carl The Garden Helper — Phase 12 handoff

**Phase 11 is one feature wide.** The specification has had nothing unbuilt in
it since Phase 10, so this is the first phase that is entirely somebody's
choice: a tweak to the photo field, asked for in the field.

It is also the first phase that fixed something by reading an attribute
properly. `capture="environment"` had been on the photo input since Phase 2
and nobody had noticed what it does.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 11 annotated neither.** It leaned on §4 (the 2 MB
   `upload_max_filesize` that shapes the client-side resize) and §9 (the
   150 KB shell budget) and both were right and complete.
2. **`docs/CARL-HANDOFF.md`** — the specification. §10 is photos. **There is
   still nothing unbuilt in it**; Phase 11 does not change that either way,
   because the camera was never in it.
3. **`docs/DESIGN-NOTES.md`** — the palette. Phase 11 adds no colour and takes
   none. It uses `--carl-accent` once, as the focus ring, which is the only
   thing §4 item 8 of the last handoff permits it to be.
4. **`docs/PHASE-11-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current in full; §4 gains four entries in §4
   below and §7 gains three in §7.
5. **`docs/deploy.md`** — the runbook. Phase 11 adds **no migration, no cron,
   no route and no file at the web root**. It is a file copy and nothing else:
   four changed files under `public/assets` and `app/views`, one new file
   under `tests/`. There is no `.cpanel.yml` change.
6. **§8 below is the working agreement**, unchanged.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 23 (`001`–`023`), 42 tables — **Phase 11 added none** |
| Routes | 106 — **none added** |
| Source / views | 111 PHP classes (**+0**), 57 templates (**+0**) |
| Tests | **582 tests, 6,543 assertions**, green under `--strict` on MariaDB 10.11 |
| Static CI checks | **8, up from 7** — `check_photo_capture.php` is new |
| Client shell | **26.4 KB** gzipped against 150 KB — up from 24.6, CSS and JS |

**Every screen that attaches a photo now offers two buttons: Choose photos,
and Take a photo.** All three of them — the log form (`/log/{id}`), a new
planting (`/plants/new/{kind}`) and garden actions
(`/gardens/{id}/actions`) — because all three include one partial, and a
static check now says they must go on doing so.

The case it serves is the one it was asked for: go plant to plant. Scan the
tag, log what you saw, take the picture *there*, instead of shooting a
morning's worth of photos and then trying to remember which tray was which.

---

## 2. What Phase 11 established that Phase 12 should not re-derive

### 2.1 `capture` is not a capability flag. It is an instruction.

This is the whole phase, and it is worth stating flatly because the attribute
is named as if the opposite were true.

`capture="environment"` does **not** mean "a camera is available, offer it
alongside the other sources". It means **skip the file picker and open the
camera**. An input carrying it offers the camera *instead of* the camera roll
on a phone, and one input cannot be made to offer both — there is no
attribute, no value and no combination that does it.

The input had carried `capture` **and** `multiple` since Phase 2, which is
the ambiguous case: the two attributes pull against each other, browsers have
disagreed about which wins across versions, and the answer has changed under
people who never touched the file. Whatever any given phone did with it, it
was never doing both.

So there are two inputs, and the split is the feature:

| | `multiple` | `capture` |
| --- | --- | --- |
| `#photo-input` — the roll | **yes** | **never** |
| `#photo-camera` — the camera | **never** | `environment` |

`multiple` is off the camera input because a capture returns one photo, and
on the roll input because a morning of photos should still attach in one go.

### 2.2 The bug was invisible on every machine anybody develops on

`capture` is **ignored by desktop browsers**. So the attribute's entire
effect — good or bad — happens only on a phone, and its cost was paid in a
garden by somebody who could not find their photos any more. Nothing was ever
going to report it. No console error, no failing test, no support ticket that
reads like a bug rather than like confusion.

That is why `tests/check_photo_capture.php` exists and why it is a **static**
check: merging the two inputs back into one is a one-line edit, it looks like
tidying up a duplicated control, it passes every other test in the repository
and it renders perfectly on a laptop. It is precisely the Phase 10 test —
*not "is this likely" but "would anybody find out"* — and the answer is no.

The check pins four things, and each is a separate silent failure:

- `capture` on the roll input → the camera roll is gone on Android.
- `multiple` on the camera input → the two attributes fight again.
- A file input with no label → the inputs are `.sr-only`, so the label *is*
  the control; without one there is an invisible button.
- A label that is not its input's next sibling → `carl.css` borrows the focus
  ring with `input:focus-visible + label`, so reordering them leaves a
  control that works perfectly with a mouse and has **no visible focus at
  all** with a keyboard.

All four were confirmed to fail the check before the check was trusted.

### 2.3 Making the camera a button makes EXIF orientation everybody's problem

A photo from a camera is stored in the sensor's orientation with a tag saying
which way up it goes. Portrait is how a phone is held at a plant, so the new
path produces rotated photos as the *common* case rather than the rare one.

Nothing downstream was going to straighten one. `createImageBitmap`'s default
for `imageOrientation` has moved across spec vintages — older browsers ignore
the tag, newer ones honour it — so the answer depended on the phone. And
**GD ignores EXIF orientation too**, so a photo that arrived sideways was
stored sideways, in both the full size and the thumbnail, forever.

Both decode paths (`photos.js` and `resize-worker.js`) now ask for
`imageOrientation: 'from-image'` by name. A browser that does not know the
value **rejects the promise rather than throwing**, which is why each call is
wrapped in both a `.catch` and a `try`.

This was not part of the request. It is in the change because the request is
what makes it bite.

### 2.4 The server is what decides that a file is an image, so the phone should stop guessing

`photos.js` refused anything whose `file.type` did not start with `image/`.
Some Android captures arrive typed as **nothing at all**, which that test
reads as "not an image" — so the new button would have failed on exactly the
phones it was built for, with the message "Only images can be attached."

The check now refuses only a type that is *present and* not an image. An
absent one goes to the server, which has always been the real authority:
`getimagesize` plus a three-entry mime whitelist plus a megapixel cap, in
`Carl\Support\Photos::store()`. Nothing was loosened — the client check was
never the thing keeping a text file out.

The same reasoning caught a smaller one: a capture can arrive with **no
filename**, and stripping the extension off `""` left a file called `.jpg`.

### 2.5 A docblock that has been wrong for nine phases

`photo_uploader.php` said, since Phase 2:

> Without JavaScript the file input is still here and still works; it just
> posts one photo with the form and relies on the server-side resize.

**None of that is true and it never was.** The three forms that include the
partial carry no `enctype="multipart/form-data"`, so a browser posts the
*filename* and not the file; and their controllers read `photo_ids[]`, never
`$_FILES`. Without JavaScript no photo is attached, and no photo ever was.

The docblock now says so. **It was not "fixed" into truth**, and that was a
deliberate call: making it true means an `enctype` on three forms and a
`$_FILES` path in three controllers, which is a real feature with a real
failure mode (a photo posted alongside the other fields is the thing the
whole one-photo-per-XHR design exists to avoid — hosting §4,
`post_max_size`), and nobody has asked for it. §3.1 below carries it.

The general lesson is the Phase 10 one pointed at prose instead of code: **a
comment claiming a fallback works is worth the same grep as a rule claiming
only one place does something.** Both are load-bearing, both are believed,
and neither is executed.

---

## 3. Phase 12 — what is left

Nothing here is spec. Everything from `PHASE-11-HANDOFF.md` §3 is carried
unchanged and is not reproduced: the whole-sowing report (**still the oldest
unbuilt thing in the project**, and still the most obviously ready to build),
reminder pagination and roll-up, the four Recommendations items, a second
region, the catalogue's other half and template version 3, and the six design
items in its §3.6. Phase 11 touched none of them.

Two things are new, and both come out of §2.5.

### 3.1 The no-JavaScript upload path does not exist, and now that is written down

Described in §2.5. If it is ever wanted, it is: `enctype="multipart/form-data"`
on `plants/log.php`, `plants/form.php` and `gardens/actions.php`; a
`$request->file('photo')` branch in the three controllers that
`afterRecord()`-style code already has a hook for; and an acceptance of the
thing the current design avoids, which is a photo travelling in the same POST
as the form. **`Carl\Support\Photos::store()` is already the whole back half**
— it is what `/photos` calls, and it neither knows nor cares how the file
arrived.

The honest question first is whether it is wanted at all. Every other feature
in this application works without JavaScript; photos are the one exception,
and they have been the exception since Phase 2 without anybody noticing,
which is itself an answer of a kind.

### 3.2 A camera button on a device that ignores `capture`

`photos.js` removes the camera control when the pointer is not coarse and
there are no touch points, because on a desktop `capture` is ignored and the
button would open the same file dialog as the one beside it — two controls
doing one thing, which reads as a bug.

**The heuristic is deliberately cheap and deliberately biased.** A touchscreen
laptop keeps a button it does not need, which costs nothing; the reverse
would take the camera away from a phone, which costs the feature. If it ever
needs to be better, the thing to reach for is not a longer heuristic but the
`MediaDevices` API — and that is a permission prompt, a 150 KB budget and a
CSP question, which is three prices for a cosmetic problem.

### 3.3 What is still untested on a real phone

Both of these need a device and neither can be tested any other way:

- **The camera button itself**, on iOS Safari and on Android Chrome. The
  attributes are asserted; what the *operating system* does with them is a
  field test. This is the same shape as Phase 10's "dark mode is untested on
  a real phone at dusk", and joins it.
- **Whether a portrait photo now lands upright.** §2.3 makes the behaviour
  deterministic rather than dependent on the browser's spec vintage; whether
  the deterministic answer is the *right way up* on a given phone is a
  photograph of a plant, taken sideways, looked at.

---

## 4. What must not regress

Everything in `PHASE-11-HANDOFF.md` §4 still applies. Phase 11 adds four.

1. **`capture` never goes on the input that has `multiple`, and `multiple`
   never goes on the input that has `capture`.** They are two inputs for a
   reason (§2.1) and merging them is the regression this phase exists to
   prevent. `check_photo_capture.php` fails the build for either.
2. **Both file inputs keep an `id` and a `<label for>` that is their
   immediately next sibling.** The inputs are `.sr-only`, so the label is the
   only visible control, and `carl.css` borrows the focus ring across with
   `input:focus-visible + label`. Reordering them loses keyboard focus in
   silence.
3. **`partials/photo_uploader.php` stays the only place a photo input is
   declared.** A second one anywhere else gets no camera button and none of
   these checks; the static check scans all 57 views for
   `accept="image/*"` and names any stray. It is recursive on purpose —
   `app/views/*.php` and `app/views/*/*.php` are both real, and a shallow
   glob skips eight files.
4. **Both decode paths keep `imageOrientation: 'from-image'`, and keep both
   the `try` and the `.catch` around it.** §2.3. An unknown enum value
   rejects the promise rather than throwing, so one guard is not enough, and
   GD will not straighten what arrives sideways.

---

## 5. Owner actions outstanding

**Twelve, unchanged, and none has been performed.** The list is in
`PHASE-10-HANDOFF.md` §5 and is not reproduced here for the fourth time.

**§5.1 is still the one platform fact not established**: outbound HTTPS to
`api.anthropic.com` has never been tried from sh193, carried unchanged
through Phases 6–11. Five minutes, safe, and the only thing between
Recommendations and "known to work on this host".

Phase 11 adds none, and asks for nothing: the change is four files and a
test, it ships on the existing `cp -R public/.`, and there is no migration to
run.

**One thing genuinely wants a person, though, and it is not a task so much as
a walk:** §3.3. Take a phone to a plant, scan the tag, log something, press
Take a photo, and look at which way up it comes out.

---

## 6. Claude Design outstanding

**Nothing**, for the second phase running.

Phase 11 adds no colour, no icon and no artwork. The two buttons are
`.btn btn-secondary`, which already existed; the focus ring is
`--carl-accent` used as a focus ring, which is the only use §4 item 8 of the
last handoff allows. Nothing here needed designing and nothing here should be
read as a design decision.

---

## 7. Where the bodies are buried

Everything in `PHASE-11-HANDOFF.md` §7 still applies. Phase 11 adds three.

- **`glob('app/views/**/*.php')` does not recurse.** It matches exactly one
  directory level, so it sees 49 of the 57 views and silently misses every
  top-level one — `menu.php`, `login.php`, `layout.php` and five others. The
  first draft of `check_photo_capture.php` had this bug, which meant a stray
  photo input in `menu.php` would have passed the check that exists to catch
  strays. It uses `RecursiveDirectoryIterator` and reports the file count it
  scanned, so the number is visible in CI when it next changes.
- **`grep` over a `.php` partial matches the docblock** — Phase 10 recorded
  this for `check_brand_assets.php` and it bit again immediately. The
  docblock of `photo_uploader.php` discusses `capture` and `multiple` at
  length, precisely because they are the subtle part; a check that counted
  them without stripping everything before `?>` would fail on its own
  explanation. Both static checks now do the same strip, for the same reason.
- **An invalid `createImageBitmap` option rejects, it does not throw.**
  WebIDL turns an argument-conversion error into a rejected promise for a
  promise-returning operation, so `try { createImageBitmap(f, {...}) }
  catch {}` catches nothing on the browser it was written for. Both guards
  are there because the two failure shapes are different browsers.

---

## 8. Working agreement

Unchanged from `PHASE-11-HANDOFF.md` §8. One addition, from §2.5:

> A comment claiming a fallback works is worth the same grep as a rule
> claiming only one place does something. Both are load-bearing, both are
> believed by the next person to read them, and neither is executed.

And one restatement of the Phase 10 test for a new guard, because Phase 11 is
the clearest example of it the project has produced. The question is not "is
this likely to be broken". It is:

> **Would anybody find out?**

An attribute that only does anything on a phone, only does the wrong thing on
a phone, and is ignored on every machine anybody develops on, is the answer
to that question in its purest form. It sat in the tree for nine phases.
