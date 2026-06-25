<?php

/**
 * Plugin Name: AMANE E2E Fake SDK
 * Description: E2E テスト時に SDK をフェイクへ差し替える（外部 API 不要）。
 */

declare(strict_types=1);

add_filter('amane_blog_client_factory', static function () {
    return new class {
        public function make(string $url, string $token): object
        {
            return new class {
                public function articles(): object
                {
                    return new class {
                        public function list(array $params = []): object
                        {
                            return (object) ['data' => [
                                (object) ['id' => 'e2e-1', 'title' => 'E2E Sample Article'],
                            ]];
                        }

                        public function get(string $id): object
                        {
                            return (object) ['body_html' => '<p>E2E body for ' . $id . '</p>'];
                        }

                        public function reportPublication(string $id, string $url): void
                        {
                        }
                    };
                }
            };
        }
    };
});
