<?php

/*
 * MCP endpoint: https://<site>/bitrix/tools/dimkox.mcp/index.php
 * Installed by the dimkox.mcp module. Authentication: Authorization: Bearer <token>.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('PUBLIC_AJAX_MODE', true);
define('DisableEventsCheck', true);
// No session cookie: every request authenticates with its bearer token.
define('BX_SECURITY_SESSION_VIRTUAL', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!\Bitrix\Main\Loader::includeModule('dimkox.mcp')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"module_not_installed"}';
    die();
}

\Dimkox\Mcp\Bitrix\Endpoint::run();

\CMain::FinalActions();
