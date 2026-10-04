<?php
declare(strict_types=1);

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string) config('session_name', 'LTTSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }

    /** @return array{id:int, username:string, role:string}|null */
    public static function user(): ?array
    {
        // セッション Cookie が無い（計測端末・CLI）ならセッションを開始しない
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[(string) config('session_name', 'LTTSESSID')])) {
            return null;
        }
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function login(string $username, string $password): bool
    {
        if ($username === (string) config('admin_username')) {
            self::syncFixedAdmin();
        }
        $row = Db::one('SELECT * FROM users WHERE username = ? AND is_active = 1', [$username]);
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'       => (int) $row['id'],
            'username' => (string) $row['username'],
            'role'     => (string) $row['role'],
        ];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Db::exec("UPDATE users SET last_login_at = datetime('now','localtime') WHERE id = ?", [$row['id']]);
        Util::audit(null, 'login', '');
        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $token);
    }

    /** 管理画面ページ用: 未ログインならログイン画面へ */
    public static function requirePage(): array
    {
        $user = self::user();
        if (!$user) {
            $back = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: login.php?back=' . rawurlencode($back));
            exit;
        }
        return $user;
    }

    /**
     * 固定の管理者アカウント（config の admin_username / admin_password）を users テーブルへ同期する。
     * 将来は users テーブルに直接ユーザを追加すれば複数ユーザで運用できる。
     */
    private static function syncFixedAdmin(): void
    {
        $username = (string) config('admin_username');
        $password = (string) config('admin_password');
        $row = Db::one('SELECT id, password_hash FROM users WHERE username = ?', [$username]);
        if (!$row) {
            Db::exec(
                "INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, '管理者', 'admin')",
                [$username, password_hash($password, PASSWORD_DEFAULT)]
            );
        } elseif (!password_verify($password, (string) $row['password_hash'])) {
            Db::exec(
                "UPDATE users SET password_hash = ?, updated_at = datetime('now','localtime') WHERE id = ?",
                [password_hash($password, PASSWORD_DEFAULT), $row['id']]
            );
        }
    }
}
