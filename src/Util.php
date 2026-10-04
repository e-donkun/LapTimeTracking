<?php
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public string $errorCode;

    public function __construct(string $message, int $status = 400, string $errorCode = 'bad_request')
    {
        parent::__construct($message, $status);
        $this->errorCode = $errorCode;
    }
}

final class Util
{
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function bib(int $bib): string
    {
        return sprintf('%02d', $bib);
    }

    /** ミリ秒 → "H:MM:SS.s" / "M:SS.s"（1/10秒切り捨て） */
    public static function duration(?int $ms): string
    {
        if ($ms === null) {
            return '';
        }
        $neg = $ms < 0;
        $ms = abs($ms);
        $tenths = intdiv($ms % 1000, 100);
        $sec = intdiv($ms, 1000);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        $out = $h > 0 ? sprintf('%d:%02d:%02d.%d', $h, $m, $s, $tenths) : sprintf('%d:%02d.%d', $m, $s, $tenths);
        return ($neg ? '-' : '') . $out;
    }

    /** エポックミリ秒 → 時刻 "HH:MM:SS.s" */
    public static function clock(?int $ms): string
    {
        if ($ms === null) {
            return '';
        }
        return date('H:i:s', intdiv($ms, 1000)) . '.' . intdiv($ms % 1000, 100);
    }

    /** "HH:MM:SS(.s)" または "YYYY-MM-DD HH:MM:SS(.s)" をエポックミリ秒に。日付省略時は $baseDate を使う */
    public static function parseClock(string $text, ?string $baseDate): ?int
    {
        $text = trim(mb_convert_kana($text, 'as'));
        if (!preg_match('/^(?:(\d{4}-\d{2}-\d{2})[ T])?(\d{1,2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3}))?)?$/', $text, $m)) {
            return null;
        }
        $date = $m[1] !== '' ? $m[1] : ($baseDate ?: date('Y-m-d'));
        $ts = strtotime(sprintf('%s %02d:%02d:%02d', $date, $m[2], $m[3], $m[4] ?? 0));
        if ($ts === false) {
            return null;
        }
        $frac = isset($m[5]) ? (int) str_pad($m[5], 3, '0') : 0;
        return $ts * 1000 + $frac;
    }

    /** "M:SS.s" / "H:MM:SS.s" / "SS.s" の経過時間をミリ秒に */
    public static function parseDuration(string $text): ?int
    {
        $text = trim(mb_convert_kana($text, 'as'));
        if (!preg_match('/^(?:(?:(\d+):)?(\d{1,2}):)?(\d{1,2})(?:\.(\d{1,3}))?$/', $text, $m)) {
            return null;
        }
        $h = (int) ($m[1] ?? 0);
        $mi = (int) ($m[2] ?? 0);
        $s = (int) $m[3];
        $frac = isset($m[4]) ? (int) str_pad($m[4], 3, '0') : 0;
        return (($h * 60 + $mi) * 60 + $s) * 1000 + $frac;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function audit(?int $competitionId, string $action, string $detail): void
    {
        $user = Auth::user();
        $actor = $user['username'] ?? 'system';
        Db::exec(
            'INSERT INTO audit_log (competition_id, actor, action, detail) VALUES (?, ?, ?, ?)',
            [$competitionId, $actor, $action, $detail]
        );
    }

    public static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
