<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Sdk;

use Amane\BlogSdk\AmaneClient;

class ClientFactory
{
    /**
     * AMANE SDK クライアントを生成する。
     * テスト/E2E では本クラスを差し替えてフェイクを返す。
     */
    public function make(string $url, string $token): object
    {
        return AmaneClient::make($url, $token);
    }
}
