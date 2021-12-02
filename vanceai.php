<?php

/*
 *
 * @link              https://github.com/midweste
 * @since             1.0.0
 * @package           VanceAI Api
 *
 * @wordpress-plugin
 * Plugin Name:       VanceAI Api
 * Plugin URI:        https://github.com/midweste
 * Description:       Wordpress implementation of VanceAI Api
 * Version:           1.0.0
 * Author:            Midweste
 * Author URI:        https://github.com/midweste
 * License:           GPL-2.0+
 */

defined('ABSPATH') || exit;

call_user_func(function () {
    if (!class_exists('\VanceAi\Client') && is_file(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }
});
