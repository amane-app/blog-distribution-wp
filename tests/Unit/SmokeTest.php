<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoloader_and_phpunit_work(): void
    {
        self::assertTrue(class_exists(\Amane\WpPlugin\Plugin::class));
    }
}
