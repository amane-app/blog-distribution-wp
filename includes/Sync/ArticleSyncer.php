<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Sync;

use Amane\BlogSdk\AmaneClient;

class SyncResult
{
    public int   $created = 0;
    public int   $skipped = 0;
    public array $errors  = [];
}

class ArticleSyncer
{
    public function sync(): SyncResult
    {
        $result = new SyncResult();

        $apiUrl      = (string) get_option('amane_api_url', 'https://service.amane.app/api/v1');
        $apiToken    = (string) get_option('amane_api_token', '');
        $autoPublish = (bool)   get_option('amane_auto_publish', false);
        $category    = (int)    get_option('amane_post_category', 0);
        $author      = (int)    get_option('amane_post_author', 1);

        if (! $apiToken) {
            $result->errors[] = 'API token is not configured.';
            return $result;
        }

        try {
            $client   = AmaneClient::make($apiUrl, $apiToken);
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

            // Check for existing WP post with this AMANE article ID
            $existing = new \WP_Query([
                'post_type'  => 'post',
                'meta_query' => [
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
