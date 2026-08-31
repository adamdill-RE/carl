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
        $queued = $this->queueWelcome($created['id'], $username, $email, $name);

        $this->app->session()->set('_created_user', [
            'username' => $username,
            'password' => $created['temporary_password'],
            'email'    => $email,
            'queued'   => $queued,
        ]);

        return $this->redirect('admin/users');
    }

    /**
     * Send another set-password link (Phase 5 handoff Section 3.5).
     *
     * The links expire in a week, and without this the only recovery from an
     * expired one is `setup_key` -- which is the master admin credential
     * (hosting Section 6.3) -- for a person who did not get round to clicking
     * a link. Issuing a new one cancels any earlier unused one, so "send
     * another" leaves exactly one live link, which is what an administrator
     * pressing that button believes it does.
     */
    public function sendInvite(Request $request, array $params): Response
    {
        $account = $this->accounts()->find((int) $params['id']);
        if ($account === null) {
            throw HttpException::notFound('There is no such account.');
        }

        $queued = $this->queueWelcome(
            (int) $account['id'],
            (string) $account['username'],
            (string) $account['email'],
            (string) $account['name'],
            'invite:' . $account['id'] . ':' . \bin2hex(\random_bytes(4)),
        );

        $this->flash($queued
            ? 'A new set-password link is on its way to ' . $account['email']
              . '. Any earlier link for that account has stopped working.'
            : 'No mail driver is configured, so nothing was sent. Set one up in '
              . 'config/local.php first (handoff Section 12.1).',
            $queued ? 'ok' : 'error');

        return $this->redirect('admin/users');
    }

    /**
     * Queue the welcome message, if there is a driver to send it with.
     *
     * It goes in the outbox and returns immediately; a mail server being slow
     * cannot make creating a user slow (handoff Section 5.8).
     *
     * **The password is not in it.** Phase 3 shipped the temporary password
     * in this message and Phase 3 handoff Section 9.4 called that out: a
     * password in an inbox sits there until the account is first used, and
     * long after. It was bounded by must_reset_password, so the window was
     * one login long -- but it was still a working credential in a mailbox
     * that Carl does not control. Phase 5 handoff Section 3.5 is the fix:
     * the mail carries a one-shot expiring link that can do one thing, and
     * `InviteStore` is what backs it.
     *
     * The ON-SCREEN temporary password stays, unchanged. It is what works
     * with no mailbox, what works when a message bounces, and the only path
     * that works the first time an install is stood up.
     *
     * @param string|null $dedupeKey null to derive the once-per-account one
     */
    private function queueWelcome(
        int $userId,
        string $username,
        string $email,
        string $name,
        ?string $dedupeKey = null,
    ): bool {
        $outbox = $this->app->outbox();
        if ($outbox->driver() === null) {
            return false;
        }

        $invites = new \Carl\Auth\InviteStore(
            $this->app->db(),
            $this->app->config()->int('invite.lifetime_days', 7)
        );

        try {
            $token = $invites->issue($userId, $this->userId(), $this->app->request()?->ip() ?? '');
        } catch (Throwable $e) {
            \error_log('[carl] invite not issued: ' . $e->getMessage());
            return false;
        }

        $days = $this->app->config()->int('invite.lifetime_days', 7);
        // Absolute, because it is going in an email: $app->url() is
        // site-root-relative by design (hosting Section 5.2) and a relative
        // path in a mail client resolves against nothing.
        $link = 'https://www.reshiftmanager.com' . $this->app->url('password/setup/' . $token);

        $text = \implode("\n", [
            'Hello ' . $name . ',',
            '',
            'An account has been created for you on Carl, a garden logging system.',
            '',
            'Choose your password here:',
            '',
            '  ' . $link,
            '',
            '  Your username: ' . $username,
            '',
            'That link works once and stops working after ' . $days . ' days. There is no',
            'password in this message, so there is nothing here worth keeping -- if the',
            'link has expired by the time you get to it, ask for another.',
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
                // One welcome per account by default; a resend passes its own
                // key so it is not swallowed as a duplicate of that one.
                $dedupeKey ?? 'welcome:' . $userId,
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

    /**
     * Queue the test message (handoff Section 12.1 step 7).
     *
     * The recipient is a field rather than always the admin's own address.
     * Step 7 is "check the received headers for spf=pass and dkim=pass", and
     * those headers are written by the RECEIVING server: a message from
     * carl@example.com to carl@example.com is delivered locally by the same
     * Exim that accepted it, never crosses the internet and is never
     * authenticated by anybody. On this install the master admin's address is
     * on the sending domain, so the button could only ever prove that SMTP
     * auth and TLS work -- which it did, in 0.1 s, without leaving the host.
     * Spike 4 needs the other half: one send each from the smtp and api
     * drivers to an external inbox, to see which one lands in it.
     */
    public function sendMailTest(Request $request): Response
    {
        $user = $this->user();
        $email = \trim((string) $request->input('to', ''));
        if ($email === '') {
            $email = \trim((string) $user->email);
        }

        if ($email === '' || \filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('That is not an email address Carl can send to.', 'error');
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
            'Those headers only exist if this crossed the internet. Sent to an',
            'address on the sending domain it is delivered locally and nobody',
            'authenticates it, so test against an outside inbox.',
            '',
            '-- Carl',
        ]);

        try {
            $id = $this->app->outbox()->queue(
                $user->id,
                \Carl\Mail\Outbox::KIND_TEST,
                $email,
                \strcasecmp($email, (string) $user->email) === 0 ? $user->name : null,
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
            'Queued as message #' . $id . ' to ' . $email . '. It goes out on the next drain; '
            . 'reload this page to see whether it was sent.'
        );
        return $this->redirect('admin/mail-test');
    }

    /** @return array<string,mixed> */
    private function mailData(): array
    {
        $outbox = $this->app->outbox();
        $fromEmail = $this->app->config()->string('mail.from_email');
        $at = \strrpos($fromEmail, '@');

        return [
            'driver'      => $this->app->config()->string('mail.driver', 'none'),
            'description' => $outbox->describeDriver(),
            'configured'  => $outbox->driver() !== null,
            'health'      => $outbox->health(),
            'recent'      => $outbox->recent(15),
            'lastRun'     => $outbox->lastRun(),
            'fromEmail'   => $fromEmail,
            'toEmail'     => $this->user()->email,
            // Named on the page so "send it somewhere else" is concrete
            // advice rather than a principle the reader has to apply.
            'fromDomain'  => $at === false ? 'this domain' : \substr($fromEmail, $at + 1),
            // Named in full because there are two config/ directories on this
            // account -- the git checkout's and the deployed application's --
            // and only one of them is ever read (hosting Section 6.4).
            'localConfigPath' => $this->app->root() . '/config/local.php',
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
