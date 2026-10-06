<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Tests;

use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Server\CallObserver;
use Dimkox\Mcp\Server\McpServer;

final class McpServerTest extends TestCase
{
    private function call(string $method, array $params = [], ?AbilityContext $ctx = null, ?McpServer $server = null): array
    {
        $server ??= new McpServer(self::registry(), instructions: 'Use the tools.');
        $ctx ??= new AbilityContext(userId: 5, allowWrite: false, protocolVersion: '2025-11-25');
        return self::wire($server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $ctx));
    }

    public function testInitializeNegotiatesVersionAndAdvertisesCapabilities(): void
    {
        $result = $this->call('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']])['result'];
        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertSame(false, $result['capabilities']['tools']['listChanged']);
        self::assertSame('bitrix-mcp', $result['serverInfo']['name']);
        self::assertSame('Use the tools.', $result['instructions']);

        $unknown = $this->call('initialize', ['protocolVersion' => '1999-01-01'])['result'];
        self::assertSame('2025-11-25', $unknown['protocolVersion']);
    }

    public function testPingReturnsEmptyObject(): void
    {
        $server = new McpServer(self::registry());
        $raw = json_encode($server->handle(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping'], new AbilityContext(1)));
        self::assertSame('{"jsonrpc":"2.0","id":"a","result":{}}', $raw);
    }

    public function testNotificationsGetNoResponse(): void
    {
        $server = new McpServer(self::registry());
        self::assertSame(null, $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], new AbilityContext(1)));
    }

    public function testToolsListHidesWriteToolsUnlessWritesAreAllowed(): void
    {
        $names = array_column($this->call('tools/list')['result']['tools'], 'name');
        self::assertSame(['demo-crash', 'demo-echo', 'demo-fail'], $names);

        $names = array_column($this->call('tools/list', [], new AbilityContext(5, allowWrite: true))['result']['tools'], 'name');
        self::assertSame(['demo-crash', 'demo-echo', 'demo-fail', 'demo-write'], $names);
    }

    public function testToolsListCarriesSchemasAndAnnotations(): void
    {
        $tools = $this->call('tools/list')['result']['tools'];
        $echo = $tools[array_search('demo-echo', array_column($tools, 'name'), true)];
        self::assertSame(['text'], $echo['inputSchema']['required']);
        self::assertSame(true, $echo['annotations']['readOnlyHint']);
        self::assertSame(false, $echo['annotations']['destructiveHint']);
        self::assertSame('object', $echo['outputSchema']['type']);
    }

    public function testEmptyPropertiesSerializeAsObject(): void
    {
        $server = new McpServer(self::registry());
        $raw = json_encode($server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], new AbilityContext(1)));
        self::assertContains('"properties":{}', $raw);
    }

    public function testToolCallAppliesDefaultsAndReturnsStructuredContent(): void
    {
        $result = $this->call('tools/call', ['name' => 'demo-echo', 'arguments' => ['text' => 'ab']])['result'];
        self::assertSame(false, $result['isError']);
        self::assertSame(['text' => 'ab'], $result['structuredContent']);
        self::assertSame('{"text":"ab"}', $result['content'][0]['text']);
    }

    public function testInvalidToolArgumentsAreReportedToTheModel(): void
    {
        $result = $this->call('tools/call', ['name' => 'demo-echo', 'arguments' => ['text' => 'x', 'times' => 9, 'extra' => 1]])['result'];
        self::assertSame(true, $result['isError']);
        self::assertContains('$.times must be <= 3', $result['content'][0]['text']);
        self::assertContains('$.extra is not allowed', $result['content'][0]['text']);
    }

    public function testPermissionDenied(): void
    {
        $result = $this->call('tools/call', ['name' => 'demo-echo', 'arguments' => ['text' => 'x']], new AbilityContext(0))['result'];
        self::assertSame(true, $result['isError']);
        self::assertContains('Access denied', $result['content'][0]['text']);
    }

    public function testAbilityErrorBecomesToolError(): void
    {
        $result = $this->call('tools/call', ['name' => 'demo-fail'])['result'];
        self::assertSame(true, $result['isError']);
        self::assertSame('Element 7 not found', $result['content'][0]['text']);
    }

    public function testUnexpectedExceptionDoesNotLeakDetails(): void
    {
        $result = $this->call('tools/call', ['name' => 'demo-crash'])['result'];
        self::assertSame(true, $result['isError']);
        self::assertTrue(!str_contains($result['content'][0]['text'], 'hunter2'), 'internal message leaked');
    }

    public function testUnknownToolAndWriteToolWithoutPermissionAreProtocolErrors(): void
    {
        self::assertSame(-32602, $this->call('tools/call', ['name' => 'nope'])['error']['code']);
        self::assertSame(-32602, $this->call('tools/call', ['name' => 'demo-write'])['error']['code']);
        $ok = $this->call('tools/call', ['name' => 'demo-write'], new AbilityContext(5, allowWrite: true))['result'];
        self::assertSame(false, $ok['isError']);
    }

    public function testDisabledAbilitiesAreInvisible(): void
    {
        $registry = self::registry();
        $registry->enableOnly(['demo-fail', 'demo-info']);
        $server = new McpServer($registry);
        $names = array_column($this->call('tools/list', [], null, $server)['result']['tools'], 'name');
        self::assertSame(['demo-fail'], $names);
        self::assertSame(-32602, $this->call('tools/call', ['name' => 'demo-echo', 'arguments' => ['text' => 'x']], null, $server)['error']['code']);
        self::assertSame([], $this->call('prompts/list', [], null, $server)['result']['prompts']);
    }

    public function testResources(): void
    {
        $list = $this->call('resources/list')['result']['resources'];
        self::assertSame('bitrix://demo/info', $list[0]['uri']);
        $read = $this->call('resources/read', ['uri' => 'bitrix://demo/info'])['result'];
        self::assertSame('{"site":"Демо"}', $read['contents'][0]['text']);
        self::assertSame(-32002, $this->call('resources/read', ['uri' => 'bitrix://nope'])['error']['code']);
        self::assertSame([], $this->call('resources/templates/list')['result']['resourceTemplates']);
    }

    public function testPrompts(): void
    {
        $list = $this->call('prompts/list')['result']['prompts'];
        self::assertSame('topic', $list[0]['arguments'][0]['name']);
        self::assertSame(true, $list[0]['arguments'][0]['required']);
        $prompt = $this->call('prompts/get', ['name' => 'demo-prompt', 'arguments' => ['topic' => 'котах']])['result'];
        self::assertSame('user', $prompt['messages'][0]['role']);
        self::assertSame('Напиши о котах', $prompt['messages'][0]['content']['text']);
        self::assertSame(-32602, $this->call('prompts/get', ['name' => 'demo-prompt'])['error']['code']);
    }

    public function testProtocolErrors(): void
    {
        self::assertSame(-32601, $this->call('tools/unknown')['error']['code']);
        $server = new McpServer(self::registry());
        $bad = self::wire($server->handle(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'], new AbilityContext(1)));
        self::assertSame(-32600, $bad['error']['code']);
        $badParams = self::wire($server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [1, 2]], new AbilityContext(1)));
        self::assertSame(-32602, $badParams['error']['code']);
        self::assertSame(-32602, $this->call('tools/list', ['cursor' => '!!'])['error']['code']);
    }

    public function testObserverSeesEveryOutcome(): void
    {
        $observer = new class () implements CallObserver {
            public array $log = [];

            public function onCall(string $kind, string $name, AbilityContext $context, string $outcome, float $durationMs, ?string $message): void
            {
                $this->log[] = "$name:$outcome";
            }
        };
        $server = new McpServer(self::registry(), observer: $observer);
        $this->call('tools/call', ['name' => 'demo-echo', 'arguments' => ['text' => 'x']], null, $server);
        $this->call('tools/call', ['name' => 'demo-echo', 'arguments' => []], null, $server);
        $this->call('tools/call', ['name' => 'demo-fail'], null, $server);
        $this->call('tools/call', ['name' => 'demo-echo', 'arguments' => ['text' => 'x']], new AbilityContext(0), $server);
        self::assertSame(['demo-echo:ok', 'demo-echo:invalid', 'demo-fail:error', 'demo-echo:denied'], $observer->log);
    }
}
