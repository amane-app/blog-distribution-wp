<?php

declare(strict_types=1);

// Usage: php bin/coverage-check.php build/clover.xml 90
$file = $argv[1] ?? 'build/clover.xml';
$min  = (float) ($argv[2] ?? '90');

if (! is_file($file)) {
    fwrite(STDERR, "clover が見つかりません: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false || ! isset($xml->project->metrics)) {
    fwrite(STDERR, "clover の metrics を解析できません: {$file}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$covered = (int) $metrics['coveredstatements'];
$total   = (int) $metrics['statements'];
$pct     = $total > 0 ? ($covered / $total) * 100 : 0.0;

printf("Coverage: %.2f%% (%d/%d statements)\n", $pct, $covered, $total);

if ($pct + 1e-9 < $min) {
    fwrite(STDERR, sprintf("FAIL: カバレッジ %.2f%% < 閾値 %.1f%%\n", $pct, $min));
    exit(1);
}

echo "PASS\n";
