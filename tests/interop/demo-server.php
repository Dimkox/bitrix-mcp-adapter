<?php

/*
 * Demo MCP endpoint over the module core with the test abilities, no Bitrix:
 *   php -S 127.0.0.1:8765 tests/interop/demo-server.php
 * Token: bxmcp_0123456789abcdef0123456789abcdef
 */

declare(strict_types=1);

require __DIR__ . '/../../dimkox.mcp/include.php';
require __DIR__ . '/../TestCase.php';

use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Http\HttpHandler;
use Dimkox\Mcp\Server\McpServer;

$registry = (new class () extends \Dimkox\Mcp\Tests\TestCase {
    public function make(): \Dimkox\Mcp\Ability\AbilityRegistry
    {
        return self::registry();
    }
})->make();

$headers = function_exists('getallheaders') ? getallheaders() : [];
$handler = new HttpHandler(
    new McpServer($registry, instructions: 'Demo server'),
    static fn (string $token): ?AbilityContext => $token === 'bxmcp_0123456789abcdef0123456789abcdef' ? new AbilityContext(5, allowWrite: true) : null,
    ['127.0.0.1', 'localhost'],
);
$response = $handler->handle($_SERVER['REQUEST_METHOD'], $headers, (string) file_get_contents('php://input'));
http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("$name: $value");
}
echo $response->body;
