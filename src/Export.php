<?php
declare(strict_types=1);

/** Excel / CSV 出力 */
final class Export
{
    private const STATE_LABELS = [
        'finished' => '完走',
        'running'  => '走行中',
        'waiting'  => '未出走',
        'dns'      => 'DNS',
        'dnf'      => 'DNF',
        'dq'       => 'DQ',
    ];

    public static function stateLabel(string $state): string
    {
        return self::STATE_LABELS[$state] ?? $state;
    }

    public static function xlsx(int $competitionId): string
    {
        $res = Results::compute($competitionId);
        $comp = $res['competition'];
        $book = new Xlsx();

        $book->addSheet('成績', ...self::resultSheet($res));
        $book->addSheet('端末比較', ...self::compareSheet($res));
        $book->addSheet('通過記録', ...self::passSheet($competitionId, $comp['start_ms']));

        $path = tempnam(sys_get_temp_dir(), 'ltt') . '.xlsx';
        $book->save($path);
        return $path;
    }

    /** @return array{0: array, 1: array, 2: array, 3: string} */
    private static function resultSheet(array $res): array
    {
        $comp = $res['competition'];
        $labels = $res['flag_labels'];
        $rows = [];
        $rows[] = [['v' => $comp['name'], 's' => 'title']];
        $rows[] = [['v' => '日程: ' . ($comp['event_date'] ?? '') . '　場所: ' . ($comp['location'] ?? '')
            . '　スタート: ' . ($comp['start_ms'] !== null ? date('Y-m-d H:i:s', intdiv($comp['start_ms'], 1000)) : '未記録'), 's' => 'muted']];
        $rows[] = [['v' => '出力日時: ' . date('Y-m-d H:i:s') . '　※ ' . $comp['team_size'] . '名に満たないチームはオープン参加（順位なし）', 's' => 'muted']];
        $rows[] = [];

        $header = ['順位', '区分順位', 'ビブ', 'チーム名', '区分', '参加区分', '走順', '氏名', 'フリガナ', '性別', '学年・年齢', '所属', '備考',
            '所要時間', '区間順位', '通過記録(累計)', 'チーム記録', '状態', '確認事項'];
        $rows[] = array_map(fn ($h) => ['v' => $h, 's' => 'header'], $header);
        $headerRow = count($rows);
        $merges = [];

        foreach ($res['teams'] as $t) {
            $first = count($rows) + 1;
            $n = max(1, $t['leg_count']);
            $rankText = $t['is_open'] ? 'OP' : ($t['rank'] ?? '');
            $teamFlags = $t['extra'] ? $labels['extra'] . '(' . count($t['extra']) . '件)' : '';
            for ($i = 0; $i < $n; $i++) {
                $leg = $t['legs'][$i] ?? null;
                $r = $leg['runner'] ?? null;
                $c = $leg['crossing'] ?? null;
                $flags = $c ? array_map(fn ($f) => $labels[$f] ?? $f, $c['flags']) : [];
                if ($i === 0 && $teamFlags !== '') {
                    $flags[] = $teamFlags;
                }
                $rows[] = [
                    ['v' => $i === 0 ? $rankText : null, 's' => 'number'],
                    ['v' => $i === 0 ? ($t['is_open'] ? null : $t['category_rank']) : null, 's' => 'number'],
                    ['v' => $i === 0 ? $t['bib_label'] : null, 's' => 'center'],
                    ['v' => $i === 0 ? $t['name'] : null, 's' => 'text'],
                    ['v' => $i === 0 ? $t['category'] : null, 's' => 'text'],
                    ['v' => $i === 0 ? ($t['is_open'] ? 'オープン' : '正式') : null, 's' => 'center'],
                    ['v' => $i + 1, 's' => 'number'],
                    ['v' => $r['name'] ?? '', 's' => 'text'],
                    ['v' => $r['kana'] ?? '', 's' => 'text'],
                    ['v' => $r['gender'] ?? '', 's' => 'center'],
                    ['v' => $r['age'] ?? '', 's' => 'center'],
                    ['v' => $r['affiliation'] ?? '', 's' => 'text'],
                    ['v' => $r['note'] ?? '', 's' => 'text'],
                    ['v' => Xlsx::durationValue($leg['split_ms'] ?? null), 's' => 'duration'],
                    ['v' => $leg['split_rank'] ?? null, 's' => 'number'],
                    ['v' => Xlsx::durationValue($leg['elapsed_ms'] ?? null), 's' => 'duration'],
                    ['v' => $i === 0 ? Xlsx::durationValue($t['total_ms']) : null, 's' => 'total'],
                    ['v' => $i === 0 ? self::stateLabel($t['state']) : null, 's' => 'center'],
                    ['v' => implode(' / ', $flags), 's' => 'text'],
                ];
            }
            $last = count($rows);
            if ($last > $first) {
                foreach ([0, 1, 2, 3, 4, 5, 16, 17] as $col) {
                    $L = Xlsx::col($col);
                    $merges[] = "{$L}{$first}:{$L}{$last}";
                }
            }
        }

        // 未登録ビブ
        if ($res['unknown_bibs']) {
            $rows[] = [];
            $rows[] = [['v' => '未登録ビブの記録', 's' => 'title']];
            $rows[] = array_map(fn ($h) => ['v' => $h, 's' => 'header'], ['ビブ', '回目', '通過時刻', '経過']);
            foreach ($res['unknown_bibs'] as $u) {
                foreach ($u['crossings'] as $i => $c) {
                    $rows[] = [
                        ['v' => Util::bib($u['bib']), 's' => 'center'],
                        ['v' => $i + 1, 's' => 'number'],
                        ['v' => Xlsx::dateTimeValue($c['time_ms']), 's' => 'clock'],
                        ['v' => Xlsx::durationValue($c['elapsed_ms']), 's' => 'duration'],
                    ];
                }
            }
        }

        $widths = [6, 8, 6, 22, 10, 9, 6, 16, 18, 6, 10, 18, 14, 12, 8, 14, 13, 8, 30];
        return [$rows, $merges, $widths, 'E' . ($headerRow + 1)];
    }

