<?php

declare(strict_types=1);

namespace Dimkox\Mcp\Server;

use Dimkox\Mcp\Ability\Ability;
use Dimkox\Mcp\Ability\AbilityContext;
use Dimkox\Mcp\Ability\AbilityError;
use Dimkox\Mcp\Ability\AbilityRegistry;
use Dimkox\Mcp\Protocol\JsonRpc;
use Dimkox\Mcp\Protocol\ProtocolError;
use Dimkox\Mcp\Protocol\VersionNegotiator;
use Dimkox\Mcp\Schema\SchemaValidator;

/**
 * Transport-independent MCP server: one decoded JSON-RPC message in, one
 * response (or null for notifications) out.
 *
 * Implemented methods: initialize, ping, tools/list, tools/call,
 * resources/list, resources/read, resources/templates/list, prompts/list,
 * prompts/get, and the notifications/initialized notification.
 */
final class McpServer
{
    public const PAGE_SIZE = 50;

    private readonly SchemaValidator $validator;

    public function __construct(
        private readonly AbilityRegistry $registry,
        private readonly string $name = 'bitrix-mcp',
        private readonly string $version = '0.1.0',
        private readonly string $title = '1C-Bitrix MCP',
        private readonly string $instructions = '',
        private readonly ?CallObserver $observer = null,
    ) {
        $this->validator = new SchemaValidator();
    }

