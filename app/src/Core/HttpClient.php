<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * Outbound HTTP over the raw curl extension. No Guzzle, no SDK -- nothing
 * installs packages on this host (hosting Section 3, weather.md Section 2).
 *
 * Every third-party call is cron-only, bounded, and logged to a run table.
 * Nothing here may be reached from a page render (weather.md Section 3).
 */
final class HttpClient
{
    public function __construct(
        private string $userAgent,
        private int $timeout = 20,
    ) {
    }

    /** @param array<string,scalar|null> $query */
    public function getJson(string $url, array $query = []): HttpResult
    {
        $result = $this->get($url, $query);
        if ($result->body === '') {
            return $result;
        }

        $decoded = \json_decode($result->body, true);
        if (!\is_array($decoded)) {
            return $result->withError('Response was not JSON: ' . \substr($result->body, 0, 200));
        }

        // weather.md Section 4.3: check for the error key on every response
        // regardless of status code, and log the reason verbatim.
        if (($decoded['error'] ?? false) === true) {
            $reason = $decoded['reason'] ?? 'unspecified';
            return $result->withJson($decoded)->withError(
                'Provider error: ' . (\is_string($reason) ? $reason : \json_encode($reason))
            );
        }

        return $result->withJson($decoded);
    }

    /**
     * @param array<string,scalar|null> $query
     * @param list<string> $headers
     */
    public function get(string $url, array $query = [], array $headers = []): HttpResult
    {
        if ($query !== []) {
            $filtered = \array_filter($query, static fn ($v): bool => $v !== null);
            $url .= (\str_contains($url, '?') ? '&' : '?') . \http_build_query($filtered);
        }

        return $this->request($url, null, $headers);
    }

    /**
     * A JSON POST, for the one caller that needs it: the Brevo mail driver
     * (handoff Section 12.1). It goes through this class rather than round
     * its own curl handle so it inherits the timeouts, the certificate
     * verification and the quota recognition.
     *
     * @param array<string,mixed> $payload
     * @param list<string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = []): HttpResult
    {
        $encoded = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return new HttpResult($url, 0, '', 0.0, 'Request payload could not be encoded as JSON');
        }

        $result = $this->request($url, $encoded, \array_merge($headers, ['Content-Type: application/json']));
        if ($result->body === '') {
            return $result;
        }

        $decoded = \json_decode($result->body, true);
        return \is_array($decoded) ? $result->withJson($decoded) : $result;
    }

    /**
     * A POST with a body of bytes and the caller's own headers: what a push
     * service wants (Phase 16) -- an encrypted octet-stream with its own
     * Content-Type, Content-Encoding and Authorization. No Accept: json is
     * forced, because the caller set every header on purpose.
     *
     * @param list<string> $headers
     */
    public function postRaw(string $url, string $body, array $headers): HttpResult
    {
        return $this->request($url, $body, $headers, false);
    }

    /** @param list<string> $headers */
    private function request(string $url, ?string $postBody, array $headers, bool $acceptJson = true): HttpResult
    {
        $started = \microtime(true);
        $handle = \curl_init();
        if ($handle === false) {
            return new HttpResult($url, 0, '', 0.0, 'curl_init failed');
        }

        \curl_setopt_array($handle, [
            \CURLOPT_URL            => $url,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS      => 3,
            \CURLOPT_CONNECTTIMEOUT => \min(10, $this->timeout),
            \CURLOPT_TIMEOUT        => $this->timeout,
            \CURLOPT_USERAGENT      => $this->userAgent,
            \CURLOPT_HTTPHEADER     => \array_merge($acceptJson ? ['Accept: application/json'] : [], $headers),
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_ENCODING       => '',
        ]);

        if ($postBody !== null) {
            \curl_setopt($handle, \CURLOPT_POST, true);
            \curl_setopt($handle, \CURLOPT_POSTFIELDS, $postBody);
        }

        $body = \curl_exec($handle);
        $status = (int) \curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = \curl_error($handle);
        \curl_close($handle);

        $seconds = \microtime(true) - $started;

        if ($body === false) {
            return new HttpResult($url, $status, '', $seconds, $error !== '' ? $error : 'curl failed');
        }

        $result = new HttpResult($url, $status, (string) $body, $seconds, null);
        if ($status >= 400) {
            return $result->withError('HTTP ' . $status);
        }
        return $result;
    }

    /**
     * One retry after a delay, then give up until tomorrow. Hammering a quota
     * that resets daily just burns the account's reputation with the provider
     * (weather.md Section 8.1).
     *
     * @param array<string,scalar|null> $query
     */
    public function getJsonWithRetry(string $url, array $query, int $retryDelaySeconds, bool $sleepEnabled = true): HttpResult
    {
        $first = $this->getJson($url, $query);
        if ($first->ok()) {
            return $first;
        }

        // A quota is not a blip: retrying inside the same run cannot help, and
        // hammering a limit that resets on a clock just burns the account's
        // reputation with the provider (weather.md Section 8.1).
        //
        // Open-Meteo reports this as an error envelope whose status is not
        // always 429 -- an hourly limit came back 200 with
        // {"error":true,"reason":"Hourly API request limit exceeded..."} --
        // so the reason text decides, not the status code alone.
        if ($first->status === 429 || self::isQuota($first->error)) {
            return $first;
        }

        if ($sleepEnabled && $retryDelaySeconds > 0) {
            \sleep($retryDelaySeconds);
        }

        return $this->getJson($url, $query)->withAttempts(2);
    }

    /** Does this error say a rate limit was hit rather than something failed? */
    public static function isQuota(?string $error): bool
    {
        if ($error === null) {
            return false;
        }
        // 'HTTP 429' is the string this class itself sets when the status
        // says so, matched explicitly rather than by digits so a 429 inside a
        // response body cannot masquerade as one.
        return \preg_match(
            '/(\blimit exceeded\b|\brate limit\b|\bquota\b|\btoo many requests\b|^HTTP 429$)/i',
            $error
        ) === 1;
    }
}
