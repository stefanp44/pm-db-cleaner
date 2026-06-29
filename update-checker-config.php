<?php
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/load-v5p7.php';

$updateChecker = YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
    'https://github.com/stefanp44/pm-db-cleaner/',
    plugin_dir_path( __FILE__ ) . 'pm-db-cleaner.php',
    'pm-db-cleaner'
);

$updateChecker->setBranch('main');
$updateChecker->addResultFilter(function($info) {
   $info->icons = [
    '1x' => 'https://raw.githubusercontent.com/stefanp44/pm-db-cleaner/main/assets/img/icon-128x128.png',
    '2x' => 'https://raw.githubusercontent.com/stefanp44/pm-db-cleaner/main/assets/img/icon-256x256.png',
];
    return $info;
});