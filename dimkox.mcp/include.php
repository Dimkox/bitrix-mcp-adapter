<?php

/*
 * Classes live in lib/ with PSR-4 casing (lib/Server/McpServer.php), which the
 * default Bitrix autoloader (lowercase paths) does not find on every core
 * version, so the module registers its own autoloader.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Dimkox\\Mcp\\')) {
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', substr($class, strlen('Dimkox\\Mcp\\'))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
