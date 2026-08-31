<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * Hosting Section 8.1: every host default is unsafe. Nothing is inherited --
 * every setting is written before session_start(), because they can change
 * under you on a shared box.
 *
 * The PHP session is short and holds one thing: the id of a server-side
 * token row (hosting Section 8.3). The token row is the real session.
 */
final class Session
{
    private bool $started = false;

    public function __construct(
        private string $name,
        private string $cookiePath,
        private bool $secure,
        private string $savePath,
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (\PHP_SAPI === 'cli') {
            // CLI has no cookies and no session handler; the array is enough
            // for bin/ scripts and for the test harness to drive a request.
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            $this->started = true;
            return;
        }
        if (\session_status() === \PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if (!\is_dir($this->savePath)) {
            // 0700 is correct here: outside public_html, nothing serves it,
            // and it keeps session files off the cPanel-wide save path shared
            // with every other account (hosting Section 5.3).
            @\mkdir($this->savePath, 0700, true);
        }
        if (\is_dir($this->savePath) && \is_writable($this->savePath)) {
            \session_save_path($this->savePath);
        }

        \ini_set('session.use_strict_mode', '1');   // reject an attacker-supplied id
        \ini_set('session.use_only_cookies', '1');
        \ini_set('session.use_trans_sid', '0');
        \ini_set('session.cookie_httponly', '1');
        \ini_set('session.cookie_secure', $this->secure ? '1' : '0');
        \ini_set('session.cookie_samesite', 'Lax');
        \ini_set('session.cookie_path', $this->cookiePath);
        // Deliberately NOT setting sid_length / sid_bits_per_character:
        // the defaults are already secure and both are deprecated in 8.4
        // (hosting Section 8.1).

        \session_name($this->name);
        \session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $this->cookiePath,
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        \session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function has(string $key): bool
    {
        $this->start();
        return isset($_SESSION[$key]);
    }

    /** Read once and clear: one-shot messages across a redirect. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function flash(string $message, string $kind = 'ok'): void
    {
        $this->set('_flash', ['kind' => $kind, 'message' => $message]);
    }

    /** @return array{kind:string,message:string}|null */
    public function takeFlash(): ?array
    {
        $flash = $this->pull('_flash');
        return \is_array($flash) ? $flash : null;
    }

    public function regenerate(): void
    {
        $this->start();
        if (\PHP_SAPI !== 'cli' && \session_status() === \PHP_SESSION_ACTIVE) {
            \session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (\PHP_SAPI !== 'cli' && \session_status() === \PHP_SESSION_ACTIVE) {
            \session_destroy();
        }
    }

    /**
     * Hosting Section 9: close the session before anything slow, so a second
     * request from the same browser does not serialise behind it.
     */
    public function writeClose(): void
    {
        if (\PHP_SAPI !== 'cli' && \session_status() === \PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
    }
}