    /** 各通過について端末ごとの記録時刻を並べる */
    private static function compareSheet(array $res): array
    {
        $labels = $res['flag_labels'];
        $devices = array_values(array_filter($res['devices'], fn ($d) => $d['pass_count'] > 0));
        $header = ['ビブ', 'チーム名', '走順', '氏名', '採用時刻', '経過', '採用方法'];
        foreach ($devices as $d) {
            $header[] = $d['name'];
        }
        $header[] = '管理者入力';
        $header[] = '端末間の差(秒)';
        $header[] = '確認事項';
        $rows = [array_map(fn ($h) => ['v' => $h, 's' => 'header'], $header)];
        $methods = ['median' => '中央値', 'earliest' => '最速', 'admin' => '管理者'];

        $emit = function (string $bib, string $team, $legNo, string $runner, array $c) use (&$rows, $devices, $labels, $methods) {
            $row = [
                ['v' => $bib, 's' => 'center'],
                ['v' => $team, 's' => 'text'],
                ['v' => $legNo, 's' => 'number'],
                ['v' => $runner, 's' => 'text'],
                ['v' => Xlsx::dateTimeValue($c['time_ms']), 's' => 'clock'],
                ['v' => Xlsx::durationValue($c['elapsed_ms'] ?? null), 's' => 'duration'],
                ['v' => $methods[$c['method']] ?? $c['method'], 's' => 'center'],
            ];
            foreach ($devices as $d) {
                $row[] = ['v' => Xlsx::dateTimeValue($c['devices'][$d['uuid']] ?? null), 's' => 'clock'];
            }
            $row[] = ['v' => Xlsx::dateTimeValue($c['admin_ms']), 's' => 'clock'];
            $row[] = ['v' => round($c['spread_ms'] / 1000, 1), 's' => 'number'];
            $row[] = ['v' => implode(' / ', array_map(fn ($f) => $labels[$f] ?? $f, $c['flags'])), 's' => 'text'];
            $rows[] = $row;
        };

        foreach ($res['teams'] as $t) {
            foreach ($t['legs'] as $leg) {
                if ($leg['crossing']) {
                    $emit($t['bib_label'], $t['name'], $leg['leg'], $leg['runner']['name'] ?? '', $leg['crossing'] + ['elapsed_ms' => $leg['elapsed_ms']]);
                }
            }
            foreach ($t['extra'] as $c) {
                $emit($t['bib_label'], $t['name'], '超過', '', $c + ['flags' => array_merge($c['flags'], ['extra'])]);
            }
        }
        foreach ($res['unknown_bibs'] as $u) {
            foreach ($u['crossings'] as $i => $c) {
                $emit(Util::bib($u['bib']), '(未登録)', $i + 1, '', $c + ['flags' => array_merge($c['flags'], ['unknown'])]);
            }
        }
        $widths = array_merge([6, 20, 6, 14, 12, 11, 9], array_fill(0, count($devices) + 1, 12), [10, 30]);
        return [$rows, [], $widths, 'E2'];
    }

