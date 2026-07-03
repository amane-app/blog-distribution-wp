<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Apply;

/**
 * AMANEA WordPress 自動適用 V1 API のクライアント。
 *
 * ブログ配信の SDK (amane/blog-sdk) とは別に、WordPress HTTP API
 * (wp_remote_*) で直接叩く。SDK はまだ wp-apply エンドポイントを持たないため、
 * プラグイン内に自己完結した薄いクライアントを置く。
 */
class ApplyClient
{
    private string $base;
    private string $token;

    public function __construct(string $apiUrl, string $token)
    {
        // 設定値は 'https://service.amane.app' でも '.../api/v1' でも許容。
        // 末尾スラッシュ / 末尾 /api/v1 を落として base に正規化する。
        $u = rtrim(trim($apiUrl), '/');
        $u = (string) preg_replace('#/api/v1$#', '', $u);
        $this->base = $u;
        $this->token = trim($token);
    }

    /**
     * 指定ステータスの適用ジョブ一覧を取得する。
     *
     * @return array<int,array<string,mixed>> job_id / suggestion_id / url / changes / target_post_id / before_snapshot
     */
    public function listJobs(string $status): array
    {
        $url = $this->base . '/api/v1/wp/apply-jobs?status=' . rawurlencode($status);
        $body = $this->request('GET', $url, null);

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * 1 ジョブの適用結果を報告する。
     *
     * @param array<string,mixed> $payload status / applied_at / before_snapshot / seo_plugin / target_post_id / error
     */
    public function reportResult(int $jobId, array $payload): void
    {
        $url = $this->base . '/api/v1/wp/apply-jobs/' . $jobId . '/result';
        $this->request('POST', $url, $payload);
    }

    /**
     * 1 ジョブの取消結果を報告する。
     *
     * @param array<string,mixed> $payload reverted_at
     */
    public function reportRevertResult(int $jobId, array $payload): void
    {
        $url = $this->base . '/api/v1/wp/apply-jobs/' . $jobId . '/revert-result';
        $this->request('POST', $url, $payload);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, ?array $body): array
    {
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = $method === 'GET'
            ? wp_remote_get($url, $args)
            : wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException('HTTP error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("Unexpected status {$code}: " . $raw);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
