<?php

declare(strict_types=1);

namespace Carl\Auth;

use Carl\Core\App;
use Carl\Core\Response;

/**
 * Sign-in, sign-out and "who is this request".
 *
 * The PHP session holds one thing: the user id resolved from the rotating
 * token row (hosting Section 8.3). The token row is the real session.
 */
final class Auth
{
    private ?User $user = null;
    private bool $resolved = false;
    private ?string $pendingCookie = null;
    private int $pendingCookieExpires = 0;
    private bool $clearCookie = false;

    private TokenStore $tokens;
    private LoginThrottle $throttle;
    private Password $passwords;

    public function __construct(private App $app)
    {
        $config = $app->config();
        $this->tokens = new TokenStore($app->db(), $config->int('auth.token_lifetime_days', 30));
        $this->throttle = new LoginThrottle(
            $app->db(),
            $config->int('auth.throttle.max_attempts', 10),
            $config->int('auth.throttle.window_sec', 900),
            $config->int('auth.throttle.lockout_sec', 60),
        );
        $this->passwords = new Password($config->int('auth.bcrypt_cost', 11));
    }

    public function passwords(): Password
    {
        return $this->passwords;
    }

    public function tokens(): TokenStore
    {
        return $this->tokens;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $session = $this->app->session();
        $userId = $session->get('user_id');

        if (!\is_int($userId)) {
            $userId = $this->resolveFromCookie();
        }
        if ($userId === null) {
            return null;
        }

        $row = $this->app->db()->one(
            'SELECT id, username, email, name, role, must_reset_password, zip, county_fips,'
            . ' region_id, latitude, longitude, timezone, weather_location_id,'
            . ' email_digest_enabled, label_stock, tagging_started_at,'
            . ' onboarded_at, onboarding_step'
            . ' FROM user WHERE id = :id',
            ['id' => $userId]
        );
        if ($row === null) {
            // The account was deleted under a live session.
            $session->forget('user_id');
            $this->clearCookie = true;
            return null;
        }

        return $this->user = User::fromRow($row);
    }

    /** For error pages, where a failed lookup must not cascade. */
    public function userOrNull(): ?User
    {
        try {
            return $this->user();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveFromCookie(): ?int
    {
        $request = $this->app->request();
        if ($request === null) {
            return null;
        }
        $cookie = $request->cookie(TokenStore::COOKIE);
        if ($cookie === null || $cookie === '') {
            return null;
        }

        $resolved = $this->tokens->resolve(
            $cookie,
            (string) ($request->server['HTTP_USER_AGENT'] ?? ''),
            $request->ip()
        );
        if ($resolved === null) {
            $this->clearCookie = true;
            return null;
        }

        if ($resolved['cookie'] !== null) {
            $this->pendingCookie = $resolved['cookie'];
            $this->pendingCookieExpires = $resolved['expires'];
        }

        $this->app->session()->set('user_id', $resolved['user_id']);
        return $resolved['user_id'];
    }

    /**
     * @return array{ok:bool,user:?User,error:?string,retry_after:int}
     */
    public function attempt(string $username, string $password, string $ip, string $userAgent): array
    {
        $username = \trim($username);

        $retryAfter = $this->throttle->retryAfter($username, $ip);
        if ($retryAfter > 0) {
            return [
                'ok'          => false,
                'user'        => null,
                'error'       => 'Too many attempts. Try again in ' . $retryAfter . ' seconds.',
                'retry_after' => $retryAfter,
            ];
        }

        $row = $this->app->db()->one(
            'SELECT id, username, email, name, role, password_hash, must_reset_password, zip,'
            . ' county_fips, region_id, latitude, longitude, timezone, weather_location_id,'
            . ' email_digest_enabled, label_stock, tagging_started_at,'
            . ' onboarded_at, onboarding_step'
            . ' FROM user WHERE username = :username',
            ['username' => $username]
        );

        // Verify against a dummy hash when the user does not exist, so a
        // missing account and a wrong password cost the same time.
        $hash = \is_array($row) ? (string) $row['password_hash']
            : '$2y$11$0000000000000000000000000000000000000000000000000000u';
        $verified = $this->passwords->verify($password, $hash);

        if (!\is_array($row) || !$verified) {
            $this->throttle->record($username, $ip, false);
            return [
                'ok'          => false,
                'user'        => null,
                'error'       => 'That username and password do not match.',
                'retry_after' => 0,
            ];
        }

        $userId = (int) $row['id'];

        if ($this->passwords->needsRehash($hash)) {
            $this->app->db()->run(
                'UPDATE user SET password_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = :id',
                ['hash' => $this->passwords->hash($password), 'id' => $userId]
            );
        }

        $this->throttle->record($username, $ip, true);
        $this->throttle->clear($username);

        $this->app->db()->run(
            'UPDATE user SET last_login_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $userId]
        );

        $session = $this->app->session();
        $session->regenerate();
        $session->set('user_id', $userId);

        $issued = $this->tokens->issue($userId, $userAgent, $ip);
        $this->pendingCookie = $issued['cookie'];
        $this->pendingCookieExpires = $issued['expires'];

        $this->resolved = true;
        $this->user = User::fromRow($row);

        return ['ok' => true, 'user' => $this->user, 'error' => null, 'retry_after' => 0];
    }

    public function logout(): void
    {
        $request = $this->app->request();
        $cookie = $request?->cookie(TokenStore::COOKIE);
        if (\is_string($cookie) && $cookie !== '') {
            $this->tokens->revokeCookie($cookie);
        }
        $this->app->session()->destroy();
        $this->clearCookie = true;
        $this->pendingCookie = null;
        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Set a new password. Every other device is signed out immediately,
     * which is the whole reason the token row lives server-side.
     */
    public function setPassword(int $userId, string $plain, bool $keepThisDevice = true): void
    {
        $this->app->db()->run(
            'UPDATE user SET password_hash = :hash, must_reset_password = 0,'
            . ' updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['hash' => $this->passwords->hash($plain), 'id' => $userId]
        );

        $this->tokens->revokeAllForUser($userId);
        $this->resolved = false;
        $this->user = null;

        if ($keepThisDevice) {
            $request = $this->app->request();
            $issued = $this->tokens->issue(
                $userId,
                $request === null ? '' : (string) ($request->server['HTTP_USER_AGENT'] ?? ''),
                $request === null ? '' : $request->ip()
            );
            $this->pendingCookie = $issued['cookie'];
            $this->pendingCookieExpires = $issued['expires'];
            $this->app->session()->set('user_id', $userId);
        } else {
            $this->clearCookie = true;
        }
    }

    /** Attach any rotated or cleared auth cookie to the outgoing response. */
    public function decorate(Response $response): Response
    {
        if ($this->clearCookie) {
            return $response->withCookie(TokenStore::COOKIE, '', \time() - 3600);
        }
        if ($this->pendingCookie !== null) {
            return $response->withCookie(TokenStore::COOKIE, $this->pendingCookie, $this->pendingCookieExpires);
        }
        return $response;
    }
}
