<?php

declare(strict_types=1);

namespace Carl\Core;

use Carl\Controller\AdminController;
use Carl\Controller\AuthController;
use Carl\Controller\ExportController;
use Carl\Controller\GardenController;
use Carl\Controller\ListController;
use Carl\Controller\LogController;
use Carl\Controller\MenuController;
use Carl\Controller\OnboardingController;
use Carl\Controller\PhotoController;
use Carl\Controller\PlantController;
use Carl\Controller\SystemController;

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
        $r->get('/admin/research-import', AdminController::class, 'researchImport', Route::ADMIN_ACCESS);
        $r->post('/admin/research-import', AdminController::class, 'researchPreview', Route::ADMIN_ACCESS);
        $r->post('/admin/research-import/confirm', AdminController::class, 'researchConfirm', Route::ADMIN_ACCESS);
        $r->get('/admin/regions', AdminController::class, 'regions', Route::ADMIN_ACCESS);

        // -- Key-guarded operations (hosting Section 6.3) -------------------
        // No key configured, or a wrong key, is 404 -- never 403.
        $r->get('/status', SystemController::class, 'status', Route::KEY_ACCESS, 'status_key');
        $r->get('/setup', SystemController::class, 'setup', Route::KEY_ACCESS, 'setup_key');
        $r->post('/setup', SystemController::class, 'runSetup', Route::KEY_ACCESS, 'setup_key');
        $r->get('/tasks/weather-sync', SystemController::class, 'weatherSync', Route::KEY_ACCESS, 'cron_key');
        $r->get('/diag', SystemController::class, 'diag', Route::KEY_ACCESS, 'diag_key');

        return $r;
    }
}
