<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// [Owner instruction, disclosed]: production (sahilmoney.in) serves this app from
// a /WebHubs subfolder via an outer .htaccess rewrite, which leaves REQUEST_URI
// carrying that prefix while SCRIPT_NAME/PHP_SELF do not match it -- Laravel/
// Symfony's base-path detection then disagrees with the real routable path.
// Stripping the prefix (and only when it is actually present, so local dev and
// any future non-subfolder deploy are untouched) fixes that. This used to live
// only as an uncommitted, server-local edit -- repeatedly destroyed by the old
// deploy.sh's bootstrap/app.php + index.php backup/restore step (see deploy.sh's
// own history) -- so it is tracked here now instead, to survive an ordinary
// `git pull`-based deploy.
$subfolder = '/WebHubs';
if (str_starts_with($_SERVER['REQUEST_URI'], $subfolder.'/') || $_SERVER['REQUEST_URI'] === $subfolder) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($subfolder)) ?: '/';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
