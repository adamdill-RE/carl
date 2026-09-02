<?php

declare(strict_types=1);

namespace Carl\Mcp;

/**
 * A tool that cannot answer as asked -- no weather location, no such plant
 * type. Becomes an isError result, which the conversation can act on, rather
 * than a JSON-RPC error, which ends it.
 */
final class ToolFailure extends \RuntimeException
{
}
