<?php

declare(strict_types=1);

/*
 * Dependency-free test runner: php tests/run.php [filter]
 *
 * Every public method named test* in tests/*Test.php is a test. A test fails
 * when it throws; tests/TestCase.php provides the assertions.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Dimkox\\Mcp\\Tests\\' => __DIR__ . '/',
        'Dimkox\\Mcp\\' => dirname(__DIR__) . '/dimkox.mcp/lib/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

$filter = $argv[1] ?? '';
$passed = $failed = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $class = 'Dimkox\\Mcp\\Tests\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test') || ($filter !== '' && !str_contains("$class::$method", $filter))) {
            continue;
        }
        try {
            (new $class())->$method();
            $passed++;
        } catch (\Throwable $e) {
            $failed++;
            fwrite(STDERR, sprintf("FAIL %s::%s\n  %s\n  at %s:%d\n", $class, $method, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }
}
printf("%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 && $passed > 0 ? 0 : 1);
