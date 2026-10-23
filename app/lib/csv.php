<?php
// Đọc/ghi CSV đơn giản (có hỗ trợ dấu ngoặc kép, dấu phẩy hoặc chấm phẩy)

final class Csv
{
    public static function parse(string $text): array
    {
        $src = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $firstLine = preg_split('/\r?\n/', $src, 2)[0] ?? '';
        $sep = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $rows = [];
        $row = [];
        $cell = '';
        $quoted = false;
        $n = strlen($src);
        for ($i = 0; $i < $n; $i++) {
            $c = $src[$i];
            if ($quoted) {
                if ($c === '"' && ($src[$i + 1] ?? '') === '"') {
                    $cell .= '"';
                    $i++;
                } elseif ($c === '"') $quoted = false;
                else $cell .= $c;
            } elseif ($c === '"') $quoted = true;
            elseif ($c === $sep) {
                $row[] = $cell;
                $cell = '';
            } elseif ($c === "\n" || $c === "\r") {
                if ($c === "\r" && ($src[$i + 1] ?? '') === "\n") $i++;
                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
            } else $cell .= $c;
        }
        if ($cell !== '' || $row) {
            $row[] = $cell;
            $rows[] = $row;
        }
        return array_values(array_filter($rows, fn($r) => implode('', array_map('trim', $r)) !== ''));
    }

    private static function cell($v): string
    {
        $s = (string) ($v ?? '');
        return preg_match('/[",\n;]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
    }

    public static function stringify(array $rows): string
    {
        return "\xEF\xBB\xBF" . implode("\r\n", array_map(fn($r) => implode(',', array_map([self::class, 'cell'], $r)), $rows));
    }

    public static function download(string $filename, array $rows): never
    {
        Session::save();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo self::stringify($rows);
        exit;
    }
}
