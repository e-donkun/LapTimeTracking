<?php
declare(strict_types=1);

/**
 * 選手名簿の CSV / TSV 取り込み
 *
 * 1 行 = 1 選手（縦持ち）。チーム列（ビブ・チーム名・区分）が空の行は直前の行のチームを引き継ぐので、
 * Excel でセル結合された名簿をそのままコピー＆ペーストしても取り込める。
 */
final class Roster
{
    /** 見出し名 → 内部キー */
    private const HEADERS = [
        'bib'         => ['ビブ', 'ビブ番号', 'ビブno', 'ゼッケン', 'ゼッケン番号', 'no', 'no.', '番号', 'bib', 'チーム番号'],
        'team'        => ['チーム名', 'チーム', 'team', 'チーム名称'],
        'category'    => ['区分', '部門', 'カテゴリ', 'カテゴリー', '種別', 'category'],
        'open'        => ['オープン', 'オープン参加', 'open'],
        'team_note'   => ['チーム備考'],
        'leg'         => ['走順', '区間', '走', 'leg', '順番'],
        'name'        => ['氏名', '名前', '選手名', '選手氏名', 'name'],
        'kana'        => ['フリガナ', 'ふりがな', 'カナ', 'よみがな', 'よみ', 'kana'],
        'gender'      => ['性別', 'gender', 'sex'],
        'age'         => ['学年', '年齢', '学年・年齢', '学年/年齢', '年代', 'age'],
        'affiliation' => ['所属', '学校', '学校名', '所属名', '所属先', 'affiliation'],
        'note'        => ['備考', 'メモ', 'note'],
    ];

    public const TEMPLATE_HEADER = ['ビブ', 'チーム名', '区分', 'オープン', '走順', '氏名', 'フリガナ', '性別', '学年・年齢', '所属', '備考'];

    /**
     * @return array{teams: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public static function parse(string $text): array
    {
        $text = self::toUtf8($text);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw new ApiError('見出し行とデータ行が必要です');
        }
        $delim = substr_count($lines[0], "\t") > 0 ? "\t" : ',';
        $rows = [];
        // 改行を含むセルにも対応するため str_getcsv ではなく一時ストリームで読む
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, implode("\n", $lines));
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => trim((string) $v), $r);
        }
        fclose($fh);

        $map = self::mapHeader(array_shift($rows));
        if (!isset($map['bib']) || !isset($map['name'])) {
            throw new ApiError('見出し行に「ビブ」と「氏名」の列が必要です');
        }

        $teams = [];
        $warnings = [];
        $current = null;
        foreach ($rows as $n => $r) {
            $line = $n + 2;
            $get = fn (string $k) => isset($map[$k]) ? ($r[$map[$k]] ?? '') : '';
            $bibText = mb_convert_kana($get('bib'), 'n');
            if ($bibText !== '') {
                if (!preg_match('/^\d{1,2}$/', $bibText)) {
                    $warnings[] = "{$line}行目: ビブ「{$bibText}」が 00〜99 ではないためスキップしました";
                    $current = null;
                    continue;
                }
                $bib = (int) $bibText;
                if (!isset($teams[$bib])) {
                    $teams[$bib] = ['bib' => $bib, 'name' => '', 'category' => '', 'force_open' => 0, 'note' => '', 'runners' => []];
                }
                $current = $bib;
            }
            if ($current === null) {
                $warnings[] = "{$line}行目: ビブが空のためスキップしました";
                continue;
            }
            $t = &$teams[$current];
            foreach (['team' => 'name', 'category' => 'category', 'team_note' => 'note'] as $src => $dst) {
                if ($get($src) !== '' && $t[$dst] === '') {
                    $t[$dst] = $get($src);
                }
            }
            $open = $get('open');
            if ($open !== '' && !in_array(mb_strtolower($open), ['0', 'no', 'false', '×', '-', 'いいえ'], true)) {
                $t['force_open'] = 1;
            }
            if ($get('name') === '') {
                unset($t);
                continue;
            }
            $leg = (int) mb_convert_kana(preg_replace('/[^0-9０-９]/u', '', $get('leg')) ?? '', 'n');
            $t['runners'][] = [
                'leg'         => $leg > 0 ? $leg : 999 + count($t['runners']),
                'name'        => $get('name'),
                'kana'        => $get('kana'),
                'gender'      => $get('gender'),
                'age'         => $get('age'),
                'affiliation' => $get('affiliation'),
                'note'        => $get('note'),
            ];
            unset($t);
        }
        foreach ($teams as &$t) {
            usort($t['runners'], fn ($a, $b) => $a['leg'] <=> $b['leg']);
        }
        unset($t);
        ksort($teams);
        return ['teams' => array_values($teams), 'warnings' => $warnings];
    }

    /**
     * @param array<int, array<string, mixed>> $teams
     * @return array{created: int, updated: int}
     */
    public static function import(int $competitionId, array $teams, string $mode): array
    {
        return Db::transaction(function () use ($competitionId, $teams, $mode) {
            if ($mode === 'replace') {
                Db::exec('DELETE FROM teams WHERE competition_id = ?', [$competitionId]);
            }
            $created = 0;
            $updated = 0;
            foreach ($teams as $t) {
                $existing = Db::one('SELECT * FROM teams WHERE competition_id = ? AND bib = ?', [$competitionId, $t['bib']]);
                if ($existing) {
                    $id = (int) $existing['id'];
                    Db::exec(
                        "UPDATE teams SET name = ?, category = ?, force_open = ?, note = ?, updated_at = datetime('now','localtime') WHERE id = ?",
                        [
                            $t['name'] !== '' ? $t['name'] : $existing['name'],
                            $t['category'] !== '' ? $t['category'] : $existing['category'],
                            $t['force_open'] ?: (int) $existing['force_open'],
                            $t['note'] !== '' ? $t['note'] : $existing['note'],
                            $id,
                        ]
                    );
                    Db::exec('DELETE FROM runners WHERE team_id = ?', [$id]);
                    $updated++;
                } else {
                    Db::exec(
                        'INSERT INTO teams (competition_id, bib, name, category, force_open, note) VALUES (?, ?, ?, ?, ?, ?)',
                        [$competitionId, $t['bib'], $t['name'], $t['category'], $t['force_open'], $t['note']]
                    );
                    $id = Db::lastId();
                    $created++;
                }
                foreach (array_values($t['runners']) as $i => $r) {
                    Db::exec(
                        'INSERT INTO runners (team_id, leg, name, kana, gender, age, affiliation, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$id, $i + 1, $r['name'], $r['kana'], $r['gender'], $r['age'], $r['affiliation'], $r['note']]
                    );
                }
            }
            Util::audit($competitionId, 'roster.import', "mode=$mode created=$created updated=$updated");
            return ['created' => $created, 'updated' => $updated];
        });
    }

    /** @return array<string, int> */
    private static function mapHeader(?array $header): array
    {
        $map = [];
        foreach ((array) $header as $i => $h) {
            $norm = mb_strtolower(str_replace([' ', '　'], '', mb_convert_kana((string) $h, 'asKV')));
            foreach (self::HEADERS as $key => $aliases) {
                if (isset($map[$key])) {
                    continue;
                }
                foreach ($aliases as $a) {
                    if ($norm === mb_strtolower($a)) {
                        $map[$key] = $i;
                        continue 3;
                    }
                }
            }
        }
        return $map;
    }

    private static function toUtf8(string $text): string
    {
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'SJIS-win');
        }
        return $text;
    }
}
