<?php

/*
 * Router for PHP's built-in server: the mini app, the API and the admin on one local origin,
 * laid out as nginx serves them in production (docker/nginx/railway.conf):
 *   /miniapp/*  the mini app's files (repo's miniapp/), unknown paths → its index.html
 *   /           → 302 /miniapp/
 *   anything else → Laravel (public/ files as they are, the rest through index.php)
 *
 *   php -d extension=pdo_mysql -d extension=intl -S 127.0.0.1:8097 -t laravel/public laravel/dev/router.php
 *
 * With APP_ENV=local in laravel/.env the sign-in without Telegram is on (routes/dev.php).
 * The built-in server is single-threaded: fine for one person or one test worker.
 */

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$public = $_SERVER['DOCUMENT_ROOT'];

if ($uri === '/') {
    header('Location: /miniapp/', true, 302);
    return true;
}

if ($uri === '/miniapp' || str_starts_with($uri, '/miniapp/')) {
    $root = realpath(__DIR__ . '/../../miniapp');
    $path = substr($uri, strlen('/miniapp')) ?: '/';
    $file = realpath($root . ($path === '/' ? '/index.html' : $path));
    if ($file === false || ! str_starts_with($file, $root) || ! is_file($file)) {
        $file = $root . '/index.html';
    }
    $types = ['html' => 'text/html; charset=utf-8', 'js' => 'application/javascript', 'css' => 'text/css',
        'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

// public/ files (Filament's css / js, fonts) as they are
if ($uri !== '/' && is_file($public . $uri)) {
    return false;
}

require $public . '/index.php';
