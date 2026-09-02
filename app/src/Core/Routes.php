<?php

declare(strict_types=1);

namespace Carl\Core;

use Carl\Controller\AdminController;
use Carl\Controller\AdviceController;
use Carl\Controller\AuthController;
use Carl\Controller\CalendarController;
use Carl\Controller\DigestController;
use Carl\Controller\ExportController;
use Carl\Controller\GardenController;
use Carl\Controller\ListController;
use Carl\Controller\LogController;
use Carl\Controller\MenuController;
use Carl\Controller\OnboardingController;
use Carl\Controller\PestController;
use Carl\Controller\PhotoController;
use Carl\Controller\PlantController;
use Carl\Controller\PushController;
use Carl\Controller\TimerController;
use Carl\Controller\ReportController;
use Carl\Controller\CompanionController;
use Carl\Controller\ConnectController;
use Carl\Controller\McpController;
use Carl\Controller\SuccessionController;
use Carl\Controller\SystemController;
use Carl\Controller\TagController;

/**
 * The whole route table, in one file.
 *
 * Paths are site-root-relative: Request has already stripped the mount point,
 * so nothing here knows the app lives at /carl/ (hosting Section 5.2).
 *
 * Access is declared per route and enforced in App::guard() on every request,
 * not by reaching the route (hosting Section 8.5). Admin routes are 404 to
 * everyone else, and key-guarded routes are 404 without the key.
 */
