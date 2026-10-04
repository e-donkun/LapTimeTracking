-- Lap Time Tracking スキーマ (SQLite)
-- 時刻はすべて「サーバ時刻基準の UNIX エポックミリ秒 (INTEGER)」で保持する。

-- 管理ユーザ（現状は admin 1名。将来の複数ユーザ管理を想定）
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    display_name  TEXT,
    role          TEXT    NOT NULL DEFAULT 'admin',   -- admin / operator など将来拡張
    is_active     INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- 大会
CREATE TABLE IF NOT EXISTS competitions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT    NOT NULL,
    event_date      TEXT,                                -- YYYY-MM-DD
    location        TEXT,
    team_size       INTEGER NOT NULL DEFAULT 3,          -- 正式参加に必要な人数
    status          TEXT    NOT NULL DEFAULT 'preparing',-- preparing / active / finished
    start_ms        INTEGER,                             -- レーススタート（サーバ時刻）
    start_set_by    TEXT,                                -- 誰がスタートを記録したか
    start_set_at    TEXT,
    device_code     TEXT    NOT NULL DEFAULT '',         -- 計測端末用パスコード（空なら不要）
    merge_window_ms INTEGER NOT NULL DEFAULT 10000,      -- 同一通過とみなす時間窓
    tolerance_ms    INTEGER NOT NULL DEFAULT 1000,       -- 端末間の許容差（超えたら警告）
    adopt_method    TEXT    NOT NULL DEFAULT 'median',   -- median / earliest
    run_no          INTEGER NOT NULL DEFAULT 1,          -- 計測回（リセットのたびに +1。集計は現在の回のみ）
    note            TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- チーム（ビブ番号で管理）
CREATE TABLE IF NOT EXISTS teams (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
    bib            INTEGER NOT NULL,                     -- 0..99（表示は2桁ゼロ埋め）
    name           TEXT    NOT NULL DEFAULT '',
    category       TEXT    NOT NULL DEFAULT '',          -- 区分（一般・中学 など）
    force_open     INTEGER NOT NULL DEFAULT 0,           -- 3名揃っていてもオープン扱いにする
    status         TEXT    NOT NULL DEFAULT '',          -- '' / DNS / DNF / DQ
    note           TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    UNIQUE (competition_id, bib)
);

-- 選手（チーム内の走順つき）
CREATE TABLE IF NOT EXISTS runners (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id     INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    leg         INTEGER NOT NULL,                        -- 走順 1..
    name        TEXT    NOT NULL,
    kana        TEXT    NOT NULL DEFAULT '',
    gender      TEXT    NOT NULL DEFAULT '',
    age         TEXT    NOT NULL DEFAULT '',             -- 学年・年齢
    affiliation TEXT    NOT NULL DEFAULT '',             -- 所属
    note        TEXT    NOT NULL DEFAULT '',
    UNIQUE (team_id, leg)
);

-- 計測端末（大会ごと）
CREATE TABLE IF NOT EXISTS devices (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    competition_id  INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
    device_uuid     TEXT    NOT NULL,
    name            TEXT    NOT NULL DEFAULT '',
    user_agent      TEXT    NOT NULL DEFAULT '',
    clock_offset_ms INTEGER,                             -- サーバ時刻 - 端末時刻
    clock_rtt_ms    INTEGER,                             -- 時刻同期時の往復遅延
    first_seen_at   TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    last_seen_at    TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    UNIQUE (competition_id, device_uuid)
);

-- 通過記録（端末で入力された生データ。削除は論理削除）
CREATE TABLE IF NOT EXISTS passes (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid              TEXT    NOT NULL UNIQUE,           -- 端末側で採番（冪等な同期のため）
    competition_id    INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
    device_uuid       TEXT    NOT NULL,                  -- 管理者入力は 'admin'
    source            TEXT    NOT NULL DEFAULT 'device', -- device / admin
    run_no            INTEGER NOT NULL DEFAULT 1,        -- どの計測回の記録か
    bib               INTEGER NOT NULL,
    time_ms           INTEGER NOT NULL,                  -- 通過時刻（サーバ時刻基準）
    client_ms         INTEGER,                           -- 端末の生時刻
    offset_ms         INTEGER,                           -- 記録時に使ったオフセット
    deleted           INTEGER NOT NULL DEFAULT 0,        -- 端末側で削除
    deleted_ms        INTEGER,
    client_updated_ms INTEGER NOT NULL DEFAULT 0,        -- 端末側の最終更新（後勝ち判定）
    admin_excluded    INTEGER NOT NULL DEFAULT 0,        -- 管理者が集計から除外
    excluded_by       TEXT,
    note              TEXT    NOT NULL DEFAULT '',
    received_at       TEXT    NOT NULL DEFAULT (datetime('now', 'localtime')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_passes_comp_bib_time ON passes (competition_id, bib, time_ms);
CREATE INDEX IF NOT EXISTS idx_passes_comp_device  ON passes (competition_id, device_uuid);

-- 操作ログ
CREATE TABLE IF NOT EXISTS audit_log (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    competition_id INTEGER,
    actor          TEXT    NOT NULL,
    action         TEXT    NOT NULL,
    detail         TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_audit_comp ON audit_log (competition_id, id);
