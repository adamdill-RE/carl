<?php

declare(strict_types=1);

namespace Carl\Analysis;

use Carl\Core\HttpClient;

/**
 * The Anthropic Messages API, over the same raw-curl client every other
 * outbound call in Carl uses.
 *
 * There is an official PHP SDK and Carl cannot have it: nothing installs
 * packages on this host and CI fails the build if a `composer.json` appears
 * (hosting Section 3). `Carl\Core\HttpClient` is what the weather sync and the
 * Brevo mail driver already go through, so this inherits their timeout,
 * their certificate verification and their quota recognition rather than
 * growing a second curl handle with its own opinions.
 *
 * **This class must never be constructed on a request path.** It is reached
 * from `Analyst::drain()`, which is reached from cron (Phase 3 handoff
 * Section 5). The interface below is what makes that testable: the suite
 * drives a stub, exactly as `WeatherProvider` lets the weather tests run
 * without spending Open-Meteo's quota -- and here the quota is money.
 *
 * Wire shape (verified against the API reference, 2026-08-31):
 *
 *   POST https://api.anthropic.com/v1/messages
 *   x-api-key: <key>            anthropic-version: 2023-06-01
 *   {"model":..., "max_tokens":..., "system":..., "messages":[{"role":"user",...}]}
 *
 * Response: `content` is an array of blocks and only the ones with
 * `type == "text"` are the answer -- with thinking on, which is the default
 * on the current models, a thinking block comes first and reading
 * `content[0].text` would throw or return nothing.
 */
final class ClaudeClient implements Provider
{
    public const VERSION_HEADER = '2023-06-01';

    public function __construct(
        private HttpClient $http,
        private string $url,
        private string $apiKey,
        private string $model,
        private int $maxTokens,
        private string $effort = '',
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * One turn. No tools, no streaming, no conversation: the whole exchange
     * is a document in and prose out.
     *
     * Streaming would be the idiomatic choice for a long answer, and it is
     * the wrong one here -- the answer is stored and read later, nobody is
     * watching it arrive, and an SSE parser is a second thing to get wrong in
     * a file whose failures are all invisible until a cron log is read.
     * `max_tokens` is kept low enough that a non-streamed request finishes
     * inside the timeout instead.
     */
    public function analyse(string $systemPrompt, string $userMessage): Reply
    {
        $result = $this->http->postJson(
            $this->url,
            \array_filter([
                'model'      => $this->model,
                'max_tokens' => $this->maxTokens,
                'system'     => $systemPrompt,
                'messages'   => [['role' => 'user', 'content' => $userMessage]],
                'output_config' => $this->effort === '' ? null : ['effort' => $this->effort],
            ], static fn (mixed $v): bool => $v !== null),
            [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::VERSION_HEADER,
            ]
        );

        return self::readReply($result->status, $result->json, $result->error);
    }

    /**
     * Turn one HTTP outcome into a Reply, including the failures.
     *
     * Split out from the call so the suite can drive every documented error
     * shape through the exact code the live path uses, without a socket.
     *
     * @param array<string,mixed>|null $json
     */
    public static function readReply(int $status, ?array $json, ?string $transportError): Reply
    {
        // The API's own error envelope. It is present on every 4xx and 5xx
        // and says more than the status code does, so it is read first.
        if (\is_array($json) && ($json['type'] ?? null) === 'error') {
            $error = \is_array($json['error'] ?? null) ? $json['error'] : [];
            $type = (string) ($error['type'] ?? 'api_error');
            $message = (string) ($error['message'] ?? 'The API refused the request.');
            return Reply::failed($type . ': ' . $message, self::isRetryable($status, $type));
        }

        if ($transportError !== null || $status < 200 || $status >= 300) {
            return Reply::failed(
                $transportError ?? ('HTTP ' . $status),
                self::isRetryable($status, null)
            );
        }

        if (!\is_array($json)) {
            return Reply::failed('The API replied with something that was not JSON.', true);
        }

        // A safety refusal is a 200 with stop_reason "refusal" and no usable
        // text. Retrying it would refuse again and cost again, so it is
        // permanent.
        $stopReason = (string) ($json['stop_reason'] ?? '');
        if ($stopReason === 'refusal') {
            $details = \is_array($json['stop_details'] ?? null) ? $json['stop_details'] : [];
            return Reply::failed(
                'The model declined to answer'
                . (isset($details['category']) ? ' (' . (string) $details['category'] . ')' : '')
                . '.',
                false
            );
        }

        $text = '';
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (\is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $text = \trim($text);

        if ($text === '') {
            return Reply::failed('The API replied with no text.', true);
        }

        $usage = \is_array($json['usage'] ?? null) ? $json['usage'] : [];

        return Reply::ok(
            $text,
            (string) ($json['model'] ?? ''),
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            // Not a failure, but the answer is cut off mid-sentence and the
            // page should not pretend otherwise.
            $stopReason === 'max_tokens',
        );
    }

    /**
     * 429, 5xx and 529 are worth another attempt; 400, 401, 403, 404 and 413
     * will fail identically for ever and burning five attempts on one just
     * delays the moment somebody reads the error.
     */
    private static function isRetryable(int $status, ?string $errorType): bool
    {
        if ($errorType !== null) {
            return \in_array($errorType, ['rate_limit_error', 'api_error', 'overloaded_error'], true);
        }
        return $status === 0 || $status === 429 || $status >= 500;
    }
}
