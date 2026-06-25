<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Unit\Sdk;

use Amane\WpPlugin\Sdk\ClientFactory;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    public function test_make_returns_sdk_client_object(): void
    {
        $client = (new ClientFactory())->make('https://service.amane.app', 'amb_dummy_token');

        self::assertIsObject($client);
        self::assertTrue(method_exists($client, 'articles'));
    }
}
