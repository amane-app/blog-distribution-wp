<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Support;

use Amane\WpPlugin\Sdk\ClientFactory;

final class FakeArticlesResource
{
    /** @var object[] */
    private array $list;
    /** @var array<string, object> */
    private array $contents;
    /** @var array<int, array{0:string,1:string}> */
    public array $reported = [];

    public function __construct(array $list, array $contents)
    {
        $this->list = $list;
        $this->contents = $contents;
    }

    public function list(array $params = []): object
    {
        return (object) ['data' => $this->list];
    }

    public function get(string $id): object
    {
        return $this->contents[$id] ?? (object) ['body_html' => ''];
    }

    public function reportPublication(string $id, string $url): void
    {
        $this->reported[] = [$id, $url];
    }
}

final class FakeClient
{
    private FakeArticlesResource $articles;

    public function __construct(FakeArticlesResource $articles)
    {
        $this->articles = $articles;
    }

    public function articles(): FakeArticlesResource
    {
        return $this->articles;
    }
}

final class FakeClientFactory extends ClientFactory
{
    private FakeArticlesResource $articles;

    /**
     * @param object[]               $list     list() が返す記事メタ（stdClass: id,title,...）
     * @param array<string, object>  $contents get() が返す本文（id => stdClass: body_html）
     */
    public function __construct(array $list = [], array $contents = [])
    {
        $this->articles = new FakeArticlesResource($list, $contents);
    }

    public function make(string $url, string $token): object
    {
        return new FakeClient($this->articles);
    }

    public function articlesResource(): FakeArticlesResource
    {
        return $this->articles;
    }
}
