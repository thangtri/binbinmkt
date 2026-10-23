<?php
// Gọi API bên ngoài: có giới hạn thời gian, lỗi trả về thông báo dễ đọc

class ConnectorError extends RuntimeException
{
    public bool $needsReconnect;

    public function __construct(string $message, bool $needsReconnect = false)
    {
        parent::__construct($message);
        $this->needsReconnect = $needsReconnect;
    }
}

final class Http
{
    /**
     * $opts: method, headers (tên => giá trị), form (mảng → x-www-form-urlencoded), json, body (chuỗi hoặc mảng multipart),
     *        timeout (giây), follow (theo chuyển hướng, mặc định có)
     * @return array{ok:bool, status:int, data:mixed, text:string, headers:array}
     */
    public static function request(string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        $headers = ['Accept' => 'application/json'] + ($opts['headers'] ?? []);
        $method = $opts['method'] ?? 'GET';
        if (isset($opts['form'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif (array_key_exists('json', $opts)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $opts['follow'] ?? true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $opts['timeout'] ?? 20,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if (isset($opts['upload'])) {
            // PUT nguyên file (TikTok)
            $fh = fopen($opts['upload'], 'rb');
            curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($opts['upload'])]);
        }
        $text = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (isset($fh)) fclose($fh);
        if ($errno) {
            $host = parse_url($url, PHP_URL_HOST);
            throw new ConnectorError($errno === CURLE_OPERATION_TIMEDOUT ? 'Máy chủ của kênh phản hồi quá chậm, thử lại sau.' : "Không kết nối được tới $host.");
        }
        $data = $text !== '' ? json_decode($text, true) : null;
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'text' => (string) $text, 'headers' => $respHeaders];
    }
}
