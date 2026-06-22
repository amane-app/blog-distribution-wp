<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Cron;

use Amane\WpPlugin\Sync\ArticleSyncer;

class ArticleSyncJob
{
    public static function handle(): void
    {
        $syncer = new ArticleSyncer();
        $result = $syncer->sync();

        error_log(sprintf(
            'AMANE sync: created=%d skipped=%d',
            $result->created,
            $result->skipped,
        ));

        foreach ($result->errors as $error) {
            error_log('AMANE sync error: ' . $error);
        }
    }
}
