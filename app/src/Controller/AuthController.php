<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Auth\Password;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Repo\UserRepository;

/**
 * Sign in, sign out, and the forced first reset.
 *
 * Handoff Section 4.1: forced reset on first login, both for an
 * admin-created temporary password and for the seeded admin/1234.
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if ($this->app->auth()->user() !== null) {
            return $this->redirect('/');
        }
        return $this->render('login', [
            'username' => $request->query('u', '') ?? '',
            'errors'   => [],
        ]);
    }

    public function login(Request $request): Response
    {
        if ($this->app->auth()->user() !== null) {
            return $this->redirect('/');
        }

        $username = $request->input('username', '') ?? '';
        $password = $request->post['password'] ?? '';

        if ($username === '' || !\is_string($password) || $password === '') {
            return $this->render('login', [
                'username' => $username,
                'errors'   => ['Enter your username and password.'],
            ]);
        }

        $result = $this->app->auth()->attempt(
            $username,
            $password,
            $request->ip(),
            (string) ($request->server['HTTP_USER_AGENT'] ?? '')
        );

        if (!$result['ok']) {
            return $this->render('login', [
                'username' => $username,
                'errors'   => [$result['error']],
            ]);
        }

        $user = $result['user'];

        if ($user->mustResetPassword) {
            return $this->redirect('/password/reset');
        }
        if (!$user->isOnboarded()) {
            return $this->redirect('/onboarding');
        }

        $intended = $this->app->session()->pull('_intended');
        if (\is_string($intended) && \str_starts_with($intended, '/')) {
            return Response::redirect($this->app->url(\ltrim($intended, '/')));
        }

        return $this->redirect('/');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth()->logout();
        return $this->redirect('/login');
    }

    // -- The tokenised set-password link (Phase 5 handoff Section 3.5) -----

    /**
     * `/password/setup/<token>` -- what the invitation email links to.
     *
     * Public, because the person clicking it has no account they can sign in
     * to yet: the whole point of Section 3.5 is that the mail no longer
     * carries a password for them to sign in WITH.
     *
     * Unlike the One-Click unsubscribe (Route::TOKEN_ACCESS), this is
     * PUBLIC_ACCESS and the POST below therefore gets the normal CSRF check.
     * It can: a person reached this page in a browser, so there is a session
     * and a rendered form to carry a token. The unsubscribe POST is exempt
     * only because a mail client sends it with neither.
     *
     * Every failure says which failure it is. "Not valid" and "already used"
     * send someone to two different places, and only one of them is the
     * login page.
     */
    public function showSetup(Request $request, array $params): Response
    {
        $resolved = $this->invites()->resolve((string) $params['token']);

        return $this->render('password_setup', [
            'status'   => $resolved['status'],
            'token'    => (string) $params['token'],
            'username' => $this->inviteUsername($resolved),
            'errors'   => [],
            'layout'   => 'layout',
        ]);
    }

    /**
     * Set the password the link was issued for, and sign them in.
     *
     * The token is re-resolved here rather than trusted from the form: the
     * page that rendered it may have been open for a week, and a link that
     * expired or was used in another tab in the meantime must not still work.
     *
     * `markUsed()` is spent BEFORE the password is set, and it is a
     * compare-and-swap. Two submissions of the same form set the password
     * once; the loser is told the link is used, which by then it is.
     */
    public function setup(Request $request, array $params): Response
    {
        $token = (string) $params['token'];
        $invites = $this->invites();
        $resolved = $invites->resolve($token);

        if ($resolved['status'] !== \Carl\Auth\InviteStore::VALID) {
            return $this->render('password_setup', [
                'status'   => $resolved['status'],
                'token'    => $token,
                'username' => $this->inviteUsername($resolved),
                'errors'   => [],
            ]);
        }

        $userId = (int) $resolved['user_id'];
        $account = $this->accounts()->find($userId);
        $username = $account === null ? '' : (string) $account['username'];

        $new = $request->post['password'] ?? '';
        $confirm = $request->post['password_confirm'] ?? '';

        $errors = [];
        if (!\is_string($new) || !\is_string($confirm)) {
            $errors[] = 'Enter the new password twice.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        } else {
            $errors = Password::problems($new, $username);
        }

        if ($errors === [] && !$invites->markUsed((int) $resolved['invite_id'])) {
            // Lost the swap: somebody -- almost always this same person in
            // another tab -- already spent it.
            return $this->render('password_setup', [
                'status'   => \Carl\Auth\InviteStore::USED,
                'token'    => $token,
                'username' => $username,
                'errors'   => [],
            ]);
        }

        if ($errors !== []) {
            return $this->render('password_setup', [
                'status'   => \Carl\Auth\InviteStore::VALID,
                'token'    => $token,
                'username' => $username,
                'errors'   => $errors,
            ]);
        }

        // Clears must_reset_password, revokes every other device's token, and
        // signs this one in (hosting Section 8.3). The forced-reset screen is
        // therefore not shown again: they have just chosen their own password,
        // which is the whole thing that screen exists to make them do.
        $this->app->auth()->setPassword($userId, (string) $new);

        $this->flash('Password set. Welcome to Carl.');

        $refreshed = $this->accounts()->find($userId);
        $onboarded = $refreshed !== null && $refreshed['onboarded_at'] !== null;

        return $this->redirect($onboarded ? '/' : '/onboarding');
    }

    private function invites(): \Carl\Auth\InviteStore
    {
        return new \Carl\Auth\InviteStore(
            $this->app->db(),
            $this->app->config()->int('invite.lifetime_days', 7)
        );
    }

    /**
     * The username a link belongs to, for the page to greet.
     *
     * Only for a token that actually resolved: a wrong token learns nothing
     * about whose account it nearly was.
     *
     * @param array{status:string,user_id:?int,invite_id:?int} $resolved
     */
    private function inviteUsername(array $resolved): string
    {
        if ($resolved['user_id'] === null) {
            return '';
        }
        $account = $this->accounts()->find($resolved['user_id']);
        return $account === null ? '' : (string) $account['username'];
    }

    public function showReset(Request $request): Response
    {
        return $this->render('password_reset', [
            'forced' => $this->user()->mustResetPassword,
            'errors' => [],
        ]);
    }

    public function reset(Request $request): Response
    {
        $user = $this->user();
        $new = $request->post['password'] ?? '';
        $confirm = $request->post['password_confirm'] ?? '';

        $errors = [];
        if (!\is_string($new) || !\is_string($confirm)) {
            $errors[] = 'Enter the new password twice.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        } else {
            $errors = Password::problems($new, $user->username);
        }

        if ($errors !== []) {
            return $this->render('password_reset', [
                'forced' => $user->mustResetPassword,
                'errors' => $errors,
            ]);
        }

        // Every other device is signed out immediately -- that is the point of
        // the token row living server-side (hosting Section 8.3).
        $this->app->auth()->setPassword($user->id, (string) $new);

        $this->flash('Password changed. Any other device you were signed in on has been signed out.');

        $refreshed = $this->accounts()->find($user->id);
        $onboarded = $refreshed !== null && $refreshed['onboarded_at'] !== null;

        return $this->redirect($onboarded ? '/' : '/onboarding');
    }
}
