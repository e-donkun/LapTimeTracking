<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['ltt_config'] = require APP_ROOT . '/config.php';

function config(string $key, $default = null)
{
    return $GLOBALS['ltt_config'][$key] ?? $default;
}

date_default_timezone_set((string) config('timezone', 'Asia/Tokyo'));
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Repo.php';
require_once __DIR__ . '/Results.php';
require_once __DIR__ . '/Roster.php';
require_once __DIR__ . '/Xlsx.php';
require_once __DIR__ . '/Export.php';
require_once __DIR__ . '/View.php';
