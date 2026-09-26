<?php

/**
 * PHPUnit bootstrap.
 *
 * A cached config (php artisan config:cache, which `php artisan optimize`
 * runs) freezes the .env values and takes precedence over the <env> entries
 * in phpunit.xml. In that state the suite would connect to the real
 * database/database.sqlite and RefreshDatabase would migrate:fresh it,
 * destroying the developer's local data.
 *
 * Deleting the cached config here, before anything boots, means the suite
 * always reads phpunit.xml and always uses the in-memory database. It is a
 * blunt instrument on purpose: a test run must never be able to touch real
 * data, whatever state the caches happen to be in.
 */
$cachePath = dirname(__DIR__).'/bootstrap/cache';

foreach (['config.php', 'routes-v7.php', 'events.php'] as $stale) {
    $path = $cachePath.DIRECTORY_SEPARATOR.$stale;

    if (is_file($path)) {
        @unlink($path);
    }
}

require dirname(__DIR__).'/vendor/autoload.php';
