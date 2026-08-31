<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Research\ImportResult;
use Carl\Research\ResearchImporter;
use Throwable;

/**
 * Admin has exactly three functions (handoff Section 0.14): create users,
 * import research, and review the regions that need research.
 *
 * Every route here requires role admin AND is 404 to everyone else -- the
 * guard in App::guard() does that, not this class (handoff Section 7).
 */
final class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('admin/index', [
            'userCount' => $this->accounts()->count(),
            'counts'    => $this->reference()->counts(),
            'queue'     => \count($this->reference()->regionsNeedingResearch()),
            'imports'   => $this->reference()->imports(5),
        ]);
    }

    // -- Create user ------------------------------------------------------

    public function users(Request $request): Response
    {
        return $this->render('admin/users', [
            'users'     => $this->accounts()->all(),
            'created'   => $this->app->session()->pull('_created_user'),
            'errors'    => [],
            'old'       => [],
        ]);
    }

    public function createUser(Request $request): Response
    {
        $username = \strtolower(\trim((string) $request->input('username', '')));
        $email = \trim((string) $request->input('email', ''));
        $name = \trim((string) $request->input('name', ''));
        $role = $this->choice($request, 'role', ['user', 'admin'], 'user');

        $errors = [];
        if (\preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username) !== 1) {
            $errors[] = 'Username must be 3 to 64 characters: letters, digits, dot, dash, underscore.';
        } elseif ($this->accounts()->usernameTaken($username)) {
            $errors[] = 'That username is taken.';
        }
        if ($email === '' || \filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($name === '') {
            $errors[] = 'Enter the person\'s name.';
        }

        if ($errors !== []) {
            return $this->render('admin/users', [
                'users'   => $this->accounts()->all(),
                'created' => null,
                'errors'  => $errors,
                'old'     => ['username' => $username, 'email' => $email, 'name' => $name],
            ]);
        }

        $created = $this->accounts()->createWithTemporaryPassword(
            $username, $email, $name, $this->app->auth()->passwords(), $role
        );

        // Email delivery is Phase 3. Until then the password is shown once,
        // here, and the admin passes it on (handoff Section 4.10).
        $this->app->session()->set('_created_user', [
            'username' => $username,
            'password' => $created['temporary_password'],
            'email'    => $email,
        ]);

        return $this->redirect('admin/users');
    }

    // -- Research import ---------------------------------------------------

    public function researchImport(Request $request): Response
    {
        return $this->render('admin/research', [
            'result'  => $this->pendingResult(),
            'imports' => $this->reference()->imports(),
            'counts'  => $this->reference()->counts(),
        ]);
    }

    /**
     * Step one: validate the whole zip and show a preview. Nothing is written
     * until the admin confirms (handoff Section 9.3 step 4).
     */
    public function researchPreview(Request $request): Response
    {
        $file = $request->file('dataset');
        if ($file === null || (int) ($file['error'] ?? 4) !== \UPLOAD_ERR_OK) {
            $this->flash('Choose a dataset zip to upload.', 'error');
            return $this->redirect('admin/research-import');
        }

        $maxBytes = $this->app->config()->int('research.max_zip_bytes', 2097152);
        if ((int) $file['size'] > $maxBytes) {
            $this->flash('That zip is larger than 2 MB, which is this server\'s upload limit.', 'error');
            return $this->redirect('admin/research-import');
        }

        // Stored in var/imports/ under a random name, outside public_html
        // (handoff Section 9.3 step 1).
        $directory = $this->app->varPath('imports');
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            $this->flash('var/imports is not writable.', 'error');
            return $this->redirect('admin/research-import');
        }

        $stagedPath = $directory . '/' . \bin2hex(\random_bytes(16)) . '.zip';
        $tmp = (string) $file['tmp_name'];
        $moved = \is_uploaded_file($tmp) ? @\move_uploaded_file($tmp, $stagedPath) : @\rename($tmp, $stagedPath);
        if (!$moved) {
            $this->flash('The upload could not be staged.', 'error');
            return $this->redirect('admin/research-import');
        }
        @\chmod($stagedPath, 0600);

        try {
            $result = $this->importer()->validate($stagedPath, (string) $file['name']);
        } catch (Throwable $e) {
            @\unlink($stagedPath);
            $this->flash('That zip could not be read: ' . $e->getMessage(), 'error');
            return $this->redirect('admin/research-import');
        }

        // Only the staged path and the summary cross the redirect; the parsed
        // rows are re-read from the file on confirm so the session stays small.
        $this->app->session()->set('_import', [
            'path'     => $stagedPath,
            'filename' => (string) $file['name'],
            'sha256'   => $result->sha256,
        ]);

        return $this->render('admin/research', [
            'result'  => $result,
            'imports' => $this->reference()->imports(),
            'counts'  => $this->reference()->counts(),
        ]);
    }

    /** Step two: apply it, in one transaction (handoff Section 9.3 step 5). */
    public function researchConfirm(Request $request): Response
    {
        $pending = $this->app->session()->get('_import');
        if (!\is_array($pending) || !\is_file((string) $pending['path'])) {
            $this->flash('That upload is no longer staged. Upload the zip again.', 'error');
            return $this->redirect('admin/research-import');
        }

        $stagedPath = (string) $pending['path'];

        // Re-validate rather than trusting the summary that crossed the
        // redirect: the file is the only thing that decides.
        $result = $this->importer()->validate($stagedPath, (string) $pending['filename']);

        if (!\hash_equals((string) $pending['sha256'], $result->sha256)) {
            $this->flash('The staged file changed between preview and confirm. Nothing was written.', 'error');
            return $this->redirect('admin/research-import');
        }

        if (!$result->ok()) {
            return $this->render('admin/research', [
                'result'  => $result,
                'imports' => $this->reference()->imports(),
                'counts'  => $this->reference()->counts(),
            ]);
        }

        $written = $this->importer()->apply($result, $this->userId());

        // A newly researched region has to reach the users already living in
        // that county (handoff Section 9.4).
        $relinked = $this->accounts()->relinkRegions();

        @\unlink($stagedPath);
        $this->app->session()->forget('_import');

        $total = \array_sum($written);
        $this->flash(
            'Imported ' . $result->datasetVersion . ': ' . $total . ' rows across '
            . \count(\array_filter($written)) . ' files'
            . ($relinked > 0 ? ', and re-pointed ' . $relinked . ' user(s) at their region' : '')
            . '.'
        );

        return $this->redirect('admin/research-import');
    }

    // -- Regions needing research ------------------------------------------

    public function regions(Request $request): Response
    {
        return $this->render('admin/regions', [
            'queue'         => $this->reference()->regionsNeedingResearch(),
            'regions'       => $this->reference()->allRegions(),
            'zipsNeedingCounty' => $this->zcta()->needingCounty(),
        ]);
    }

    private function importer(): ResearchImporter
    {
        return new ResearchImporter(
            $this->app->db(),
            $this->app->config()->int('research.max_entry_bytes', 5242880),
            $this->app->config()->int('research.upsert_chunk_rows', 200),
        );
    }

    private function pendingResult(): ?ImportResult
    {
        return null;
    }
}
