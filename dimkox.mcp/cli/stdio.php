<?php

/*
 * MCP over STDIO for local agents (Claude Desktop, Claude Code, Cursor...).
 *
 *   BITRIX_MCP_TOKEN=bxmcp_... php local/modules/dimkox.mcp/cli/stdio.php --root=/var/www/site
 *
 * One JSON-RPC message per line on stdin, one response per line on stdout,
 * diagnostics on stderr. The token is read from the environment so it does not
 * show up in the process list.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['root:']);
$root = rtrim((string) ($options['root'] ?? getenv('BITRIX_DOCUMENT_ROOT') ?: ''), '/');
$token = (string) getenv('BITRIX_MCP_TOKEN');
if ($root === '' || !is_file("$root/bitrix/modules/main/include/prolog_before.php") || $token === '') {
    fwrite(STDERR, "Usage: BITRIX_MCP_TOKEN=<token> php stdio.php --root=<site document root>\n");
    exit(2);
}

$_SERVER['DOCUMENT_ROOT'] = $root;
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('BX_SECURITY_SESSION_VIRTUAL', true);
require "$root/bitrix/modules/main/include/prolog_before.php";

// Bitrix may have started output buffering; STDIO needs every byte on stdout to be protocol.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!\Bitrix\Main\Loader::includeModule('dimkox.mcp')) {
    fwrite(STDERR, "The dimkox.mcp module is not installed\n");
    exit(3);
}

use Dimkox\Mcp\Bitrix\Endpoint;
use Dimkox\Mcp\Bitrix\Settings;
use Dimkox\Mcp\Protocol\JsonRpc;
use Dimkox\Mcp\Protocol\ProtocolError;
use Dimkox\Mcp\Protocol\VersionNegotiator;

if (!Settings::isEnabled()) {
    fwrite(STDERR, "The MCP server is turned off in the module settings\n");
    exit(4);
}
$context = Endpoint::authenticate($token);
if ($context === null) {
    fwrite(STDERR, "Invalid, revoked or expired token\n");
    exit(5);
}

$server = Endpoint::server();
$version = VersionNegotiator::latest();
fwrite(STDERR, "bitrix-mcp ready (user {$context->userId})\n");

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    try {
        $message = JsonRpc::decode($line);
    } catch (ProtocolError $e) {
        fwrite(STDOUT, JsonRpc::encode(JsonRpc::error(null, $e)) . "\n");
        continue;
    }
    // A revoked or expired token or a deactivated user stops the session at the next message.
    if (!Settings::isEnabled() || Endpoint::authenticate($token) === null) {
        fwrite(STDERR, "Access withdrawn (server disabled or token no longer valid); exiting\n");
        exit(6);
    }
    if (($message['method'] ?? null) === 'initialize') {
        $version = VersionNegotiator::negotiate($message['params']['protocolVersion'] ?? null);
    }
    $response = $server->handle($message, $context->withProtocolVersion($version));
    if ($response !== null) {
        fwrite(STDOUT, JsonRpc::encode($response) . "\n");
    }
}
