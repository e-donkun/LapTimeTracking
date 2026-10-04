<?php
declare(strict_types=1);

/**
 * 依存なしの簡易テスト: php tests/run.php
 * 一時 DB を作り、名簿取り込み → 複数端末の同期 → 集計 → Excel 出力 までを検証する。
 */

$db = sys_get_temp_dir() . '/ltt_test_' . getmypid() . '.sqlite';
putenv('LTT_DB_PATH=' . $db);
require dirname(__DIR__) . '/src/bootstrap.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    if (!$ok) {
        $failures++;
    }
}
function eq($a, $b): bool
{
    return $a === $b;
}

register_shutdown_function(function () use ($db) {
    foreach ([$db, "$db-wal", "$db-shm"] as $f) {
        @unlink($f);
    }
});

echo "Util\n";
check('duration', Util::duration(754321) === '12:34.3', Util::duration(754321));
check('duration hours', Util::duration(3723456) === '1:02:03.4');
check('parseDuration', Util::parseDuration('12:34.5') === 754500);
check('parseDuration full-width', Util::parseDuration('１２：３４．５') === 754500);
check('parseClock', Util::parseClock('2026-10-04 10:00:01.2', null) === strtotime('2026-10-04 10:00:01') * 1000 + 200);
check('bib pad', Util::bib(5) === '05');

echo "Sample\n";
$sample = Db::all('SELECT * FROM competitions');
check('sample seeded on new DB', count($sample) === 1 && $sample[0]['name'] === Sample::NAME);
$sid = (int) $sample[0]['id'];
$steams = Repo::teams($sid);
check('sample teams', count($steams) === 8);
$sres = Results::compute($sid);
$sopen = array_values(array_filter($sres['teams'], fn ($t) => $t['is_open']));
check('sample open teams (2名 + オープン指定)', count($sopen) === 2);
check('sample not re-seeded', (function () { Sample::createIfEmpty(); return (int) Db::value('SELECT COUNT(*) FROM competitions') === 1; })());
Db::exec('DELETE FROM competitions WHERE id = ?', [$sid]);

echo "Roster\n";
$csv = "ビブ,チーム名,区分,走順,氏名,フリガナ\n"
     . "01,チームA,一般,1,A1,エーイチ\n,,,2,A2,\n,,,3,A3,\n"
     . "２,チームB,一般,1,B1,\n,,,2,B2,\n,,,3,B3,\n"
     . "03,チームC（2名）,一般,1,C1,\n,,,2,C2,\n"
     . "x9,不正,,1,X,\n";
$parsed = Roster::parse($csv);
check('teams parsed', count($parsed['teams']) === 3, (string) count($parsed['teams']));
check('continuation rows', count($parsed['teams'][0]['runners']) === 3);
check('full-width bib', $parsed['teams'][1]['bib'] === 2);
check('invalid bib warned', count($parsed['warnings']) >= 1);
$tsv = "No.\t氏名\t走順\n4\tD1\t2\n\tD2\t1\n";
$p2 = Roster::parse($tsv);
check('tsv + leg sort', $p2['teams'][0]['runners'][0]['name'] === 'D2');

$cid = Repo::saveCompetition(['name' => 'テスト駅伝', 'event_date' => '2026-10-04', 'location' => '公園', 'tolerance_ms' => 1000]);
$r = Roster::import($cid, $parsed['teams'], 'replace');
check('import created', $r['created'] === 3);
$r = Roster::import($cid, $parsed['teams'], 'merge');
check('import merge updates', $r['updated'] === 3 && $r['created'] === 0);
check('runners stored', (int) Db::value('SELECT COUNT(*) FROM runners r JOIN teams t ON t.id = r.team_id WHERE t.competition_id = ?', [$cid]) === 8);

echo "Results\n";
$start = strtotime('2026-10-04 10:00:00') * 1000;
Db::exec('UPDATE competitions SET start_ms = ? WHERE id = ?', [$start, $cid]);

$devA = 'aaaaaaaa-0000-4000-8000-000000000001';
$devB = 'bbbbbbbb-0000-4000-8000-000000000002';
Repo::touchDevice($cid, ['uuid' => $devA, 'name' => '端末A']);
Repo::touchDevice($cid, ['uuid' => $devB, 'name' => '端末B']);
$seq = 0;
function pass(int $cid, string $dev, int $bib, int $ms, int $deleted = 0): string
{
    global $seq;
    $uuid = sprintf('%08d-1111-4111-8111-%012d', ++$seq, $seq);
    Db::exec(
        "INSERT INTO passes (uuid, competition_id, device_uuid, bib, time_ms, deleted, client_updated_ms) VALUES (?,?,?,?,?,?,1)",
        [$uuid, $cid, $dev, $bib, $ms, $deleted]
    );
    return $uuid;
}
$m = fn (float $sec) => $start + (int) round($sec * 1000);
check('race not finished before any pass', Results::race($cid)['finished'] === false && Results::race($cid)['teams'] === 3);
// チームA: 両端末が記録（少しずれ）
pass($cid, $devA, 1, $m(600.0));  pass($cid, $devB, 1, $m(600.4));
pass($cid, $devA, 1, $m(1250.0)); pass($cid, $devB, 1, $m(1250.2));
pass($cid, $devA, 1, $m(1900.0)); pass($cid, $devB, 1, $m(1903.0)); // 3秒差 → 警告
// チームB: 端末Bが 2走 を記録漏れ、端末A は 1走 を二重入力
pass($cid, $devA, 2, $m(580.0));  pass($cid, $devA, 2, $m(581.0)); pass($cid, $devB, 2, $m(580.2));
pass($cid, $devA, 2, $m(1200.0));
pass($cid, $devA, 2, $m(1800.0)); pass($cid, $devB, 2, $m(1800.0));
// チームC（2名 → オープン）
pass($cid, $devA, 3, $m(500.0));  pass($cid, $devB, 3, $m(500.0));
pass($cid, $devA, 3, $m(1000.0)); pass($cid, $devB, 3, $m(1000.0));
// 削除済み・未登録ビブ・スタート前
pass($cid, $devA, 1, $m(700.0), 1);
pass($cid, $devA, 77, $m(900.0));
pass($cid, $devB, 2, $m(-30.0));