    private static function passSheet(int $competitionId, ?int $start): array
    {
        $rows = [array_map(fn ($h) => ['v' => $h, 's' => 'header'], ['No', '通過時刻', '経過', 'ビブ', '端末', '状態', '受信日時', 'ID'])];
        $passes = array_reverse(Repo::passes($competitionId));
        foreach ($passes as $i => $p) {
            $state = $p['deleted'] ? '端末で削除' : ($p['admin_excluded'] ? '管理者が除外' : '有効');
            $rows[] = [
                ['v' => $i + 1, 's' => 'number'],
                ['v' => Xlsx::dateTimeValue($p['time_ms']), 's' => 'clock'],
                ['v' => $start === null ? null : Xlsx::durationValue($p['time_ms'] - $start), 's' => 'duration'],
                ['v' => Util::bib($p['bib']), 's' => 'center'],
                ['v' => $p['device_name'] ?? $p['device_uuid'], 's' => 'text'],
                ['v' => $state, 's' => 'center'],
                ['v' => $p['received_at'], 's' => 'text'],
                ['v' => $p['uuid'], 's' => 'muted'],
            ];
        }
        return [$rows, [], [6, 12, 11, 6, 14, 12, 20, 38], 'A2'];
    }

    public static function rosterTemplateCsv(): string
    {
        $rows = [
            Roster::TEMPLATE_HEADER,
            ['01', 'サンプルA', '一般', '', '1', '山田 太郎', 'ヤマダ タロウ', '男', '30', '○○クラブ', ''],
            ['', '', '', '', '2', '鈴木 花子', 'スズキ ハナコ', '女', '28', '○○クラブ', ''],
            ['', '', '', '', '3', '佐藤 次郎', 'サトウ ジロウ', '男', '35', '○○クラブ', ''],
            ['02', 'サンプルB（2名・オープン）', '一般', '', '1', '田中 一郎', 'タナカ イチロウ', '男', '40', '', ''],
            ['', '', '', '', '2', '高橋 愛', 'タカハシ アイ', '女', '22', '', ''],
        ];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    public static function downloadName(string $base, string $ext): string
    {
        $base = preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', $base) ?: 'export';
        return $base . '_' . date('Ymd_His') . '.' . $ext;
    }

    public static function sendFile(string $path, string $filename, string $mime): void
    {
        header('Content-Type: ' . $mime);
        header("Content-Disposition: attachment; filename=\"export." . pathinfo($filename, PATHINFO_EXTENSION) . "\"; filename*=UTF-8''" . rawurlencode($filename));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
    }
}
