<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Apply;

/**
 * WordPress 自動適用の pull → 適用 → 報告のオーケストレーション。
 *
 * 1. queued ジョブを pull → target_url を post に解決 → before 取得 → 適用 → applied 報告
 *    (解決不可は failed=post_not_resolved 報告してスキップ)
 * 2. revert_requested ジョブを pull → before に書き戻し → reverted 報告
 *
 * head 限定なので本文/レイアウトには触れない。1 ジョブずつ処理し、失敗は他に波及しない。
 */
class ApplyRunner
{
    private ?ApplyClient $client;
    private SeoPluginAdapter $adapter;

    public function __construct(?ApplyClient $client = null, ?SeoPluginAdapter $adapter = null)
    {
        $this->client = $client;
        $this->adapter = $adapter ?? new SeoPluginAdapter();
    }

    public function run(): ApplyResult
    {
        $result = new ApplyResult();

        $client = $this->client ?? $this->makeClient();
        if ($client === null) {
            $result->errors[] = 'API token is not configured.';
            return $result;
        }

        $this->processApply($client, $result);
        $this->processRevert($client, $result);

        return $result;
    }

    private function makeClient(): ?ApplyClient
    {
        $apiUrl = trim((string) get_option('amane_api_url', 'https://service.amane.app'));
        $token  = trim((string) get_option('amane_api_token', ''));
        if ($token === '') {
            return null;
        }

        return new ApplyClient($apiUrl, $token);
    }

    private function processApply(ApplyClient $client, ApplyResult $result): void
    {
        try {
            $jobs = $client->listJobs('queued');
        } catch (\Throwable $e) {
            $result->errors[] = 'Failed to pull queued jobs: ' . $e->getMessage();
            return;
        }

        foreach ($jobs as $job) {
            $jobId = (int) ($job['job_id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $url = (string) ($job['url'] ?? '');
            $postId = $url !== '' ? (int) url_to_postid($url) : 0;

            if ($postId <= 0) {
                $this->safeReportResult($client, $jobId, [
                    'status' => 'failed',
                    'error'  => 'post_not_resolved',
                ], $result);
                $result->failed++;
                continue;
            }

            try {
                $changes = (array) ($job['changes'] ?? []);
                $plugin  = $this->adapter->detect();
                $before  = $this->adapter->readCurrent($postId, $plugin);

                $this->adapter->apply($postId, $changes, $plugin);

                $client->reportResult($jobId, [
                    'status'          => 'applied',
                    'applied_at'      => $this->now(),
                    'before_snapshot' => $before,
                    'seo_plugin'      => $plugin,
                    'target_post_id'  => $postId,
                ]);
                $result->applied++;
            } catch (\Throwable $e) {
                $result->errors[] = "Apply job {$jobId} failed: " . $e->getMessage();
                $this->safeReportResult($client, $jobId, [
                    'status' => 'failed',
                    'error'  => substr($e->getMessage(), 0, 500),
                ], $result);
                $result->failed++;
            }
        }
    }

    private function processRevert(ApplyClient $client, ApplyResult $result): void
    {
        try {
            $jobs = $client->listJobs('revert_requested');
        } catch (\Throwable $e) {
            $result->errors[] = 'Failed to pull revert jobs: ' . $e->getMessage();
            return;
        }

        foreach ($jobs as $job) {
            $jobId = (int) ($job['job_id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $postId = (int) ($job['target_post_id'] ?? 0);
            if ($postId <= 0 && ! empty($job['url'])) {
                $postId = (int) url_to_postid((string) $job['url']);
            }
            $before = (array) ($job['before_snapshot'] ?? []);

            try {
                if ($postId > 0) {
                    $this->adapter->restore($postId, $before, $this->adapter->detect());
                }
                $client->reportRevertResult($jobId, ['reverted_at' => $this->now()]);
                $result->reverted++;
            } catch (\Throwable $e) {
                $result->errors[] = "Revert job {$jobId} failed: " . $e->getMessage();
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function safeReportResult(ApplyClient $client, int $jobId, array $payload, ApplyResult $result): void
    {
        try {
            $client->reportResult($jobId, $payload);
        } catch (\Throwable $e) {
            $result->errors[] = "Report result for job {$jobId} failed: " . $e->getMessage();
        }
    }

    private function now(): string
    {
        return gmdate('c');
    }
}