$res = Results::compute($cid);
check('race finished at last team finish', $res['race']['finished'] === true && $res['race']['elapsed_ms'] === 1901500, json_encode($res['race']));
$byBib = [];
foreach ($res['teams'] as $t) {
    $byBib[$t['bib']] = $t;
}
$A = $byBib[1];
$B = $byBib[2];
$C = $byBib[3];
check('A finished', $A['state'] === 'finished');
check('A leg1 median', $A['legs'][0]['elapsed_ms'] === 600200, (string) $A['legs'][0]['elapsed_ms']);
check('A leg2 split', $A['legs'][1]['split_ms'] === 1250100 - 600200, (string) $A['legs'][1]['split_ms']);
check('A leg3 spread flag', in_array('spread', $A['legs'][2]['crossing']['flags'], true));
check('A total', $A['total_ms'] === 1901500, (string) $A['total_ms']);
check('B duplicate flag', in_array('duplicate', $B['legs'][0]['crossing']['flags'], true));
check('B leg1 earliest per device', $B['legs'][0]['elapsed_ms'] === 580100, (string) $B['legs'][0]['elapsed_ms']);
check('B missing flag', in_array('missing', $B['legs'][1]['crossing']['flags'], true));
check('B rank 1', $B['rank'] === 1 && $A['rank'] === 2, "B={$B['rank']} A={$A['rank']}");
check('C open, no rank', $C['is_open'] === true && $C['rank'] === null && $C['state'] === 'finished');
check('C total measured', $C['total_ms'] === 1000000);
check('unknown bib 77', count($res['unknown_bibs']) === 1 && $res['unknown_bibs'][0]['bib'] === 77);
check('prestart', count($res['prestart']) === 1);
check('split rank leg1', $B['legs'][0]['split_rank'] === 1 && $A['legs'][0]['split_rank'] === 2);
check('order', $res['teams'][0]['bib'] === 2 && $res['teams'][1]['bib'] === 1 && $res['teams'][2]['bib'] === 3);

// 管理者入力が優先される
Db::exec(
    "INSERT INTO passes (uuid, competition_id, device_uuid, source, bib, time_ms) VALUES ('admin-test-0001', ?, 'admin', 'admin', 1, ?)",
    [$cid, $m(1901.0)]
);
$res = Results::compute($cid);
foreach ($res['teams'] as $t) {
    if ($t['bib'] === 1) {
        check('admin override', $t['total_ms'] === 1901000 && $t['legs'][2]['crossing']['method'] === 'admin');
        check('admin clears spread', !in_array('spread', $t['legs'][2]['crossing']['flags'], true));
    }
}
// 全チームのゴールが揃うとレース終了（最後のゴール時刻でタイマー停止）
$race = Results::race($cid);
check('race finish follows admin override', $race['finished'] === true && $race['finish_ms'] === $m(1901.0) && $race['elapsed_ms'] === 1901000, json_encode($race));
// DNF のチームは対象外
Db::exec("UPDATE teams SET status = 'DNF' WHERE competition_id = ? AND bib = 3", [$cid]);
check('DNF team excluded from race state', Results::race($cid)['teams'] === 2);
Db::exec("UPDATE teams SET status = '' WHERE competition_id = ? AND bib = 3", [$cid]);

// 管理者による除外
Db::exec("UPDATE passes SET admin_excluded = 1 WHERE bib = 2 AND time_ms = ?", [$m(1200.0)]);
$res = Results::compute($cid);
foreach ($res['teams'] as $t) {
    if ($t['bib'] === 2) {
        check('exclusion drops crossing', $t['legs_done'] === 2 && $t['state'] === 'running');
    }
}
check('race resumes when a finish is removed', $res['race']['finished'] === false);
// earliest 採用
Db::exec("UPDATE competitions SET adopt_method = 'earliest' WHERE id = ?", [$cid]);
$res = Results::compute($cid);
foreach ($res['teams'] as $t) {
    if ($t['bib'] === 1) {
        check('earliest method', $t['legs'][0]['elapsed_ms'] === 600000);
    }
}

echo "Export\n";
$path = Export::xlsx($cid);
$zip = new ZipArchive();
check('xlsx opens', $zip->open($path) === true);
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
check('sheet has team', str_contains($sheet, 'チームA'));
check('sheet has headers', str_contains($sheet, '所要時間') && str_contains($sheet, 'チーム記録'));
$xml = simplexml_load_string($sheet);
check('sheet xml valid', $xml !== false);
foreach (['xl/workbook.xml', 'xl/styles.xml', '[Content_Types].xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml'] as $f) {
    check("$f valid xml", simplexml_load_string((string) $zip->getFromName($f)) !== false);
}
$zip->close();
@unlink($path);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
