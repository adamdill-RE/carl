<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Mcp\Server;
use Carl\Mcp\Tools;

/**
 * `POST /mcp`: the Streamable HTTP transport, stateless (Phase 16).
 *
 * The transport was designed for exactly this hosting, and this controller
 * is the proof: every client message is one POST carrying one JSON-RPC
 * message, and the server answers with `application/json` -- which the
 * specification allows in place of an event stream, and which is the only
 * thing hosting Section 3 permits (no held-open connections under the LVE
 * entry-process cap). The GET side, which exists for server-initiated
 * messages, is 405 from the router. No `Mcp-Session-Id` is ever issued, so
 * there is no session to hold, and each tool call is one ordinary PHP
 * request with a statement count like every other.
 *
 * The bearer, the Origin check and the rate limit have already happened in
 * App::guard() by the time this runs -- Route::BEARER_ACCESS -- so the user
 * here is the token's owner and every repository scopes to them.
 */
final class McpController extends Controller
{
    public function endpoint(Request $request): Response
    {
        // The specification's version header, on every request after
        // initialize: an unsupported one is 400, an absent one is assumed to
        // be 2025-03-26, exactly as the transport section says.
        $asked = $request->header('MCP-Protocol-Version');
        if ($asked !== null && $asked !== '' && !Server::supportsVersion($asked)) {
            return self::json(Server::error(null, Server::INVALID_REQUEST,
                'Unsupported MCP-Protocol-Version: ' . $asked . '. Supported: '
                . \implode(', ', Server::PROTOCOL_VERSIONS) . '.'), 400);
        }

        $decoded = \json_decode($request->rawBody(), true);
        if (!\is_array($decoded)) {
            return self::json(Server::error(null, Server::PARSE_ERROR, 'The body is not JSON.'), 400);
        }
        if (\array_is_list($decoded)) {
            // Removed from the protocol in 2025-06-18; one message per POST.
            return self::json(Server::error(null, Server::INVALID_REQUEST,
                'JSON-RPC batching is not supported; send one message per request.'), 400);
        }

        $user = $this->user();
        $config = $this->app->config();
        $server = new Server(
            new Tools($this->app, $user, $this->today(), $config->int('mcp.max_response_bytes', 262144)),
            $config->string('mcp.version', '1.0'),
        );

        $reply = $server->dispatch($decoded);
        if ($reply === null) {
            // A notification: accepted, nothing to say.
            return Response::text('', 202);
        }

        return self::json($reply, 200);
    }

    /** @param array<string,mixed> $data */
    private static function json(array $data, int $status): Response
    {
        return Response::json($data, $status)
            ->withHeader('MCP-Protocol-Version', Server::PROTOCOL_VERSIONS[0]);
    }
}
