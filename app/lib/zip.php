<?php
// Tạo file ZIP kiểu "store" (không nén): ảnh và video vốn đã nén nên không cần nén thêm.
// Ghi thẳng ra trình duyệt để không phải giữ cả file trong bộ nhớ.

final class Zip
{
    /** @param list<array{name:string, path:string}> $entries */
    public static function send(string $filename, array $entries): never
    {
        Session::save();
        @set_time_limit(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $now = getdate();
        $time = ($now['hours'] << 11) | ($now['minutes'] << 5) | ($now['seconds'] >> 1);
        $date = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
        $central = '';
        $offset = 0;
        foreach ($entries as $e) {
            $size = filesize($e['path']);
            $crc = hexdec(hash_file('crc32b', $e['path']));
            $name = $e['name'];
            // 0x0800: tên file UTF-8
            echo pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0), $name;
            $fh = fopen($e['path'], 'rb');
            while (!feof($fh)) {
                echo fread($fh, 1 << 20);
                flush();
            }
            fclose($fh);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $offset += 30 + strlen($name) + $size;
        }
        echo $central;
        echo pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0);
        exit;
    }
}
