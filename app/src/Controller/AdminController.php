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
            'mail'      => $this->app->outbox()->health(),
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

        // The on-screen path is not replaced by mail, only supplemented
        // (Phase 3 handoff Section 4.1). It works with no mailbox, it works
        // when a message bounces, and it is the only path that works the
        // first time an install is stood up -- so the password is shown here
        // whether or not an email was queued.
        $queued = $this->queueWelcome($created['id'], $username, $email, $name,
            $created['temporary_password']);

        $this->app->session()->set('_created_user', [
            'username' => $username,
            'password' => $created['temporary_password'],
            'email'    => $email,
            'queued'   => $queued,
        ]);

        return $this->redirect('admin/users');
    }

    /**
     * Queue the welcome message, if there is a driver to send it with.
     *
     * It goes in the outbox and returns immediately; a mail server being slow
     * cannot make creating a user slow (handoff Section 5.8).
     *
     * A temporary password in an email is a real exposure -- it sits in an
     * inbox until the account is used. It is bounded by must_reset_password,
     * which forces a change on first sign-in, so the window is one login
     * long. The alternative, a tokenised set-password link, is a bigger
     * change to the auth flow than Section 4.10 asks for; noted in
     * docs/PHASE-3-HANDOFF.md Section 9 as the Phase 4 improvement.
     */
    private function queueWelcome(
        int $userId,
        string $username,
        string $email,
        string $name,
        string $temporaryPassword,
    ): bool {
        $outbox = $this->app->outbox();
        if ($outbox->driver() === null) {
            return false;
        }

        $url = 'https://www.reshiftmanager.com' . $this->app->url('login');
        $text = \implode("\n", [
            'Hello ' . $name . ',',
            '',
            'An account has been created for you on Carl, a garden logging system.',
            '',
            '  Sign in:   ' . $url,
            '  Username:  ' . $username,
            '  Password:  ' . $temporaryPassword,
            '',
            'You will be asked to choose your own password the first time you sign in.',
            'Until you do, the password above is the only one that works, so please',
            'sign in soon and then delete this message.',
            '',
            '-- Carl',
        ]);

        try {
            return $outbox->queue(
                $userId,
                \Carl\Mail\Outbox::KIND_TEMPORARY_PASSWORD,
                $email,
                $name,
                'Your Carl account',
                $text,
                null,
                [],
                // One welcome per account, ever. A second create with the
                // same username cannot happen (the name is unique), so this
                // only guards a double submit.
                'welcome:' . $userId,
            ) > 0;
        } catch (Throwable $e) {
            // The password is on the screen either way; a queue failure must
            // not lose the account that was just created.
            \error_log('[carl] welcome mail not queued: ' . $e->getMessage());
            return false;
        }
    }

    // -- Mail test (handoff Section 12.1 step 7) ---------------------------

    /**
     * Spike 4: send one message with each driver and see which lands in a
     * Gmail inbox.
     *
     * Sending happens in the drain, never here -- no third-party call on the
     * request path (Phase 3 handoff Section 5). This page queues, and shows
     * the outbox rows so the result can be read off it a few minutes later.
     */
    public function mailTest(Request $request): Response
    {
        return $this->render('admin/mail', $this->mailData());
    }

    public function sendMailTest(Request $request): Response
    {
        $user = $this->user();
        $email = \trim((string) $user->email);

        if ($email === '' || \filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('Your own account has no valid email address to send to.', 'error');
            return $this->redirect('admin/mail-test');
        }

        $driver = $this->app->config()->string('mail.driver', 'none');
        $stamp = $this->app->clock()->utcStamp();

        $text = \implode("\n", [
            'This is a test message from Carl.',
            '',
            '  Driver:   ' . $driver,
            '  Queued:   ' . $stamp . ' UTC',
            '  Sent to:  ' . $email,
            '',
            'Check the received headers for "spf=pass" and "dkim=pass"',
            '(handoff Section 12.1 step 7). If either says fail or none, the',
            'DNS records in Email Deliverability are not in place yet.',
            '',
            '-- Carl',
        ]);

        try {
            $id = $this->app->outbox()->queue(
                $user->id,
                \Carl\Mail\Outbox::KIND_TEST,
                $email,
                $user->name,
                'Carl mail test (' . $driver . ')',
                $text,
                '<p>This is a test message from Carl, sent with the <strong>'
                    . \htmlspecialchars($driver, \ENT_QUOTES, 'UTF-8')
                    . '</strong> driver at ' . $stamp . ' UTC.</p>'
                    . '<p>Check the received headers for <code>spf=pass</code> and '
                    . '<code>dkim=pass</code>.</p>',
                [],
                // Deliberately unique, so the button can be pressed twice.
                null,
            );
        } catch (Throwable $e) {
            $this->flash('That could not be queued: ' . $e->getMessage(), 'error');
            return $this->redirect('admin/mail-test');
        }

        $this->flash(
            'Queued as message #' . $id . '. It goes out on the next drain; reload this page '
            . 'to see whether it was sent.'
        );
        return $this->redirect('admin/mail-test');
    }

    /** @return array<string,mixed> */
    private function mailData(): array
    {
        $outbox = $this->app->outbox();
        return [
            'driver'      => $this->app->config()->string('mail.driver', 'none'),
            'description' => $outbox->describeDriver(),
            'configured'  => $outbox->driver() !== null,
            'health'      => $outbox->health(),
            'recent'      => $outbox->recent(15),
            'lastRun'     => $outbox->lastRun(),
            'fromEmail'   => $this->app->config()->string('mail.from_email'),
            'toEmail'     => $this->user()->email,
        ];
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
