<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

Auth::requirePage();

$type = (string) ($_GET['type'] ?? 'xlsx');

if ($type === 'template') {
    $csv = Export::rosterTemplateCsv();
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"roster_template.csv\"; filename*=UTF-8''" . rawurlencode('選手名簿テンプレート.csv'));
    echo $csv;
    exit;
}

$comp = Repo::competition((int) ($_GET['id'] ?? 0));
if (!$comp) {
    http_response_code(404);
    echo '大会が見つかりません';
    exit;
}

$path = Export::xlsx($comp['id']);
Util::audit($comp['id'], 'export.xlsx', '');
Export::sendFile($path, Export::downloadName($comp['name'] . '_成績', 'xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
@unlink($path);
