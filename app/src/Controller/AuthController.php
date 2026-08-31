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
