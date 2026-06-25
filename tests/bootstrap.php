<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (! file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php がありません。`composer install` を実行してください。\n");
    exit(1);
}
require $autoload;

// 単体テストで型ヒント / new に必要な最小 WP クラススタブ
if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public function __construct(int $id = 0)
        {
            $this->ID = $id;
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        private string $message;

        public function __construct($code = '', string $message = '')
        {
            $this->message = $message;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

// have_posts() の戻り値をテストから制御できる WP_Query スタブ。
// 各 new WP_Query() は $haveResults キューから1件 shift して使う。
if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var bool[] */
        public static array $haveResults = [];

        private bool $have;

        public function __construct($args = [])
        {
            $this->have = (bool) (array_shift(self::$haveResults) ?? false);
        }

        public function have_posts(): bool
        {
            return $this->have;
        }
    }
}
