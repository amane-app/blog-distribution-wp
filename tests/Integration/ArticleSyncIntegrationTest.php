<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Tests\Integration;

use Amane\WpPlugin\Sync\ArticleSyncer;
use Amane\WpPlugin\Tests\Support\FakeClientFactory;
use WP_UnitTestCase;

final class ArticleSyncIntegrationTest extends WP_UnitTestCase
{
    public function test_creates_draft_post_with_meta(): void
    {
        update_option('amane_api_token', 'amb_token');
        update_option('amane_auto_publish', false);

        $factory = new FakeClientFactory(
            [(object) ['id' => 'a1', 'title' => 'Integration Title']],
            ['a1' => (object) ['body_html' => '<p>Integration body</p>']],
        );

        $result = (new ArticleSyncer($factory))->sync();

        self::assertSame(1, $result->created);
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'draft', 'numberposts' => -1]);
        self::assertCount(1, $posts);
        self::assertSame('Integration Title', $posts[0]->post_title);
        self::assertSame('a1', get_post_meta($posts[0]->ID, '_amane_article_id', true));
    }

    public function test_skips_already_imported_article(): void
    {
        update_option('amane_api_token', 'amb_token');

        $postId = self::factory()->post->create(['post_status' => 'draft']);
        add_post_meta($postId, '_amane_article_id', 'a1', true);

        $factory = new FakeClientFactory(
            [(object) ['id' => 'a1', 'title' => 'Dup']],
            ['a1' => (object) ['body_html' => '<p>x</p>']],
        );

        $result = (new ArticleSyncer($factory))->sync();

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->skipped);
    }

    public function test_publishes_and_reports_when_auto_publish_enabled(): void
    {
        update_option('amane_api_token', 'amb_token');
        update_option('amane_auto_publish', true);

        $factory = new FakeClientFactory(
            [(object) ['id' => 'a2', 'title' => 'Auto']],
            ['a2' => (object) ['body_html' => '<p>auto</p>']],
        );

        $result = (new ArticleSyncer($factory))->sync();

        self::assertSame(1, $result->created);
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1]);
        self::assertCount(1, $posts);
    }
}
