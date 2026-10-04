<?php
declare(strict_types=1);

/**
 * 集計ロジック
 *
 * 1. 有効な通過記録（端末で削除されておらず、管理者が除外していないもの）をビブごとに時刻順に並べる。
 * 2. 同じ端末の記録は 1 件ずつ別の「通過 (crossing)」とし（同じビブを入力するたびに次の走者）、
 *    別の端末の記録は merge_window_ms 以内なら同じ通過に突き合わせる。1 台だけの記録でも有効。
 * 3. 通過時刻は 管理者入力 > 採用方法（中央値 / 最速）の順で決める。
 *    端末間の差が tolerance_ms を超える・直前の通過と間隔が短い は要確認、1 台のみの記録は参考として表示する。
 * 4. n 回目の通過 = n 走のゴール。ラップ = 前の通過（1走はスタート）からの差。
 *    登録人数分の通過が揃えば完走、最終通過 - スタート = チーム記録。
 * 5. 登録人数が team_size 未満（またはオープン指定）のチームはオープン参加として順位を付けない。
 */
final class Results
{
    public const FLAG_LABELS = [
        'spread'   => '端末間の差',
        'short'    => '直前の通過と間隔が短い（二重入力?）',
        'missing'  => '1台のみの記録',
        'manual'   => '管理者入力',
        'extra'    => '走者数を超える通過',
        'unknown'  => '未登録ビブ',
        'prestart' => 'スタート前の記録',
    ];

    /** 「要確認」として数えるフラグ（それ以外は参考表示。いずれも記録は有効） */
    public const WARN_FLAGS = ['spread', 'short', 'extra', 'unknown', 'prestart'];

    /** @return array<string, mixed> */
    public static function compute(int $competitionId): array
    {
        $comp = Repo::competitionOrFail($competitionId);
        $teams = Repo::teams($competitionId);
        $start = $comp['start_ms'];
        $window = $comp['merge_window_ms'];

        $deviceRows = Repo::devices($competitionId);
        $deviceNames = [];
        foreach ($deviceRows as $d) {
            $deviceNames[$d['device_uuid']] = $d['name'] !== '' ? $d['name'] : substr($d['device_uuid'], 0, 6);
        }
        $deviceNames['admin'] = '管理者';

        $passes = Db::all(
            'SELECT id, uuid, device_uuid, source, bib, time_ms FROM passes
              WHERE competition_id = ? AND run_no = ? AND deleted = 0 AND admin_excluded = 0
              ORDER BY bib, time_ms, id',
            [$competitionId, $comp['run_no']]
        );

        // 「稼働中の端末」= スタート後に 1 件以上記録した端末。未記録判定に使う。
        $activeDevices = [];
        $byBib = [];
        $prestart = [];
        foreach ($passes as $p) {
            $p['bib'] = (int) $p['bib'];
            $p['time_ms'] = (int) $p['time_ms'];
            $p['id'] = (int) $p['id'];
            if ($start !== null && $p['time_ms'] < $start) {
                $prestart[] = $p + ['device_name' => $deviceNames[$p['device_uuid']] ?? '?'];
                continue;
            }
            if ($p['source'] === 'device') {
                $activeDevices[$p['device_uuid']] = true;
            }
            $byBib[$p['bib']][] = $p;
        }
        $activeDevices = array_keys($activeDevices);

        $crossingsByBib = [];
        foreach ($byBib as $bib => $list) {
            $crossingsByBib[$bib] = self::cluster($list, $comp, $activeDevices);
        }

        $out = [];
        $teamBibs = [];
        foreach ($teams as $t) {
            $teamBibs[$t['bib']] = true;
            $out[] = self::teamResult($t, $crossingsByBib[$t['bib']] ?? [], $comp);
        }

        // 未登録ビブ
        $unknown = [];
        foreach ($crossingsByBib as $bib => $crossings) {
            if (!isset($teamBibs[$bib])) {
                $unknown[] = ['bib' => $bib, 'crossings' => self::withElapsed($crossings, $start)];
            }
        }

        self::rank($out, $comp);
        usort($out, [self::class, 'displayOrder']);

        $flagged = 0;
        $single = 0;
        $finished = 0;
        foreach ($out as $t) {
            $single += $t['single_count'];
            if ($t['flag_count'] > 0) {
                $flagged++;
            }
            if ($t['state'] === 'finished') {
                $finished++;
            }
        }

        return [
            'competition' => [
                'id'           => $comp['id'],
                'name'         => $comp['name'],
                'event_date'   => $comp['event_date'],
                'location'     => $comp['location'],
                'team_size'    => $comp['team_size'],
                'status'       => $comp['status'],
                'start_ms'     => $start,
                'tolerance_ms' => $comp['tolerance_ms'],
                'adopt_method' => $comp['adopt_method'],
                'run_no'       => $comp['run_no'],
            ],
            'server_ms'      => Util::nowMs(),
            'devices'        => array_map(fn ($d) => [
                'uuid'         => $d['device_uuid'],
                'name'         => $deviceNames[$d['device_uuid']],
                'pass_count'   => $d['pass_count'],
                'last_seen_at' => $d['last_seen_at'],
                'active'       => in_array($d['device_uuid'], $activeDevices, true),
            ], $deviceRows),
            'device_names'   => $deviceNames,
            'teams'          => $out,
            'unknown_bibs'   => $unknown,
            'prestart'       => $prestart,
            'summary'        => [
                'teams'    => count($out),
                'finished' => $finished,
                'flagged'  => $flagged,
                'single'   => $single,
                'unknown'  => count($unknown),
            ],
            'race'           => self::raceState($out, $start),
            'flag_labels'    => self::FLAG_LABELS,
            'warn_flags'     => self::WARN_FLAGS,
        ];
    }

