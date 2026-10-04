<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$app = Util::h((string) config('app_name'));
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $app ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<header class="topbar"><a class="brand" href="./"><img src="assets/icon.svg" alt="" width="24" height="24"> <?= $app ?></a></header>
<main class="container landing">
  <h1>駅伝（3人リレー）ラップ計測システム</h1>
  <div class="landing-grid">
    <a class="card landing-card" href="m/">
      <span class="landing-icon">⏱</span>
      <strong>計測端末</strong>
      <span class="muted">スマートフォンでビブ番号を入力してラップを記録します</span>
    </a>
    <a class="card landing-card" href="admin/">
      <span class="landing-icon">📋</span>
      <strong>管理画面</strong>
      <span class="muted">大会・選手の登録、リアルタイム集計、Excel 出力</span>
    </a>
  </div>
</main>
</body>
</html>
