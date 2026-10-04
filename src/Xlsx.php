<?php
declare(strict_types=1);

/**
 * 依存ライブラリなしの最小 xlsx ライタ（ZipArchive を使用）
 *
 * セルは スカラー値 または ['v' => 値, 's' => スタイル名]。
 * 数値はそのまま、文字列はインライン文字列として書き出す。
 */
final class Xlsx
{
    /** スタイル名 → cellXfs のインデックス（styles() と順序を合わせる） */
    public const STYLES = [
        'default'  => 0,
        'header'   => 1,
        'text'     => 2,
        'duration' => 3,
        'clock'    => 4,
        'center'   => 5,
        'title'    => 6,
        'total'    => 7,
        'number'   => 8,
        'muted'    => 9,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $sheets = [];

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, string> $merges   例: ['A2:A4']
     * @param array<int, float> $widths    列幅（文字数）
     */
    public function addSheet(string $name, array $rows, array $merges = [], array $widths = [], ?string $freeze = null): void
    {
        $name = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '_', $name), 0, 31);
        $this->sheets[] = compact('name', 'rows', 'merges', 'widths', 'freeze');
    }

    /** ミリ秒の経過時間 → Excel のシリアル値（日単位） */
    public static function durationValue(?int $ms): ?float
    {
        return $ms === null ? null : $ms / 86400000;
    }

    /** エポックミリ秒 → Excel の日時シリアル値（ローカルタイムゾーン） */
    public static function dateTimeValue(?int $ms): ?float
    {
        if ($ms === null) {
            return null;
        }
        $offset = (int) date('Z', intdiv($ms, 1000));
        return ($ms / 1000 + $offset) / 86400 + 25569;
    }

    public static function col(int $index): string
    {
        $s = '';
        $index++;
        while ($index > 0) {
            $m = ($index - 1) % 26;
            $s = chr(65 + $m) . $s;
            $index = intdiv($index - 1, 26);
        }
        return $s;
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('xlsx を作成できません');
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes($n));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Lap Time Tracking</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>');

        $sheetsXml = '';
        $relsXml = '';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $sheetsXml .= '<sheet name="' . $this->esc($s['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $relsXml .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            $zip->addFromString("xl/worksheets/sheet$id.xml", $this->sheetXml($s, $i === 0));
        }
        $relsXml .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews><sheets>' . $sheetsXml . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relsXml . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->close();
    }

    private function contentTypes(int $n): string
    {
        $over = '';
        for ($i = 1; $i <= $n; $i++) {
            $over .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . $over . '</Types>';
    }

    /** @param array<string, mixed> $s */
    private function sheetXml(array $s, bool $selected): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $x .= '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>';
        if ($s['freeze'] && preg_match('/^([A-Z]+)(\d+)$/', $s['freeze'], $m)) {
            $colIdx = 0;
            foreach (str_split($m[1]) as $ch) {
                $colIdx = $colIdx * 26 + (ord($ch) - 64);
            }
            $xSplit = $colIdx - 1;
            $ySplit = (int) $m[2] - 1;
            $pane = $ySplit > 0 && $xSplit > 0 ? 'bottomRight' : ($ySplit > 0 ? 'bottomLeft' : 'topRight');
            $x .= '<pane' . ($xSplit > 0 ? ' xSplit="' . $xSplit . '"' : '') . ($ySplit > 0 ? ' ySplit="' . $ySplit . '"' : '')
                . ' topLeftCell="' . $s['freeze'] . '" activePane="' . $pane . '" state="frozen"/>';
        }
        $x .= '</sheetView></sheetViews>';
        $x .= '<sheetFormatPr defaultRowHeight="18"/>';
        if ($s['widths']) {
            $x .= '<cols>';
            foreach ($s['widths'] as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($s['rows'] as $r => $row) {
            $rowNum = $r + 1;
            $x .= '<row r="' . $rowNum . '">';
            foreach (array_values($row) as $c => $cell) {
                $x .= $this->cellXml(self::col($c) . $rowNum, $cell);
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($s['merges']) {
            $x .= '<mergeCells count="' . count($s['merges']) . '">';
            foreach ($s['merges'] as $m) {
                $x .= '<mergeCell ref="' . $m . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
        $x .= '<pageSetup paperSize="9" orientation="landscape" fitToHeight="0"/>';
        return $x . '</worksheet>';
    }

    /** @param mixed $cell */
    private function cellXml(string $ref, $cell): string
    {
        $style = 'default';
        $value = $cell;
        if (is_array($cell)) {
            $style = $cell['s'] ?? 'default';
            $value = $cell['v'] ?? null;
        }
        $s = self::STYLES[$style] ?? 0;
        $sAttr = $s ? ' s="' . $s . '"' : '';
        if ($value === null || $value === '') {
            return $s ? '<c r="' . $ref . '"' . $sAttr . '/>' : '';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '"' . $sAttr . '><v>' . $value . '</v></c>';
        }
        return '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . $this->esc((string) $value) . '</t></is></c>';
    }

    private function styles(): string
    {
        $font = '<name val="Yu Gothic"/><family val="3"/><charset val="128"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="[h]:mm:ss.0"/>'
            . '<numFmt numFmtId="165" formatCode="hh:mm:ss.0"/>'
            . '</numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/>' . $font . '</font>'
            . '<font><b/><sz val="11"/>' . $font . '</font>'
            . '<font><b/><sz val="14"/>' . $font . '</font>'
            . '<font><sz val="10"/><color rgb="FF777777"/>' . $font . '</font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F1"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF999999"/></left><right style="thin"><color rgb="FF999999"/></right>'
            . '<top style="thin"><color rgb="FF999999"/></top><bottom style="thin"><color rgb="FF999999"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="10">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function esc(string $s): string
    {
        // XML で使えない制御文字を除去
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
