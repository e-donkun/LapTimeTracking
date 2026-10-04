<?php
declare(strict_types=1);

/**
 * JSON API
 *   api/index.php?r=<route>
 *
 * 計測端末用（ログイン不要。大会に計測パスコードが設定されていれば X-Device-Code ヘッダが必要）
 *   GET  time            サーバ時刻（端末の時刻合わせ用）
 *   GET  competitions    大会一覧
 *   GET  competition     大会情報 + 選手名簿 (id)
 *   POST start           レーススタート（未記録ならサーバ時刻で記録）
 *   POST sync            通過記録の送信（作成・削除・復元を冪等に反映）
 *   GET  results         集計結果 (id)
 *
 * 管理用（ログイン + CSRF トークン必須）
 *   admin.* （下の $adminRoutes を参照）
 */
final class Api
{
    public static function handle(string $route): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            $data = self::dispatch($route);
            echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
        } catch (ApiError $e) {
            http_response_code($e->getCode() ?: 400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log((string) $e);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'サーバエラーが発生しました', 'code' => 'server_error'], JSON_UNESCAPED_UNICODE);
        }
    }

    /** @return array<string, mixed> */
    private static function dispatch(string $route): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $deviceRoutes = [
            'time'         => ['GET', 'time'],
            'competitions' => ['GET', 'competitions'],
            'competition'  => ['GET', 'competition'],
            'start'        => ['POST', 'start'],
            'sync'         => ['POST', 'sync'],
            'results'      => ['GET', 'results'],
        ];
        $adminRoutes = [
            'admin.competitions'       => ['GET', 'adminCompetitions'],
            'admin.competition.save'   => ['POST', 'adminCompetitionSave'],
            'admin.competition.delete' => ['POST', 'adminCompetitionDelete'],
            'admin.sample.create'      => ['POST', 'adminSampleCreate'],
            'admin.competition'        => ['GET', 'adminCompetition'],
            'admin.team.save'          => ['POST', 'adminTeamSave'],
            'admin.team.delete'        => ['POST', 'adminTeamDelete'],
            'admin.roster.preview'     => ['POST', 'adminRosterPreview'],
            'admin.roster.import'      => ['POST', 'adminRosterImport'],
            'admin.start.set'          => ['POST', 'adminStartSet'],
            'admin.passes'             => ['GET', 'adminPasses'],
            'admin.pass.exclude'       => ['POST', 'adminPassExclude'],
            'admin.pass.add'           => ['POST', 'adminPassAdd'],
            'admin.pass.delete'        => ['POST', 'adminPassDelete'],
            'admin.device.rename'      => ['POST', 'adminDeviceRename'],
        ];

        if (isset($deviceRoutes[$route])) {
            [$m, $fn] = $deviceRoutes[$route];
        } elseif (isset($adminRoutes[$route])) {
            [$m, $fn] = $adminRoutes[$route];
            if (!Auth::user()) {
                throw new ApiError('ログインが必要です', 401, 'login_required');
            }
            if ($m === 'POST' && !Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                throw new ApiError('セッションが無効です。再読み込みしてください', 403, 'csrf');
            }
        } else {
            throw new ApiError('不明な API です', 404, 'not_found');
        }
        if ($method !== $m) {
            throw new ApiError('メソッドが不正です', 405, 'method');
        }
        $in = $m === 'POST' ? self::body() : $_GET;
        return self::$fn($in);
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiError('JSON が不正です');
        }
        return $data;
    }

    /** 計測端末としてのアクセス権確認（管理者ログイン中なら常に可） */
    private static function deviceCompetition(array $in): array
    {
        $comp = Repo::competitionOrFail((int) ($in['id'] ?? $in['competition_id'] ?? 0));
        if ($comp['device_code'] !== '' && !Auth::user()) {
            $code = (string) ($_SERVER['HTTP_X_DEVICE_CODE'] ?? '');
            if (!hash_equals($comp['device_code'], $code)) {
                throw new ApiError('計測パスコードが違います', 401, 'device_code');
            }
        }
        return $comp;
    }

    private static function int(array $in, string $key): int
    {
        return (int) ($in[$key] ?? 0);
    }

    // ---------------------------------------------------------------- 計測端末

    private static function time(array $in): array
    {
        return ['now_ms' => Util::nowMs()];
    }

    private static function competitions(array $in): array
    {
        $list = array_map(fn ($c) => [
            'id'         => $c['id'],
            'name'       => $c['name'],
            'event_date' => $c['event_date'],
            'location'   => $c['location'],
            'status'     => $c['status'],
            'start_ms'   => $c['start_ms'],
            'team_count' => $c['team_count'],
            'needs_code' => $c['device_code'] !== '',
        ], Repo::competitions());
        return ['competitions' => $list, 'server_ms' => Util::nowMs()];
    }

    private static function competition(array $in): array
    {
        $comp = self::deviceCompetition($in);
        $teams = array_map(fn ($t) => [
            'bib'      => $t['bib'],
            'name'     => $t['name'],
            'category' => $t['category'],
            'is_open'  => $t['force_open'] === 1 || count($t['runners']) < $comp['team_size'],
            'status'   => $t['status'],
            'runners'  => array_map(fn ($r) => ['leg' => $r['leg'], 'name' => $r['name'], 'kana' => $r['kana']], $t['runners']),
        ], Repo::teams($comp['id']));
        return [
            'competition' => self::publicCompetition($comp),
            'teams'       => $teams,
            'race'        => Results::race($comp['id']),
            'server_ms'   => Util::nowMs(),
        ];
    }

    private static function publicCompetition(array $c): array
    {
        return [
            'id'              => $c['id'],
            'name'            => $c['name'],
            'event_date'      => $c['event_date'],
            'location'        => $c['location'],
            'team_size'       => $c['team_size'],
            'status'          => $c['status'],
            'start_ms'        => $c['start_ms'],
            'merge_window_ms' => $c['merge_window_ms'],
            'run_no'          => $c['run_no'],
        ];
    }

    /**
     * スタートの記録。最初に押した端末のサーバ到着時刻をスタートとする（以降の押下は既存値を返すだけ）。
     * オフライン中に押された場合は端末が推定したサーバ時刻 (estimated_ms) を後から送ってくる。
     */
    private static function start(array $in): array
    {
        $comp = self::deviceCompetition($in);
        $device = (array) ($in['device'] ?? []);
        Repo::touchDevice($comp['id'], $device);
        $state = fn () => [
            'start_ms'    => Repo::competitionOrFail($comp['id'])['start_ms'],
            'competition' => self::publicCompetition(Repo::competitionOrFail($comp['id'])),
            'server_ms'   => Util::nowMs(),
        ];
        // リセット前の計測回で押されたスタート（オフライン送信の遅延など）は採用しない
        if (isset($in['run_no']) && (int) $in['run_no'] !== $comp['run_no']) {
            return ['created' => false, 'stale_run' => true] + $state();
        }
        $now = Util::nowMs();
        $startMs = $now;
        $by = '端末:' . mb_substr((string) ($device['name'] ?? ''), 0, 40);
        if (isset($in['estimated_ms']) && is_numeric($in['estimated_ms'])) {
            $est = (int) $in['estimated_ms'];
            if ($now - $est > 86400000) {
                throw new ApiError('推定スタート時刻が古すぎるため採用できません（管理画面で設定してください）', 400, 'start_too_old');
            }
            $startMs = min($est, $now); // 端末の時刻差の誤差で未来になった場合は現在時刻
            $by .= '（オフライン時の推定）';
        }
        $updated = Db::exec(
            "UPDATE competitions SET start_ms = ?, start_set_by = ?, start_set_at = datetime('now','localtime'),
                    status = CASE WHEN status = 'preparing' THEN 'active' ELSE status END,
                    updated_at = datetime('now','localtime')
              WHERE id = ? AND start_ms IS NULL",
            [$startMs, $by, $comp['id']]
        );
        if ($updated) {
            Util::audit($comp['id'], 'start', $by . ' ' . Util::clock($startMs));
        }
        return ['created' => $updated > 0] + $state();
    }

    /**
     * 通過記録の同期。端末は未送信分（新規・削除・復元）をまとめて送る。
     * uuid で冪等に upsert し、削除フラグは client_updated_ms が新しい方を採用する。
     */
    private static function sync(array $in): array
    {
        $comp = self::deviceCompetition($in);
        $device = (array) ($in['device'] ?? []);
        Repo::touchDevice($comp['id'], $device);
        $deviceUuid = (string) $device['uuid'];
        $now = Util::nowMs();

        $accepted = [];
        $rejected = [];
        $passes = (array) ($in['passes'] ?? []);
        if (count($passes) > 2000) {
            throw new ApiError('一度に送信できる件数を超えています');
        }
        Db::transaction(function () use ($passes, $comp, $deviceUuid, $now, &$accepted, &$rejected) {
            $st = Db::pdo()->prepare(
                "INSERT INTO passes (uuid, competition_id, run_no, device_uuid, source, bib, time_ms, client_ms, offset_ms, deleted, deleted_ms, client_updated_ms)
                 VALUES (:uuid, :c, :run, :d, 'device', :bib, :t, :cm, :om, :del, :dms, :upd)
                 ON CONFLICT (uuid) DO UPDATE SET
                     deleted = excluded.deleted,
                     deleted_ms = excluded.deleted_ms,
                     client_updated_ms = excluded.client_updated_ms,
                     updated_at = datetime('now','localtime')
                 WHERE passes.device_uuid = excluded.device_uuid
                   AND passes.competition_id = excluded.competition_id
                   AND excluded.client_updated_ms > passes.client_updated_ms"
            );
            foreach ($passes as $p) {
                $uuid = (string) ($p['uuid'] ?? '');
                $bib = $p['bib'] ?? null;
                $t = $p['time_ms'] ?? null;
                if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uuid) || !is_int($bib) || $bib < 0 || $bib > 99
                    || !is_numeric($t) || abs($now - (int) $t) > 7 * 86400000) {
                    $rejected[] = $uuid;
                    continue;
                }
                $st->execute([
                    'uuid' => $uuid,
                    'c'    => $comp['id'],
                    'run'  => isset($p['run_no']) && (int) $p['run_no'] >= 1 ? (int) $p['run_no'] : $comp['run_no'],
                    'd'    => $deviceUuid,
                    'bib'  => $bib,
                    't'    => (int) $t,
                    'cm'   => isset($p['client_ms']) ? (int) $p['client_ms'] : null,
                    'om'   => isset($p['offset_ms']) ? (int) $p['offset_ms'] : null,
                    'del'  => !empty($p['deleted']) ? 1 : 0,
                    'dms'  => isset($p['deleted_ms']) ? (int) $p['deleted_ms'] : null,
                    'upd'  => (int) ($p['updated_ms'] ?? 0),
                ]);
                $accepted[] = ['uuid' => $uuid, 'updated_ms' => (int) ($p['updated_ms'] ?? 0)];
            }
        });

        $fresh = Repo::competitionOrFail($comp['id']);
        return [
            'accepted'    => $accepted,
            'rejected'    => $rejected,
            'competition' => self::publicCompetition($fresh),
            'race'        => Results::race($comp['id']),
            'server_ms'   => Util::nowMs(),
        ];
    }

    private static function results(array $in): array
    {
        $comp = self::deviceCompetition($in);
        return ['results' => Results::compute($comp['id'])];
    }

    // ---------------------------------------------------------------- 管理

    private static function adminCompetitions(array $in): array
    {
        return ['competitions' => Repo::competitions()];
    }

    private static function adminCompetition(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'id'));
        return [
            'competition' => $comp,
            'teams'       => Repo::teams($comp['id']),
            'devices'     => Repo::devices($comp['id']),
            'race'        => Results::race($comp['id']),
            'server_ms'   => Util::nowMs(),
        ];
    }

    private static function adminCompetitionSave(array $in): array
    {
        $id = Repo::saveCompetition($in);
        return ['id' => $id, 'competition' => Repo::competition($id)];
    }

    private static function adminCompetitionDelete(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'id'));
        if (($in['confirm'] ?? '') !== $comp['name']) {
            throw new ApiError('確認のため大会名を正しく入力してください');
        }
        Db::exec('DELETE FROM competitions WHERE id = ?', [$comp['id']]);
        Util::audit($comp['id'], 'competition.delete', $comp['name']);
        return [];
    }

    private static function adminSampleCreate(array $in): array
    {
        return ['id' => Sample::create()];
    }

    private static function adminTeamSave(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'competition_id'));
        $id = Repo::saveTeam($comp['id'], (array) ($in['team'] ?? []));
        return ['id' => $id];
    }

    private static function adminTeamDelete(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'competition_id'));
        $team = Db::one('SELECT * FROM teams WHERE id = ? AND competition_id = ?', [self::int($in, 'id'), $comp['id']]);
        if (!$team) {
            throw new ApiError('チームが見つかりません', 404, 'not_found');
        }
        Db::exec('DELETE FROM teams WHERE id = ?', [$team['id']]);
        Util::audit($comp['id'], 'team.delete', Util::bib((int) $team['bib']) . ' ' . $team['name']);
        return [];
    }

    private static function adminRosterPreview(array $in): array
    {
        Repo::competitionOrFail(self::int($in, 'competition_id'));
        return Roster::parse((string) ($in['text'] ?? ''));
    }

    private static function adminRosterImport(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'competition_id'));
        $parsed = Roster::parse((string) ($in['text'] ?? ''));
        $mode = ($in['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
        return Roster::import($comp['id'], $parsed['teams'], $mode) + ['warnings' => $parsed['warnings']];
    }

    /** スタート時刻の設定: mode = now / clock / reset（計測のやり直し） */
    private static function adminStartSet(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'id'));
        $mode = (string) ($in['mode'] ?? '');
        $user = Auth::user();
        $by = '管理者:' . ($user['username'] ?? '');
        switch ($mode) {
            case 'now':
                $ms = Util::nowMs();
                break;
            case 'clock':
                $ms = Util::parseClock((string) ($in['value'] ?? ''), $comp['event_date']);
                if ($ms === null) {
                    throw new ApiError('時刻は HH:MM:SS.s の形式で入力してください');
                }
                break;
            case 'reset':
                // スタートを未記録に戻し、計測回を進める。これまでの通過記録は削除せず、集計対象から外れる。
                Db::exec(
                    "UPDATE competitions SET start_ms = NULL, start_set_by = NULL, start_set_at = NULL, run_no = run_no + 1,
                            status = 'preparing', updated_at = datetime('now','localtime') WHERE id = ?",
                    [$comp['id']]
                );
                Util::audit($comp['id'], 'run.reset', ($comp['run_no'] + 1) . '回目へ (旧スタート ' . Util::clock($comp['start_ms']) . ')');
                return ['start_ms' => null, 'run_no' => $comp['run_no'] + 1];
            default:
                throw new ApiError('不正な指定です');
        }
        Db::exec(
            "UPDATE competitions SET start_ms = ?, start_set_by = ?, start_set_at = datetime('now','localtime'),
                    status = CASE WHEN ? IS NOT NULL AND status = 'preparing' THEN 'active' ELSE status END,
                    updated_at = datetime('now','localtime') WHERE id = ?",
            [$ms, $by, $ms, $comp['id']]
        );
        Util::audit($comp['id'], 'start.set', $mode . ' ' . Util::clock($ms) . ' (旧 ' . Util::clock($comp['start_ms']) . ')');
        return ['start_ms' => $ms];
    }

    private static function adminPasses(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'id'));
        return [
            'passes'    => Repo::passes($comp['id']),
            'devices'   => Repo::devices($comp['id']),
            'start_ms'  => $comp['start_ms'],
            'server_ms' => Util::nowMs(),
        ];
    }

    private static function adminPassExclude(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'competition_id'));
        $ids = array_map('intval', (array) ($in['ids'] ?? [$in['id'] ?? 0]));
        $excluded = !empty($in['excluded']) ? 1 : 0;
        $user = Auth::user();
        $n = 0;
        foreach ($ids as $id) {
            $n += Db::exec(
                "UPDATE passes SET admin_excluded = ?, excluded_by = ?, updated_at = datetime('now','localtime')
                  WHERE id = ? AND competition_id = ?",
                [$excluded, $excluded ? ($user['username'] ?? '') : null, $id, $comp['id']]
            );
        }
        Util::audit($comp['id'], $excluded ? 'pass.exclude' : 'pass.restore', implode(',', $ids));
        return ['updated' => $n];
    }

    /** 管理者による通過時刻の手動入力（同じ通過に端末記録があっても管理者入力が優先される） */
    private static function adminPassAdd(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'competition_id'));
        $bib = Repo::validBib($in['bib'] ?? null);
        $value = trim((string) ($in['value'] ?? ''));
        if (($in['kind'] ?? 'clock') === 'elapsed') {
            if ($comp['start_ms'] === null) {
                throw new ApiError('スタート時刻が未設定のため経過時間では入力できません');
            }
            $d = Util::parseDuration($value);
            if ($d === null) {
                throw new ApiError('経過時間は M:SS.s または H:MM:SS.s の形式で入力してください');
            }
            $ms = $comp['start_ms'] + $d;
        } else {
            $ms = Util::parseClock($value, $comp['start_ms'] !== null ? date('Y-m-d', intdiv($comp['start_ms'], 1000)) : $comp['event_date']);
            if ($ms === null) {
                throw new ApiError('時刻は HH:MM:SS.s の形式で入力してください');
            }
        }
        $uuid = Util::uuid();
        Db::exec(
            "INSERT INTO passes (uuid, competition_id, run_no, device_uuid, source, bib, time_ms, note, client_updated_ms)
             VALUES (?, ?, ?, 'admin', 'admin', ?, ?, ?, ?)",
            [$uuid, $comp['id'], $comp['run_no'], $bib, $ms, trim((string) ($in['note'] ?? '')), Util::nowMs()]
        );
        Util::audit($comp['id'], 'pass.add', Util::bib($bib) . ' ' . Util::clock($ms));
        return ['uuid' => $uuid, 'time_ms' => $ms];
    }

    /** 管理者入力の通過記録を削除（端末記録は除外で対応する） */
    private static function adminPassDelete(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'competition_id'));
        $n = Db::exec(
            "UPDATE passes SET deleted = 1, deleted_ms = ?, updated_at = datetime('now','localtime')
              WHERE id = ? AND competition_id = ? AND source = 'admin'",
            [Util::nowMs(), self::int($in, 'id'), $comp['id']]
        );
        if (!$n) {
            throw new ApiError('削除できるのは管理者入力の記録のみです');
        }
        Util::audit($comp['id'], 'pass.delete', (string) self::int($in, 'id'));
        return [];
    }

    private static function adminDeviceRename(array $in): array
    {
        $comp = Repo::competitionOrFail(self::int($in, 'competition_id'));
        Db::exec(
            'UPDATE devices SET name = ? WHERE id = ? AND competition_id = ?',
            [mb_substr(trim((string) ($in['name'] ?? '')), 0, 40), self::int($in, 'id'), $comp['id']]
        );
        return [];
    }
}
