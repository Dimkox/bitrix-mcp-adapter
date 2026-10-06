<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Tests;

use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Http\HttpHandler;
use Dimkox\Mcp\Server\McpServer;

final class HttpHandlerTest extends TestCase
{
    private const TOKEN = 'bxmcp_0123456789abcdef0123456789abcdef';

    private function handler(): HttpHandler
    {
        return new HttpHandler(
            new McpServer(self::registry()),
            static fn (string $token): ?AbilityContext => $token === self::TOKEN ? new AbilityContext(5) : null,
            ['shop.example.ru', 'https://admin.example.ru'],
        );
    }

    private function post(array|string $message, array $headers = []): \Dimkox\Mcp\Http\HttpResponse
    {
        $body = is_string($message) ? $message : json_encode($message);
        return $this->handler()->handle('POST', $headers + ['Authorization' => 'Bearer ' . self::TOKEN, 'Content-Type' => 'application/json'], $body);
    }

    public function testInitializeOverHttp(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]);
        self::assertSame(200, $response->status);
        self::assertContains('application/json', $response->headers['Content-Type']);
        self::assertSame('2025-11-25', json_decode($response->body, true)['result']['protocolVersion']);
    }

    public function testMissingOrWrongTokenIs401(): void
    {
        $response = $this->handler()->handle('POST', ['Content-Type' => 'application/json'], '{}');
        self::assertSame(401, $response->status);
        self::assertContains('Bearer realm=', $response->headers['WWW-Authenticate']);
        self::assertSame(401, $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer wrong-token-wrong-token'])->status);
    }

    public function testNotificationIs202(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertSame(202, $response->status);
        self::assertSame('', $response->body);
    }

    public function testGetAndDeleteAre405(): void
    {
        self::assertSame(405, $this->handler()->handle('GET', [], '')->status);
        self::assertSame(405, $this->handler()->handle('DELETE', [], '')->status);
    }

    public function testOriginValidation(): void
    {
        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        self::assertSame(403, $this->post($ping, ['Origin' => 'https://evil.example.com'])->status);
        self::assertSame(200, $this->post($ping, ['Origin' => 'https://shop.example.ru'])->status);
        self::assertSame(200, $this->post($ping, ['Origin' => 'https://admin.example.ru'])->status);
        self::assertSame(403, $this->post($ping, ['Origin' => 'http://admin.example.ru'])->status);
    }

    public function testCorsForAllowedBrowserOrigins(): void
    {
        $preflight = $this->handler()->handle('OPTIONS', ['Origin' => 'https://shop.example.ru'], '');
        self::assertSame(204, $preflight->status);
        self::assertSame('https://shop.example.ru', $preflight->headers['Access-Control-Allow-Origin']);
        self::assertContains('Authorization', $preflight->headers['Access-Control-Allow-Headers']);
        self::assertContains('MCP-Protocol-Version', $preflight->headers['Access-Control-Allow-Headers']);
        $post = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'https://shop.example.ru']);
        self::assertSame('https://shop.example.ru', $post->headers['Access-Control-Allow-Origin']);
        self::assertTrue(!isset($this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->headers['Access-Control-Allow-Origin']));
        self::assertTrue(!isset($this->handler()->handle('OPTIONS', ['Origin' => 'https://evil.example.com'], '')->headers['Access-Control-Allow-Origin']));
    }

    public function testBadBodies(): void
    {
        self::assertSame(400, $this->post('{not json')->status);
        self::assertSame(-32700, json_decode($this->post('{not json')->body, true)['error']['code']);
        $batch = $this->post([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]);
        self::assertSame(400, $batch->status);
        self::assertSame(415, $this->post('{}', ['Content-Type' => 'text/plain'])->status);
        self::assertSame(413, $this->post(str_repeat(' ', HttpHandler::MAX_BODY_BYTES + 1))->status);
    }

    public function testUnsupportedProtocolVersionHeaderIs400(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['MCP-Protocol-Version' => '2024-01-01']);
        self::assertSame(400, $response->status);
        self::assertSame(3, json_decode($response->body, true)['id']);
        self::assertSame(200, $this->post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['MCP-Protocol-Version' => '2025-06-18'])->status);
    }

    public function testToolCallOverHttp(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'demo-echo', 'arguments' => ['text' => 'hi', 'times' => 2]]]);
        $result = json_decode($response->body, true)['result'];
        self::assertSame(['text' => 'hihi'], $result['structuredContent']);
    }
}
