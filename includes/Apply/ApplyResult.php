<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Apply;

/**
 * WordPress 自動適用 (pull) の 1 回の実行結果サマリ。
 */
class ApplyResult
{
    public int $applied = 0;
    public int $failed = 0;
    public int $reverted = 0;

    /** @var string[] */
    public array $errors = [];
}