final class Routes
{
    public static function build(): Router
    {
        $r = new Router();

        // -- Signed out ---------------------------------------------------
        $r->get('/login', AuthController::class, 'showLogin', Route::PUBLIC_ACCESS);
        $r->post('/login', AuthController::class, 'login', Route::PUBLIC_ACCESS);
        $r->post('/logout', AuthController::class, 'logout', Route::PUBLIC_ACCESS);

        // -- Forced reset (outranks everything but itself) -----------------
        $r->get('/password/reset', AuthController::class, 'showReset', Route::SETUP_ACCESS);
        $r->post('/password/reset', AuthController::class, 'reset', Route::SETUP_ACCESS);

        // -- The set-password link (Phase 5 handoff Section 3.5) ------------
        // Public because the person clicking it cannot sign in yet: that is
        // the point -- the invitation email no longer carries a password for
        // them to sign in WITH.
        //
        // PUBLIC_ACCESS and not TOKEN_ACCESS, so the POST gets the normal
        // CSRF check. It can afford to: a person reached this in a browser,
        // so there is a session and a rendered form to carry a token. The
        // One-Click unsubscribe is exempt only because a mail client sends it
        // with neither.
        //
        // [0-9a-f.]+ rather than a length: the router reads a constraint as
        // everything up to the first '}', so a brace quantifier would cut the
        // pattern in half (see the unsubscribe note below). The exact shape
        // is enforced in InviteStore::resolve().
        $r->get('/password/setup/{token:[0-9a-f.]+}',
            AuthController::class, 'showSetup', Route::PUBLIC_ACCESS);
        $r->post('/password/setup/{token:[0-9a-f.]+}',
            AuthController::class, 'setup', Route::PUBLIC_ACCESS);

        // -- Onboarding ----------------------------------------------------
        $r->get('/onboarding', OnboardingController::class, 'index', Route::SETUP_ACCESS);
        $r->post('/onboarding/profile', OnboardingController::class, 'saveProfile', Route::SETUP_ACCESS);
        $r->post('/onboarding/zip', OnboardingController::class, 'lookupZip', Route::SETUP_ACCESS);
        $r->get('/onboarding/garden', OnboardingController::class, 'garden', Route::SETUP_ACCESS);
        $r->post('/onboarding/garden', OnboardingController::class, 'saveGarden', Route::SETUP_ACCESS);
        $r->get('/onboarding/plant', OnboardingController::class, 'plant', Route::SETUP_ACCESS);
        $r->post('/onboarding/finish', OnboardingController::class, 'finish', Route::SETUP_ACCESS);

        // -- Main menu -----------------------------------------------------
        $r->get('/', MenuController::class, 'index');
        $r->post('/motd/dismiss', MenuController::class, 'dismiss');
        $r->post('/reminders/dismiss', DigestController::class, 'dismiss');

        // -- Unsubscribe (handoff Section 12) -------------------------------
        // Public on purpose: someone clicking a link in an email is not
        // signed in, and making them sign in to stop the mail is exactly the
        // pattern that gets a sender marked as spam. The POST is
        // PUBLIC_ACCESS so RFC 8058 One-Click works, which needs no CSRF
        // token and no session; the token in the path is the only credential
        // and the only thing it can do is turn that person's own mail off.
        // The constraint is [0-9a-f]+ rather than [0-9a-f]{64}: the router
        // reads a constraint as everything up to the first '}', so a brace
        // quantifier would cut the pattern in half. The exact length is
        // enforced where the token is looked up.
        $r->get('/unsubscribe/{token:[0-9a-f]+}',
            DigestController::class, 'unsubscribe', Route::PUBLIC_ACCESS);
        $r->post('/unsubscribe/{token:[0-9a-f]+}',
            DigestController::class, 'confirmUnsubscribe', Route::TOKEN_ACCESS);
        $r->post('/unsubscribe/{token:[0-9a-f]+}/resume',
            DigestController::class, 'resubscribe', Route::TOKEN_ACCESS);

        // -- Start a New Plant ---------------------------------------------
        $r->get('/plants/new', PlantController::class, 'chooseKind');
        $r->get('/plants/new/{kind:indoor_seed|direct_sow|nursery_transplant}',
            PlantController::class, 'newForm');
        $r->post('/plants', PlantController::class, 'create');

        // -- View Plants ---------------------------------------------------
        $r->get('/plants', PlantController::class, 'index');
        $r->get('/plants/{id:\d+}', PlantController::class, 'show');

        // -- Log Plant Activity --------------------------------------------
        $r->get('/log', LogController::class, 'index');
        $r->get('/log/{id:\d+}', LogController::class, 'plant');
        $r->post('/log/{id:\d+}', LogController::class, 'record');
        $r->post('/log/batch', LogController::class, 'batch');

        // -- Calendar (Phase 9) ---------------------------------------------
        // A month of the garden, and the table of what is coming. One GET,
        // and a GET on purpose: a filtered month has to be bookmarkable and
        // reachable with the back button, which a POST is not. Nothing here
        // writes and nothing here computes anything the digest does not --
        // see Carl\Planting\Calendar.
        $r->get('/calendar', CalendarController::class, 'index');
        // The same month on paper (Phase 15): the grid, then every worked-out
        // date on it in full. A GET like the field sheet, so the link carries
        // the page's own month and filter and a paper jam costs nothing. The
        // dot is escaped by the router, so this answers to nothing else.
        $r->get('/calendar.pdf', CalendarController::class, 'pdf');

        // -- Gardens --------------------------------------------------------
        $r->get('/gardens', GardenController::class, 'index');
        $r->get('/gardens/new', GardenController::class, 'newForm');
        $r->post('/gardens', GardenController::class, 'create');
        $r->get('/gardens/{id:\d+}', GardenController::class, 'show');
        $r->get('/gardens/{id:\d+}/edit', GardenController::class, 'edit');
        $r->post('/gardens/{id:\d+}', GardenController::class, 'update');
        $r->post('/gardens/{id:\d+}/rows', GardenController::class, 'updateRows');
        $r->post('/gardens/{id:\d+}/zones', GardenController::class, 'saveZone');
        $r->get('/gardens/{id:\d+}/actions', GardenController::class, 'actions');
        $r->post('/gardens/{id:\d+}/actions', GardenController::class, 'recordAction');
        // End Growing Season (Phase 5 handoff Section 3.3): the one
        // destructive action in the application, so the GET is a
        // confirmation screen that names every planting it will end and the
        // POST wants the words typed.
        $r->get('/gardens/{id:\d+}/end-season', GardenController::class, 'endSeasonForm');
        $r->post('/gardens/{id:\d+}/end-season', GardenController::class, 'endSeason');

        // -- The watering timer (Phase 16) ------------------------------------
        // A row with an end time; bin/timers_fire.php notices it. The start
        // button lives on the garden actions page and on the MOTD; the
        // landing page is what the notification opens.
        $r->post('/timers', TimerController::class, 'start');
        $r->get('/timers/{id:\d+}', TimerController::class, 'show');
        $r->post('/timers/{id:\d+}/cancel', TimerController::class, 'cancel');
        $r->post('/timers/{id:\d+}/log', TimerController::class, 'logNow');
        // A browser saying "tell this phone". Form-encoded from push.js, so
        // it carries the CSRF token like every other POST.
        $r->post('/push/subscribe', PushController::class, 'subscribe');
        $r->post('/push/unsubscribe', PushController::class, 'unsubscribe');

        // -- Lists ----------------------------------------------------------
        $r->get('/lists', ListController::class, 'index');
        $r->get('/lists/{type:[a-z_]+}', ListController::class, 'ofType');
        $r->post('/lists', ListController::class, 'save');
        $r->post('/lists/archive', ListController::class, 'archive');
        $r->post('/lists/inline', ListController::class, 'inlineAdd');

        // -- CSV export (handoff Section 13.3) ------------------------------
        // The user's own data only; the scope comes from the repository base
        // class, and every cell is formula-injection guarded (hosting 8.5).
        $r->get('/export', ExportController::class, 'index');
        $r->get('/export/plants.csv', ExportController::class, 'plantsCsv');
        $r->get('/export/events.csv', ExportController::class, 'eventsCsv');
        $r->get('/export/weather.csv', ExportController::class, 'weatherCsv');
        // The v2 "Recommendations" bridge: the same data, shaped for pasting
        // into a Claude conversation. Deliberately NOT formula-guarded --
        // see the docblock, which says why and says not to "fix" it.
        $r->get('/export/claude.json', ExportController::class, 'claudeJson');

        // -- Reports: the chart data and the PDF (handoff Section 13) -------
        // The report PAGES are /plants/<id> and /gardens/<id>, server-rendered
        // and readable with JavaScript off. These two add the series a chart
        // reads and the download at the bottom of it.
        //
        // The PDF is a POST because the browser sends the chart canvases up
        // as PNGs (Section 13.2); it is not a write, and it changes nothing.
        $r->get('/api/plant/{id:\d+}/series', ReportController::class, 'plantSeries');
        $r->get('/api/garden/{id:\d+}/series', ReportController::class, 'gardenSeries');
        $r->post('/report/plant/{id:\d+}/pdf', ReportController::class, 'plantPdf');
        $r->post('/report/garden/{id:\d+}/pdf', ReportController::class, 'gardenPdf');

        // -- Connect Claude Code, and the MCP server (Phase 16) -------------
        // The screen mints and revokes bearer tokens; the endpoint is what
        // they open. POST only: the Streamable HTTP transport allows a server
        // to answer every request with plain JSON and never open a stream,
        // and hosting Section 3 forbids a held-open connection, so a GET here
        // is 405 from the router. BEARER_ACCESS: no session, no cookie, no
        // CSRF -- App::guard() resolves the token, checks the Origin and the
        // rate limit, and signs the request in as the token's owner.
        $r->get('/connect', ConnectController::class, 'index');
        $r->post('/connect/tokens', ConnectController::class, 'mint');
        $r->post('/connect/tokens/{id:\d+}/revoke', ConnectController::class, 'revoke');
        $r->post('/mcp', McpController::class, 'endpoint', Route::BEARER_ACCESS);

        // -- Reports menu (Phase 5 handoff Section 3.2) ---------------------
        // Links and nothing else: there are now six things to download and
        // two report pages, and until this existed the only way to reach any
        // of them was from a plant or a garden. No new data access.
        $r->get('/reports', ReportController::class, 'menu');

        // -- The field sheet (handoff Section 13.4, Phase 6) ----------------
        $r->get('/reports/field-sheet.pdf', ReportController::class, 'fieldSheet');
        $r->get('/reports/garden/{id:\d+}/field-sheet.pdf',
            ReportController::class, 'gardenFieldSheet');

        // -- Succession planting (handoff Section 15, Phase 6) --------------
        $r->get('/succession', SuccessionController::class, 'index');

        // -- Companion planting reference (handoff Section 14 v2, Phase 6) --
        $r->get('/companions', CompanionController::class, 'index');

        // -- Pest and disease reference (Phase 9) ---------------------------
        // Global reference data, read-only, like /companions.
        // db/migrations/022_pest_reference.sql is why it exists at all. `?key=`
        // opens one entry in full and everything else on the page is a list,
        // because drawn as seventy-six cards it is 202 KB of HTML.
        $r->get('/pests', PestController::class, 'index');

        // -- Recommendations (handoff Section 14 v2; Phase 5 Section 3.1) ---
        // The POST queues an `analysis` row and returns. It does NOT call the
        // API: that happens in the drain cron below, because no third-party
        // call may sit on a request path (Phase 3 handoff Section 5).
        $r->get('/advice', AdviceController::class, 'index');
        $r->post('/advice', AdviceController::class, 'ask');
        $r->post('/advice/{id:\d+}/retry', AdviceController::class, 'retry');

        // -- QR plant tags (docs/QR-TAGS-SPEC.md) ---------------------------
        //
        // THE SCAN IS Route::USER_ACCESS, not PUBLIC and not TOKEN. A tag on a
        // stake in a front garden is readable by anyone walking past and
        // photographable from the pavement, so a bearer token there would let
        // a stranger read the owner's whole garden history or log a harvest
        // that never happened (Section 6.1). It costs the gardener nothing:
        // the 30-day rotating CARLAUTH cookie means their own phone is signed
        // in essentially always, and a signed-out scan is not lost either --
        // App::guard() stores the path and AuthController returns to it, which
        // is the `?next=` the spec asked for, already built and with no
        // open-redirect surface because the value never leaves the session.
        //
        // BOTH CASES OF THE `t` SEGMENT are registered. The code itself is
        // matched [0-9A-Za-z]+ and upper-cased in the controller, so only the
        // literal needs two entries. A brace quantifier cannot be used for the
        // length -- the router reads a constraint as everything up to the
        // first '}' -- which is the same trap the unsubscribe route documents;
        // TagRepository::isWellFormed() enforces the six characters.
        $r->get('/t/{code:[0-9A-Za-z]+}', TagController::class, 'scan');
        $r->get('/T/{code:[0-9A-Za-z]+}', TagController::class, 'scan');
        $r->post('/t/{code:[0-9A-Za-z]+}/log', TagController::class, 'log');
        $r->post('/t/{code:[0-9A-Za-z]+}/bind', TagController::class, 'bind');
        $r->post('/t/{code:[0-9A-Za-z]+}/undo', TagController::class, 'undo');
        $r->post('/t/{code:[0-9A-Za-z]+}/release', TagController::class, 'release');
        // One code, not a sheet: the stake that snapped, the label that tore.
        // Refused while the tag is on a plant -- take it off first.
        $r->post('/t/{code:[0-9A-Za-z]+}/retire', TagController::class, 'retireTag');

        // THE DESK HALF OF SECTION 5.2, from the plant's end: "here is a
        // plant, which tag?" The scan answers the other question. Both land
        // in TagRepository::bindTo(), which is what keeps one live binding a
        // plant whichever way it was made. Under /plants/{id} and not /t/,
        // because the code is the FORM's value here, not the address's --
        // it was picked off a list of free codes or read off a stake.
        $r->post('/plants/{id:\d+}/tag', TagController::class, 'attach');
        $r->post('/plants/{id:\d+}/tag/release', TagController::class, 'detach');

        // The pool and the printing. Literal paths before the {id} ones: the
        // router returns the FIRST route whose regex matches, so a pattern
        // that could swallow a literal has to come after it.
        $r->get('/tags', TagController::class, 'index');
        $r->get('/tags/print', TagController::class, 'printForm');
        $r->get('/tags/labels.pdf', TagController::class, 'labelsPdf');
        $r->post('/tags/find', TagController::class, 'find');
        $r->post('/tags/session', TagController::class, 'session');
        // The mint is a POST because it writes; the render is a GET because a
        // paper jam must not cost you thirty codes (Section 5.4).
        $r->post('/tags/batches', TagController::class, 'mint');
        $r->get('/tags/batches/{id:\d+}.pdf', TagController::class, 'batchPdf');
        $r->get('/tags/batches/{id:\d+}/registration.pdf',
            TagController::class, 'registrationPdf');
        $r->post('/tags/batches/{id:\d+}/retire', TagController::class, 'retire');
        $r->get('/tags/batches/{id:\d+}', TagController::class, 'batch');

        // -- Photos (never a direct URL -- handoff Section 5.3) -------------
        $r->post('/photos', PhotoController::class, 'upload');
        $r->get('/photos/{id:\d+}', PhotoController::class, 'show');
        $r->get('/photos/{id:\d+}/thumb', PhotoController::class, 'thumb');

        // -- Research card (loaded into a plant form) -----------------------
        $r->get('/research/{id:\d+}', PlantController::class, 'researchCard');

        // -- Admin: exactly three functions (handoff Section 0.14) ----------
        $r->get('/admin', AdminController::class, 'index', Route::ADMIN_ACCESS);
        $r->get('/admin/users', AdminController::class, 'users', Route::ADMIN_ACCESS);
        $r->post('/admin/users', AdminController::class, 'createUser', Route::ADMIN_ACCESS);
        // Send another set-password link (Phase 5 handoff Section 3.5). The
        // links expire, and without this the only recovery from an expired
        // one is setup_key -- which is the master admin credential (hosting
        // Section 6.3), for a person who forgot to click a link.
        $r->post('/admin/users/{id:\d+}/invite', AdminController::class, 'sendInvite', Route::ADMIN_ACCESS);
        $r->get('/admin/research-import', AdminController::class, 'researchImport', Route::ADMIN_ACCESS);
        $r->post('/admin/research-import', AdminController::class, 'researchPreview', Route::ADMIN_ACCESS);
        $r->post('/admin/research-import/confirm', AdminController::class, 'researchConfirm', Route::ADMIN_ACCESS);
        // Phase 9: re-apply db/seed/pest_catalog.csv and put it in front of
        // every account. A POST because it writes, admin-only because it
        // writes to every account, and idempotent because it will be pressed
        // twice by somebody who is not sure it worked.
        $r->post('/admin/reference-sync', AdminController::class, 'referenceSync', Route::ADMIN_ACCESS);
        $r->get('/admin/regions', AdminController::class, 'regions', Route::ADMIN_ACCESS);
        // Handoff Section 12.1 step 7 calls this "/admin/mail-test?key=".
        // It is admin-only instead: a key-guarded route that sends mail to an
        // address in the query string is an open relay to anyone who ever
        // sees the key in a browser bar or an access log. Admin access is the
        // stronger guard, and the destination is fixed to the signed-in
        // admin's own address. Recorded in docs/PHASE-3-HANDOFF.md Section 9.
        $r->get('/admin/analysis', AdminController::class, 'analysisCost', Route::ADMIN_ACCESS);
        $r->get('/admin/mail-test', AdminController::class, 'mailTest', Route::ADMIN_ACCESS);
        $r->post('/admin/mail-test', AdminController::class, 'sendMailTest', Route::ADMIN_ACCESS);

        // -- Key-guarded operations (hosting Section 6.3) -------------------
        // No key configured, or a wrong key, is 404 -- never 403.
        $r->get('/status', SystemController::class, 'status', Route::KEY_ACCESS, 'status_key');
        $r->get('/setup', SystemController::class, 'setup', Route::KEY_ACCESS, 'setup_key');
        $r->post('/setup', SystemController::class, 'runSetup', Route::KEY_ACCESS, 'setup_key');
        $r->get('/tasks/weather-sync', SystemController::class, 'weatherSync', Route::KEY_ACCESS, 'cron_key');
        $r->get('/tasks/mail-send', SystemController::class, 'mailSend', Route::KEY_ACCESS, 'cron_key');
        $r->get('/tasks/alerts-poll', SystemController::class, 'alertsPoll', Route::KEY_ACCESS, 'cron_key');
        $r->get('/tasks/daily-digest', SystemController::class, 'dailyDigest', Route::KEY_ACCESS, 'cron_key');
        $r->get('/tasks/analysis-run', SystemController::class, 'analysisRun', Route::KEY_ACCESS, 'cron_key');
        $r->get('/tasks/timers-fire', SystemController::class, 'timersFire', Route::KEY_ACCESS, 'cron_key');
        $r->get('/diag', SystemController::class, 'diag', Route::KEY_ACCESS, 'diag_key');

        return $r;
    }
}
