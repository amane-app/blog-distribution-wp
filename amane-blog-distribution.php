<?php

/**
 * Plugin Name: AMANE Blog Distribution
 * Plugin URI:  https://amane.app
 * Description: Pulls AI-generated blog articles from AMANE Blog Distribution API and publishes them as WordPress posts.
 * Version:     0.1.1
 * Author:      AMANE
 * Author URI:  https://amane.app
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: amane-blog-dist
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Amane\WpPlugin\Plugin;

$plugin = new Plugin();
$plugin->register();

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