    /** レース全体の状態のみ（計測端末の同期・管理画面ヘッダ用） */
    public static function race(int $competitionId): array
    {
        return self::compute($competitionId)['race'];
    }

    /**
     * 全走者の記録が揃ったか（= タイマー停止）。DNS/DNF/DQ のチームは対象外。
     * 揃っていれば最後にゴールした時刻を finish_ms とする。
     * @param array<int, array<string, mixed>> $teams
     * @return array<string, mixed>
     */
    private static function raceState(array $teams, ?int $start): array
    {
        $active = array_filter($teams, fn ($t) => $t['status'] === '');
        $done = array_filter($active, fn ($t) => $t['state'] === 'finished');
        $finished = $start !== null && count($active) > 0 && count($done) === count($active);
        $finishMs = $finished ? max(array_map(fn ($t) => $t['last_ms'], $done)) : null;
        return [
            'finished'       => $finished,
            'finish_ms'      => $finishMs,
            'elapsed_ms'     => $finishMs !== null ? $finishMs - $start : null,
            'teams'          => count($active),
            'teams_finished' => count($done),
        ];
    }

    /**
     * 同一ビブの記録を「通過」にまとめる
     *
     * - 同じ端末の記録は同じ通過にまとめない（その端末で n 回目に入力した記録 = n 回目の通過）。
     *   短い間隔で続けて入力しても、それぞれ次の走者の通過として数える。
     * - 別の端末の記録は、merge_window_ms 以内で、まだその端末の記録を含まない最も早い通過に加える
     *   （複数台で同じ走者を記録していれば 1 つの通過に揃う）。
     * - 1 台だけの記録でも有効。複数台あれば中央値（または最速）で精度を上げる。
     *
     * @param array<int, array<string, mixed>> $list 時刻昇順
     * @param array<string, mixed> $comp
     * @param array<int, string> $activeDevices
     * @return array<int, array<string, mixed>>
     */
    private static function cluster(array $list, array $comp, array $activeDevices): array
    {
        $window = $comp['merge_window_ms'];
        $groups = [];
        foreach ($list as $p) {
            $key = $p['source'] === 'admin' ? 'admin' : $p['device_uuid'];
            $target = null;
            foreach ($groups as $i => $g) {
                if ($p['time_ms'] - $g['first'] <= $window && !isset($g['items'][$key])) {
                    $target = $i;
                    break;
                }
            }
            if ($target === null) {
                $groups[] = ['first' => $p['time_ms'], 'items' => [$key => $p]];
            } else {
                $groups[$target]['items'][$key] = $p;
            }
        }

        $crossings = [];
        $prevTime = null;
        foreach ($groups as $g) {
            $admin = $g['items']['admin'] ?? null;
            $byDevice = [];
            $passIds = [];
            foreach ($g['items'] as $key => $p) {
                $passIds[] = $p['id'];
                if ($key !== 'admin') {
                    $byDevice[$key] = $p['time_ms'];
                }
            }
            $flags = [];
            $times = array_values($byDevice);
            sort($times);
            $spread = count($times) >= 2 ? end($times) - $times[0] : 0;

            if ($admin !== null) {
                $time = $admin['time_ms'];
                $method = 'admin';
                $flags['manual'] = true;
            } else {
                $method = $comp['adopt_method'];
                $time = $method === 'earliest' ? $times[0] : self::median($times);
                if ($spread > $comp['tolerance_ms']) {
                    $flags['spread'] = true;
                }
            }
            // 直前の通過から間隔が短い → 二重入力の可能性（記録は有効のまま、確認用）
            if ($prevTime !== null && $time - $prevTime <= $window) {
                $flags['short'] = true;
            }
            $missing = array_values(array_diff($activeDevices, array_keys($byDevice)));
            if ($admin === null && $missing && count($activeDevices) > 1) {
                $flags['missing'] = true; // 1 台のみの記録（有効。参考表示）
            }
            $prevTime = $time;

            $crossings[] = [
                'time_ms'   => $time,
                'method'    => $method,
                'devices'   => $byDevice,              // 端末UUID => 時刻
                'admin_ms'  => $admin['time_ms'] ?? null,
                'spread_ms' => $spread,
                'missing'   => $admin === null ? $missing : [],
                'flags'     => array_keys($flags),
                'pass_ids'  => $passIds,
            ];
        }
        return $crossings;
    }

