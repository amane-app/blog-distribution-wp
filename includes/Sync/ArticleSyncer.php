<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Sync;

use Amane\WpPlugin\Sdk\ClientFactory;

class ArticleSyncer
{
    private ClientFactory $clientFactory;

    public function __construct(?ClientFactory $clientFactory = null)
    {
        // 未指定時はフィルタ経由で既定生成（E2E では mu-plugin で差し替え可能）
        $this->clientFactory = $clientFactory
            ?? apply_filters('amane_blog_client_factory', new ClientFactory());
    }

    public function sync(): SyncResult
    {
        $result = new SyncResult();

        // trim() で前後空白・改行 (CRLF) を除去 (= 旧プラグイン版で
        // 保存された option に \r\n が混入している場合の防御)
        $apiUrl      = trim((string) get_option('amane_api_url', 'https://service.amane.app'));
        $apiToken    = trim((string) get_option('amane_api_token', ''));
        $autoPublish = (bool)   get_option('amane_auto_publish', false);
        $category    = (int)    get_option('amane_post_category', 0);
        $author      = (int)    get_option('amane_post_author', 1);

        if (! $apiToken) {
            $result->errors[] = 'API token is not configured.';
            return $result;
        }

        try {
            $client   = $this->clientFactory->make($apiUrl, $apiToken);
            $response = $client->articles()->list(['status' => 'completed']);
        } catch (\Throwable $e) {
            $result->errors[] = 'Failed to fetch articles: ' . $e->getMessage();
            return $result;
        }

        $articles = (array) ($response->data ?? []);

        foreach ($articles as $article) {
            $articleId = (string) ($article->id ?? '');

            if (! $articleId) {
                continue;
            }

            $existing = new \WP_Query([
                'post_type'   => 'post',
                'post_status' => 'any',
                'meta_query'  => [
                    [
                        'key'   => '_amane_article_id',
                        'value' => $articleId,
                    ],
                ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);

            if ($existing->have_posts()) {
                $result->skipped++;
                continue;
            }

            try {
                $content = $client->articles()->get($articleId);
            } catch (\Throwable $e) {
                $result->errors[] = "Failed to fetch article {$articleId}: " . $e->getMessage();
                continue;
            }

            $postData = [
                'post_title'    => (string) ($article->title ?? $article->target_keyword ?? ''),
                'post_content'  => (string) ($content->body_html ?? ''),
                'post_status'   => $autoPublish ? 'publish' : 'draft',
                'post_author'   => $author,
                'post_category' => $category > 0 ? [$category] : [],
            ];

            $postId = wp_insert_post($postData, true);

            if (is_wp_error($postId)) {
                $result->errors[] = "Failed to insert post for article {$articleId}: " . $postId->get_error_message();
                continue;
            }

            add_post_meta($postId, '_amane_article_id', $articleId, true);

            if ($autoPublish) {
                try {
                    $permalink = get_permalink($postId);
                    if ($permalink) {
                        $client->articles()->reportPublication($articleId, $permalink);
                    }
                } catch (\Throwable $e) {
                    $result->errors[] = "reportPublication failed for article {$articleId}: " . $e->getMessage();
                }
            }

            $result->created++;
        }

        return $result;
    }
}
