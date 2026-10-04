<?php
declare(strict_types=1);

/** データアクセス */
final class Repo
{
    public const STATUSES = ['preparing' => '準備中', 'active' => '開催中', 'finished' => '終了'];
    public const TEAM_STATUSES = ['', 'DNS', 'DNF', 'DQ'];

    /** @return array<string, mixed>|null */
    public static function competition(int $id): ?array
    {
        $row = Db::one('SELECT * FROM competitions WHERE id = ?', [$id]);
        return $row ? self::castCompetition($row) : null;
    }

    /** @return array<string, mixed> */
    public static function competitionOrFail(int $id): array
    {
        $c = self::competition($id);
        if (!$c) {
            throw new ApiError('大会が見つかりません', 404, 'not_found');
        }
        return $c;
    }

    /** @return array<int, array<string, mixed>> */
    public static function competitions(): array
    {
        $rows = Db::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM teams t WHERE t.competition_id = c.id) AS team_count,
                    (SELECT COUNT(*) FROM passes p WHERE p.competition_id = c.id AND p.run_no = c.run_no AND p.deleted = 0) AS pass_count
               FROM competitions c
              ORDER BY COALESCE(c.event_date, '') DESC, c.id DESC"
        );
        return array_map([self::class, 'castCompetition'], $rows);
    }

    /** @param array<string, mixed> $row */
    private static function castCompetition(array $row): array
    {
        foreach (['id', 'team_size', 'merge_window_ms', 'tolerance_ms', 'run_no', 'team_count', 'pass_count'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int) $row[$k];
            }
        }
        $row['start_ms'] = $row['start_ms'] === null ? null : (int) $row['start_ms'];
        return $row;
    }

    /** @param array<string, mixed> $in */
    public static function saveCompetition(array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new ApiError('試合名を入力してください');
        }
        $date = trim((string) ($in['event_date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiError('日程は YYYY-MM-DD 形式で入力してください');
        }
        $status = (string) ($in['status'] ?? 'preparing');
        if (!isset(self::STATUSES[$status])) {
            $status = 'preparing';
        }
        $adopt = in_array($in['adopt_method'] ?? '', ['median', 'earliest'], true) ? $in['adopt_method'] : 'median';
        $fields = [
            'name'            => $name,
            'event_date'      => $date === '' ? null : $date,
            'location'        => trim((string) ($in['location'] ?? '')),
            'team_size'       => max(1, min(10, (int) ($in['team_size'] ?? 3))),
            'status'          => $status,
            'device_code'     => trim((string) ($in['device_code'] ?? '')),
            'merge_window_ms' => max(1000, min(600000, (int) ($in['merge_window_ms'] ?? 10000))),
            'tolerance_ms'    => max(0, min(60000, (int) ($in['tolerance_ms'] ?? 1000))),
            'adopt_method'    => $adopt,
            'note'            => trim((string) ($in['note'] ?? '')),
        ];
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            self::competitionOrFail($id);
            $sets = implode(', ', array_map(fn ($k) => "$k = :$k", array_keys($fields)));
            Db::exec("UPDATE competitions SET $sets, updated_at = datetime('now','localtime') WHERE id = :id", $fields + ['id' => $id]);
            Util::audit($id, 'competition.update', $name);
            return $id;
        }
        $cols = implode(', ', array_keys($fields));
        $ph = implode(', ', array_map(fn ($k) => ":$k", array_keys($fields)));
        Db::exec("INSERT INTO competitions ($cols) VALUES ($ph)", $fields);
        $id = Db::lastId();
        Util::audit($id, 'competition.create', $name);
        return $id;
    }

    /**
     * チーム + 選手
     * @return array<int, array<string, mixed>>
     */
    public static function teams(int $competitionId): array
    {
        $teams = Db::all('SELECT * FROM teams WHERE competition_id = ? ORDER BY bib', [$competitionId]);
        $runners = Db::all(
            'SELECT r.* FROM runners r JOIN teams t ON t.id = r.team_id WHERE t.competition_id = ? ORDER BY r.team_id, r.leg',
            [$competitionId]
        );
        $byTeam = [];
        foreach ($runners as $r) {
            $r['id'] = (int) $r['id'];
            $r['team_id'] = (int) $r['team_id'];
            $r['leg'] = (int) $r['leg'];
            $byTeam[$r['team_id']][] = $r;
        }
        foreach ($teams as &$t) {
            $t['id'] = (int) $t['id'];
            $t['competition_id'] = (int) $t['competition_id'];
            $t['bib'] = (int) $t['bib'];
            $t['force_open'] = (int) $t['force_open'];
            $t['runners'] = $byTeam[$t['id']] ?? [];
        }
        return $teams;
    }

    /**
     * チームと選手を保存（選手は丸ごと置き換え）
     * @param array<string, mixed> $in
     */
    public static function saveTeam(int $competitionId, array $in): int
    {
        $bib = self::validBib($in['bib'] ?? null);
        $id = (int) ($in['id'] ?? 0);
        $status = (string) ($in['status'] ?? '');
        if (!in_array($status, self::TEAM_STATUSES, true)) {
            $status = '';
        }
        $dup = Db::value('SELECT id FROM teams WHERE competition_id = ? AND bib = ? AND id <> ?', [$competitionId, $bib, $id]);
        if ($dup) {
            throw new ApiError('ビブ ' . Util::bib($bib) . ' は既に登録されています');
        }
        $fields = [
            'bib'        => $bib,
            'name'       => trim((string) ($in['name'] ?? '')),
            'category'   => trim((string) ($in['category'] ?? '')),
            'force_open' => !empty($in['force_open']) ? 1 : 0,
            'status'     => $status,
            'note'       => trim((string) ($in['note'] ?? '')),
        ];
        $runners = [];
        $leg = 0;
        foreach ((array) ($in['runners'] ?? []) as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $leg++;
            $runners[] = [
                'leg'         => $leg,
                'name'        => $name,
                'kana'        => trim((string) ($r['kana'] ?? '')),
                'gender'      => trim((string) ($r['gender'] ?? '')),
                'age'         => trim((string) ($r['age'] ?? '')),
                'affiliation' => trim((string) ($r['affiliation'] ?? '')),
                'note'        => trim((string) ($r['note'] ?? '')),
            ];
        }

        return Db::transaction(function () use ($competitionId, $id, $fields, $runners) {
            if ($id > 0) {
                $exists = Db::value('SELECT id FROM teams WHERE id = ? AND competition_id = ?', [$id, $competitionId]);
                if (!$exists) {
                    throw new ApiError('チームが見つかりません', 404, 'not_found');
                }
                $sets = implode(', ', array_map(fn ($k) => "$k = :$k", array_keys($fields)));
                Db::exec("UPDATE teams SET $sets, updated_at = datetime('now','localtime') WHERE id = :id", $fields + ['id' => $id]);
            } else {
                Db::exec(
                    'INSERT INTO teams (competition_id, bib, name, category, force_open, status, note)
                     VALUES (:competition_id, :bib, :name, :category, :force_open, :status, :note)',
                    $fields + ['competition_id' => $competitionId]
                );
                $id = Db::lastId();
            }
            Db::exec('DELETE FROM runners WHERE team_id = ?', [$id]);
            foreach ($runners as $r) {
                Db::exec(
                    'INSERT INTO runners (team_id, leg, name, kana, gender, age, affiliation, note)
                     VALUES (:team_id, :leg, :name, :kana, :gender, :age, :affiliation, :note)',
                    $r + ['team_id' => $id]
                );
            }
            Util::audit($competitionId, 'team.save', Util::bib($fields['bib']) . ' ' . $fields['name']);
            return $id;
        });
    }

    /** @param mixed $v */
    public static function validBib($v): int
    {
        $s = trim(mb_convert_kana((string) $v, 'n'));
        if (!preg_match('/^\d{1,2}$/', $s)) {
            throw new ApiError('ビブ番号は 00〜99 の数字で入力してください');
        }
        return (int) $s;
    }

    /**
     * 現在の計測回の通過記録
     * @return array<int, array<string, mixed>>
     */
    public static function passes(int $competitionId): array
    {
        $rows = Db::all(
            'SELECT p.*, d.name AS device_name
               FROM passes p
               JOIN competitions c ON c.id = p.competition_id AND c.run_no = p.run_no
               LEFT JOIN devices d ON d.competition_id = p.competition_id AND d.device_uuid = p.device_uuid
              WHERE p.competition_id = ?
              ORDER BY p.time_ms DESC, p.id DESC',
            [$competitionId]
        );
        foreach ($rows as &$r) {
            foreach (['id', 'competition_id', 'bib', 'time_ms', 'deleted', 'admin_excluded'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            foreach (['client_ms', 'offset_ms', 'deleted_ms'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            if ($r['source'] === 'admin') {
                $r['device_name'] = '管理者';
            }
        }
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public static function devices(int $competitionId): array
    {
        $rows = Db::all(
            "SELECT d.*,
                    (SELECT COUNT(*) FROM passes p WHERE p.competition_id = d.competition_id AND p.run_no = c.run_no AND p.device_uuid = d.device_uuid AND p.deleted = 0) AS pass_count,
                    (SELECT COUNT(*) FROM passes p WHERE p.competition_id = d.competition_id AND p.run_no = c.run_no AND p.device_uuid = d.device_uuid AND p.deleted = 1) AS deleted_count
               FROM devices d JOIN competitions c ON c.id = d.competition_id
              WHERE d.competition_id = ? ORDER BY d.first_seen_at, d.id",
            [$competitionId]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['pass_count'] = (int) $r['pass_count'];
            $r['deleted_count'] = (int) $r['deleted_count'];
            $r['clock_offset_ms'] = $r['clock_offset_ms'] === null ? null : (int) $r['clock_offset_ms'];
            $r['clock_rtt_ms'] = $r['clock_rtt_ms'] === null ? null : (int) $r['clock_rtt_ms'];
        }
        return $rows;
    }

    /** @param array<string, mixed> $d */
    public static function touchDevice(int $competitionId, array $d): void
    {
        $uuid = (string) ($d['uuid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uuid)) {
            throw new ApiError('端末IDが不正です');
        }
        Db::exec(
            "INSERT INTO devices (competition_id, device_uuid, name, user_agent, clock_offset_ms, clock_rtt_ms)
             VALUES (:c, :u, :n, :ua, :o, :r)
             ON CONFLICT (competition_id, device_uuid) DO UPDATE SET
                 name = excluded.name,
                 user_agent = excluded.user_agent,
                 clock_offset_ms = COALESCE(excluded.clock_offset_ms, devices.clock_offset_ms),
                 clock_rtt_ms = COALESCE(excluded.clock_rtt_ms, devices.clock_rtt_ms),
                 last_seen_at = datetime('now','localtime')",
            [
                'c'  => $competitionId,
                'u'  => $uuid,
                'n'  => mb_substr(trim((string) ($d['name'] ?? '')), 0, 40),
                'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                'o'  => isset($d['offset_ms']) && is_numeric($d['offset_ms']) ? (int) $d['offset_ms'] : null,
                'r'  => isset($d['rtt_ms']) && is_numeric($d['rtt_ms']) ? (int) $d['rtt_ms'] : null,
            ]
        );
    }
}