    /** @param array<int, int> $sorted */
    private static function median(array $sorted): int
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $sorted[$mid] : intdiv($sorted[$mid - 1] + $sorted[$mid], 2);
    }

    /**
     * @param array<int, array<string, mixed>> $crossings
     * @return array<int, array<string, mixed>>
     */
    private static function withElapsed(array $crossings, ?int $start): array
    {
        foreach ($crossings as &$c) {
            $c['elapsed_ms'] = $start === null ? null : $c['time_ms'] - $start;
        }
        return $crossings;
    }

    /**
     * @param array<string, mixed> $t
     * @param array<int, array<string, mixed>> $crossings
     * @param array<string, mixed> $comp
     * @return array<string, mixed>
     */
    private static function teamResult(array $t, array $crossings, array $comp): array
    {
        $start = $comp['start_ms'];
        $runners = $t['runners'];
        $legCount = count($runners) > 0 ? count($runners) : $comp['team_size'];
        $isOpen = $t['force_open'] === 1 || count($runners) < $comp['team_size'];

        $legs = [];
        $prev = $start;
        $flagCount = 0;   // 要確認（警告フラグ）の数
        $singleCount = 0; // 1 台のみで記録された通過の数（参考）
        for ($i = 0; $i < $legCount; $i++) {
            $c = $crossings[$i] ?? null;
            $leg = [
                'leg'        => $i + 1,
                'runner'     => $runners[$i] ?? null,
                'crossing'   => null,
                'split_ms'   => null,
                'elapsed_ms' => null,
                'split_rank' => null,
            ];
            if ($c !== null) {
                $leg['crossing'] = $c;
                $leg['elapsed_ms'] = $start === null ? null : $c['time_ms'] - $start;
                $leg['split_ms'] = $prev === null ? null : $c['time_ms'] - $prev;
                $prev = $c['time_ms'];
                $flagCount += count(array_intersect($c['flags'], self::WARN_FLAGS));
                if (in_array('missing', $c['flags'], true)) {
                    $singleCount++;
                }
            }
            $legs[] = $leg;
        }
        $extra = array_slice($crossings, $legCount);
        if ($extra) {
            $flagCount++;
        }

        $done = min(count($crossings), $legCount);
        $finished = $done >= $legCount;
        if ($t['status'] !== '') {
            $state = strtolower($t['status']); // dns / dnf / dq
        } elseif ($finished) {
            $state = 'finished';
        } else {
            $state = $done > 0 ? 'running' : 'waiting';
        }
        $total = ($finished && $start !== null) ? $legs[$legCount - 1]['elapsed_ms'] : null;

        return [
            'id'            => $t['id'],
            'bib'           => $t['bib'],
            'bib_label'     => Util::bib($t['bib']),
            'name'          => $t['name'],
            'category'      => $t['category'],
            'note'          => $t['note'],
            'status'        => $t['status'],
            'is_open'       => $isOpen,
            'state'         => $state,
            'legs_done'     => $done,
            'leg_count'     => $legCount,
            'legs'          => $legs,
            'extra'         => self::withElapsed($extra, $start),
            'total_ms'      => $total,
            'rank'          => null,
            'category_rank' => null,
            'flag_count'    => $flagCount,
            'single_count'  => $singleCount,
            'last_ms'       => $done > 0 ? $legs[$done - 1]['crossing']['time_ms'] : null,
        ];
    }

    /**
     * 総合順位・区分順位・区間順位を付ける（オープン・DNS/DNF/DQ は対象外）
     * @param array<int, array<string, mixed>> $teams
     * @param array<string, mixed> $comp
     */
    private static function rank(array &$teams, array $comp): void
    {
        $ranked = array_keys(array_filter($teams, fn ($t) => !$t['is_open'] && $t['state'] === 'finished' && $t['total_ms'] !== null));
        $assign = function (array $idx, callable $value, string $key) use (&$teams) {
            usort($idx, fn ($a, $b) => [$value($teams[$a]), $teams[$a]['bib']] <=> [$value($teams[$b]), $teams[$b]['bib']]);
            $rank = 0;
            $last = null;
            foreach ($idx as $n => $i) {
                $v = $value($teams[$i]);
                if ($v !== $last) {
                    $rank = $n + 1;
                    $last = $v;
                }
                $teams[$i][$key] = $rank;
            }
        };
        $assign($ranked, fn ($t) => $t['total_ms'], 'rank');

        $byCat = [];
        foreach ($ranked as $i) {
            if ($teams[$i]['category'] !== '') {
                $byCat[$teams[$i]['category']][] = $i;
            }
        }
        foreach ($byCat as $idx) {
            $assign($idx, fn ($t) => $t['total_ms'], 'category_rank');
        }

        // 区間順位: 正式参加チームでラップが出ている走者
        for ($leg = 0; $leg < $comp['team_size']; $leg++) {
            $vals = [];
            foreach ($teams as $i => $t) {
                if ($t['is_open'] || in_array($t['state'], ['dq', 'dns'], true)) {
                    continue;
                }
                $split = $t['legs'][$leg]['split_ms'] ?? null;
                if ($split !== null) {
                    $vals[$i] = $split;
                }
            }
            asort($vals);
            $rank = 0;
            $n = 0;
            $last = null;
            foreach ($vals as $i => $v) {
                $n++;
                if ($v !== $last) {
                    $rank = $n;
                    $last = $v;
                }
                $teams[$i]['legs'][$leg]['split_rank'] = $rank;
            }
        }
    }

    /**
     * 表示順: 正式完走(順位順) → オープン完走(記録順) → 走行中(進んでいる順) → 未スタート → DNF/DNS/DQ
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function displayOrder(array $a, array $b): int
    {
        return self::sortKey($a) <=> self::sortKey($b);
    }

    /** @return array<int, int> */
    private static function sortKey(array $t): array
    {
        $big = PHP_INT_MAX;
        switch ($t['state']) {
            case 'finished':
                return $t['is_open'] ? [1, $t['total_ms'] ?? $big, $t['bib']] : [0, $t['rank'] ?? $big, $t['bib']];
            case 'running':
                return [2, -$t['legs_done'], $t['last_ms'] ?? $big, $t['bib']];
            case 'waiting':
                return [3, 0, 0, $t['bib']];
            default:
                return [4, 0, 0, $t['bib']];
        }
    }
}
