<?php
declare(strict_types=1);

/** 管理画面の共通レイアウト */
final class View
{
    public static function header(string $title, ?array $user, array $opts = []): void
    {
        $app = Util::h((string) config('app_name'));
        $csrf = $user ? Util::h(Auth::csrfToken()) : '';
        $v = self::assetVersion();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{$csrf}">
<title>{$title} | {$app}</title>
<link rel="icon" href="../assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/admin.css?v={$v}">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><img src="../assets/icon.svg" alt="" width="24" height="24"> {$app}</a>
HTML;
        if ($user) {
            $name = Util::h($user['username']);
            echo <<<HTML
  <nav>
    <a href="index.php">大会一覧</a>
    <a href="../m/" target="_blank" rel="noopener">計測端末</a>
    <span class="user">{$name}</span>
    <a href="logout.php">ログアウト</a>
  </nav>
HTML;
        }
        echo "</header>\n<main class=\"container\">\n";
    }

    public static function footer(array $scripts = []): void
    {
        $v = self::assetVersion();
        echo "</main>\n<div id=\"toast\" class=\"toast\" role=\"status\" aria-live=\"polite\"></div>\n";
        echo "<script src=\"../assets/admin.js?v={$v}\"></script>\n";
        foreach ($scripts as $s) {
            echo '<script src="../assets/' . Util::h($s) . '?v=' . $v . "\"></script>\n";
        }
        echo "</body>\n</html>\n";
    }

    private static function assetVersion(): string
    {
        static $v = null;
        if ($v === null) {
            $files = glob(APP_ROOT . '/public/assets/*') ?: [];
            $v = (string) array_reduce($files, fn ($max, $f) => max($max, (int) filemtime($f)), 0);
        }
        return $v;
    }
}
