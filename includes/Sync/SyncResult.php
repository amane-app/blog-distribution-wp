<?php

declare(strict_types=1);

namespace Amane\WpPlugin\Sync;

class SyncResult
{
    public int   $created = 0;
    public int   $skipped = 0;
    public array $errors  = [];
}
