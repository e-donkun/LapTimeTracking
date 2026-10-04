<?php
/**
 * 既定設定。
 * 本番環境では同じディレクトリに config.local.php を作成し、上書きしたいキーだけを返してください。
 *   <?php return ['admin_password' => '強いパスワード'];
 * config.local.php は git 管理外です。
 */
$config = [
    'app_name'       => 'Lap Time Tracking',
    'timezone'       => 'Asia/Tokyo',
    'db_path'        => getenv('LTT_DB_PATH') ?: __DIR__ . '/data/laptime.sqlite',

    // 管理者アカウント（現状は固定。users テーブルに同期され、将来の複数ユーザ化に対応）
    'admin_username' => 'admin',
    'admin_password' => 'admin', // ★必ず config.local.php で変更してください

    'session_name'   => 'LTTSESSID',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, require __DIR__ . '/config.local.php');
}

return $config;
