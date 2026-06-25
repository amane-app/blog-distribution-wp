<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$testsDir = getenv('WP_TESTS_DIR') ?: (sys_get_temp_dir() . '/wordpress-tests-lib');

require $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/amane-blog-distribution.php';
});

require $testsDir . '/includes/bootstrap.php';
