<?php

declare(strict_types=1);

namespace Carl\Mcp;

/**
 * The JSON-RPC half of the MCP server (Phase 16; Phase 15 handoff Section 3.1).
 *
 * This class knows the Model Context Protocol's methods and envelopes and
 * nothing about HTTP or the database: McpController owns the transport and
 * Tools owns the data. It takes one decoded JSON-RPC message and returns one
 * response message, or null for a notification, which has no reply.
 *
 * WHAT IT SPEAKS. The 2025-06-18 revision of the specification, which is the
 * one that removed JSON-RPC batching -- so one message per request is the
 * whole grammar -- and older clients that ask for 2025-03-26 or 2024-11-05
 * are answered in kind, because nothing this server does differs between
 * them: it has tools and resources, no prompts, no sampling, no
 * subscriptions, and it never sends a message a client did not ask for.
 *
 * READ-ONLY, EVERY METHOD. Logging a watering from a conversation would be a
 * different feature with the tag spec's two-tap promise to keep, and it
 * should be a decision rather than a side effect of this server growing a
 * verb (Phase 15 handoff Section 3.1, last paragraph).
 */
final class Server
{
    /** Newest first. The first is what an unknown or absent request gets. */
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** JSON-RPC 2.0 error codes, plus the server's own in the -32000 range. */
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const RESOURCE_NOT_FOUND = -32002;

    public function __construct(
        private Tools $tools,
        private string $serverVersion,
    ) {
    }

    public static function supportsVersion(?string $version): bool
    {
        return $version !== null && \in_array($version, self::PROTOCOL_VERSIONS, true);
    }

    /**
     * Handle one decoded message.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null the response, or null for a notification
     */
    public function dispatch(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $isRequest = \array_key_exists('id', $message) && ($id === null || \is_int($id) || \is_string($id));
        $method = $message['method'] ?? null;
        $params = $message['params'] ?? [];

        if (!\is_string($method) || $method === '' || ($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error($id, self::INVALID_REQUEST, 'Not a JSON-RPC 2.0 request.');
        }
        if (!\is_array($params)) {
            return self::error($id, self::INVALID_PARAMS, 'params must be an object.');
        }

        // A notification: acknowledged by the transport with 202 and no body.
        if (!$isRequest || \str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize'               => $this->initialize($params),
                'ping'                     => new \stdClass(),
                'tools/list'               => ['tools' => $this->tools->catalogue()],
                'tools/call'               => $this->callTool($params),
                'resources/list'           => ['resources' => $this->tools->resources()],
                'resources/templates/list' => ['resourceTemplates' => []],
                'resources/read'           => $this->readResource($params),
                'prompts/list'             => ['prompts' => []],
                default                    => null,
            };
        } catch (\InvalidArgumentException $e) {
            return self::error($id, self::INVALID_PARAMS, $e->getMessage());
        } catch (ResourceNotFound $e) {
            return self::error($id, self::RESOURCE_NOT_FOUND, $e->getMessage());
        }

        if ($result === null) {
            return self::error($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method);
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;
        $version = self::supportsVersion(\is_string($asked) ? $asked : null)
            ? (string) $asked : self::PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => 'carl',
                'title'   => 'Carl The Garden Helper',
                'version' => $this->serverVersion,
            ],
            'instructions' => $this->tools->instructions(),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new \InvalidArgumentException('tools/call needs a tool name.');
        }
        $arguments = $params['arguments'] ?? [];
        if (!\is_array($arguments)) {
            throw new \InvalidArgumentException('arguments must be an object.');
        }
        return $this->tools->call($name, $arguments);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function readResource(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!\is_string($uri) || $uri === '') {
            throw new \InvalidArgumentException('resources/read needs a uri.');
        }
        return ['contents' => [$this->tools->readResource($uri)]];
    }

    /** @return array<string,mixed> */
    public static function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => \is_int($id) || \is_string($id) ? $id : null,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }
}