    /**
     * @param array<string, mixed> $message a decoded JSON-RPC message
     * @return array<string, mixed>|null the response; null when none is due
     */
    public function handle(array $message, AbilityContext $context): ?array
    {
        if (JsonRpc::isNotification($message) || JsonRpc::isResponse($message)) {
            return null;
        }
        $id = null;
        try {
            if (!JsonRpc::isRequest($message)) {
                throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'Not a JSON-RPC request');
            }
            $id = JsonRpc::validateId($message['id']);
            $method = $message['method'];
            if (!is_string($method)) {
                throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'The "method" member must be a string');
            }
            $params = $message['params'] ?? [];
            if (!is_array($params) || (array_is_list($params) && $params !== [])) {
                throw new ProtocolError(ProtocolError::INVALID_PARAMS, '"params" must be an object');
            }
            return JsonRpc::result($id, $this->dispatch($method, $params, $context));
        } catch (ProtocolError $e) {
            return JsonRpc::error($id, $e);
        } catch (\Throwable $e) {
            return JsonRpc::error($id, new ProtocolError(ProtocolError::INTERNAL_ERROR, 'Internal error'));
        }
    }

    /** @param array<string, mixed> $params */
    private function dispatch(string $method, array $params, AbilityContext $context): array|object
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass(),
            'tools/list' => $this->listTools($params, $context),
            'tools/call' => $this->callTool($params, $context),
            'resources/list' => $this->listResources($params),
            'resources/templates/list' => ['resourceTemplates' => []],
            'resources/read' => $this->readResource($params, $context),
            'prompts/list' => $this->listPrompts($params),
            'prompts/get' => $this->getPrompt($params, $context),
            default => throw new ProtocolError(ProtocolError::METHOD_NOT_FOUND, "Method not found: $method"),
        };
    }

    private function initialize(array $params): array
    {
        $result = [
            'protocolVersion' => VersionNegotiator::negotiate($params['protocolVersion'] ?? null),
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => ['name' => $this->name, 'title' => $this->title, 'version' => $this->version],
        ];
        if ($this->instructions !== '') {
            $result['instructions'] = $this->instructions;
        }
        return $result;
    }

    private function listTools(array $params, AbilityContext $context): array
    {
        $tools = array_filter(
            $this->registry->exposed(Ability::TOOL),
            static fn (Ability $a): bool => !$a->writes() || $context->allowWrite
        );
        return $this->paginate(array_values($tools), $params, 'tools', static function (Ability $a): array {
            $tool = [
                'name' => $a->name,
                'title' => $a->title,
                'description' => $a->description,
                'inputSchema' => $a->inputSchema,
                'annotations' => [
                    'title' => $a->title,
                    'readOnlyHint' => $a->readOnly,
                    'destructiveHint' => $a->destructive,
                    'idempotentHint' => $a->idempotent,
                    'openWorldHint' => false,
                ],
            ];
            if ($a->outputSchema !== null) {
                $tool['outputSchema'] = $a->outputSchema;
            }
            return $tool;
        });
    }

    private function callTool(array $params, AbilityContext $context): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name)) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'tools/call needs a string "name"');
        }
        $ability = $this->registry->find(Ability::TOOL, $name);
        if ($ability === null || ($ability->writes() && !$context->allowWrite)) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Unknown tool: $name");
        }
        $arguments = $this->arguments($params['arguments'] ?? []);
        $arguments = $this->validator->applyDefaults($arguments, $ability->inputSchema);

        return $this->run($ability, $arguments, $context, function (mixed $result) use ($ability, $context): array {
            $response = ['content' => [['type' => 'text', 'text' => self::toText($result)]], 'isError' => false];
            if (is_array($result) && $ability->outputSchema !== null
                && VersionNegotiator::supportsStructuredContent($context->protocolVersion ?: VersionNegotiator::latest())) {
                $response['structuredContent'] = $result === [] ? new \stdClass() : $result;
            }
            return $response;
        }, static fn (string $message): array => [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ]);
    }

    private function listResources(array $params): array
    {
        return $this->paginate($this->registry->exposed(Ability::RESOURCE), $params, 'resources', static fn (Ability $a): array => [
            'uri' => $a->uri,
            'name' => $a->name,
            'title' => $a->title,
            'description' => $a->description,
            'mimeType' => $a->mimeType,
        ]);
    }

    private function readResource(array $params, AbilityContext $context): array
    {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri)) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'resources/read needs a string "uri"');
        }
        $ability = $this->registry->findResource($uri);
        if ($ability === null) {
            throw new ProtocolError(ProtocolError::RESOURCE_NOT_FOUND, 'Resource not found', ['uri' => $uri]);
        }
        return $this->run($ability, [], $context, static function (mixed $result) use ($ability): array {
            return ['contents' => [['uri' => $ability->uri, 'mimeType' => $ability->mimeType, 'text' => self::toText($result)]]];
        }, static function (string $message) use ($uri): never {
            throw new ProtocolError(ProtocolError::RESOURCE_NOT_FOUND, $message, ['uri' => $uri]);
        });
    }

    private function listPrompts(array $params): array
    {
        return $this->paginate($this->registry->exposed(Ability::PROMPT), $params, 'prompts', static function (Ability $a): array {
            return [
                'name' => $a->name,
                'title' => $a->title,
                'description' => $a->description,
                'arguments' => array_map(static fn (array $arg): array => [
                    'name' => $arg['name'],
                    'description' => $arg['description'] ?? '',
                    'required' => (bool) ($arg['required'] ?? false),
                ], $a->arguments),
            ];
        });
    }

    private function getPrompt(array $params, AbilityContext $context): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name)) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'prompts/get needs a string "name"');
        }
        $ability = $this->registry->find(Ability::PROMPT, $name);
        if ($ability === null) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Unknown prompt: $name");
        }
        $arguments = $this->arguments($params['arguments'] ?? []);
        return $this->run($ability, $arguments, $context, static function (mixed $result) use ($ability): array {
            $messages = is_string($result)
                ? [['role' => 'user', 'content' => ['type' => 'text', 'text' => $result]]]
                : $result;
            return ['description' => $ability->description, 'messages' => $messages];
        }, static function (string $message): never {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, $message);
        });
    }

    /**
     * Validates, checks permission and executes an ability; reports to the observer.
     *
     * @param callable(mixed): array $onSuccess
     * @param callable(string): array $onFailure maps an agent-visible failure to a result (or throws)
     */
    private function run(Ability $ability, array $arguments, AbilityContext $context, callable $onSuccess, callable $onFailure): array
    {
        $started = microtime(true);
        $errors = $this->validator->validate($arguments, $ability->inputSchema);
        if ($errors !== []) {
            $this->observe($ability, $context, 'invalid', $started, implode('; ', $errors));
            if ($ability->kind === Ability::TOOL) {
                // Tool input errors are reported to the model so it can correct itself.
                return $onFailure('Invalid arguments: ' . implode('; ', $errors));
            }
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'Invalid arguments', ['errors' => $errors]);
        }
        try {
            if (!$ability->isPermitted($arguments, $context)) {
                $this->observe($ability, $context, 'denied', $started, null);
                return $onFailure('Access denied: the authenticated user may not use ' . $ability->name);
            }
            $result = $ability->execute($arguments, $context);
        } catch (AbilityError $e) {
            $this->observe($ability, $context, 'error', $started, $e->getMessage());
            return $onFailure($e->getMessage());
        } catch (ProtocolError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->observe($ability, $context, 'error', $started, get_class($e) . ': ' . $e->getMessage());
            // Internal details stay in the audit log, not in the agent's context.
            return $onFailure('The ability failed with an internal error');
        }
        $this->observe($ability, $context, 'ok', $started, null);
        return $onSuccess($result);
    }

    private function observe(Ability $ability, AbilityContext $context, string $outcome, float $started, ?string $message): void
    {
        if ($this->observer === null) {
            return;
        }
        try {
            $this->observer->onCall($ability->kind, $ability->name, $context, $outcome, (microtime(true) - $started) * 1000, $message);
        } catch (\Throwable) {
            // Auditing must never break the request.
        }
    }

    private static function toText(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
    }

    /** @return array<string, mixed> */
    private function arguments(mixed $arguments): array
    {
        if (!is_array($arguments) || (array_is_list($arguments) && $arguments !== [])) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, '"arguments" must be an object');
        }
        return $arguments;
    }

    /**
     * @param list<Ability> $items
     * @param callable(Ability): array $map
     */
    private function paginate(array $items, array $params, string $key, callable $map): array
    {
        $offset = 0;
        if (isset($params['cursor'])) {
            $decoded = is_string($params['cursor']) ? base64_decode($params['cursor'], true) : false;
            if ($decoded === false || !ctype_digit($decoded)) {
                throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'Invalid cursor');
            }
            $offset = (int) $decoded;
        }
        $page = array_slice($items, $offset, self::PAGE_SIZE);
        $result = [$key => array_map($map, $page)];
        if ($offset + self::PAGE_SIZE < count($items)) {
            $result['nextCursor'] = base64_encode((string) ($offset + self::PAGE_SIZE));
        }
        return $result;
    }
}
