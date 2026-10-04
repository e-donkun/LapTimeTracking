<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

Auth::startSession();
$error = '';
$back = (string) ($_GET['back'] ?? $_POST['back'] ?? '');
// オープンリダイレクト防止: 同一サイト内の管理画面パスのみ許可
if (!preg_match('#^/[^/\\\\]#', $back) || !str_contains($back, '/admin/')) {
    $back = 'index.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'セッションの有効期限が切れました。もう一度お試しください。';
    } elseif (Auth::login($user, $pass)) {
        header('Location: ' . $back);
        exit;
    } else {
        usleep(500000);
        $error = 'ユーザ名またはパスワードが違います。';
    }
}

View::header('ログイン', null);
?>
<section class="login-box card">
  <h1>管理画面ログイン</h1>
  <?php if ($error !== ''): ?><p class="alert alert-error"><?= Util::h($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= Util::h(Auth::csrfToken()) ?>">
    <input type="hidden" name="back" value="<?= Util::h($back) ?>">
    <label>ユーザ名<input name="username" value="<?= Util::h((string) ($_POST['username'] ?? 'admin')) ?>" required autocomplete="username"></label>
    <label>パスワード<input type="password" name="password" required autofocus autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit">ログイン</button>
  </form>
</section>
<?php View::footer(); ?>
