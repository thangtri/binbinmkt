<?php
// Zalo OA qua Zalo Official Account API (OAuth v4 có PKCE).
// Access token sống khoảng 25 giờ; refresh token chỉ dùng được một lần, nên luôn lưu cái mới.

final class ZaloConnector
{
    const KEY = 'zalo';
    const OAUTH = true;
    const OAUTH_URL = 'https://oauth.zaloapp.com/v4/oa';

    public static function isConfigured(): bool
    {
        return cfg('zalo.appId') !== '' && cfg('zalo.appSecret') !== '';
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function pkce(): array
    {
        $verifier = self::b64url(random_bytes(32));
        return ['verifier' => $verifier, 'challenge' => self::b64url(hash('sha256', $verifier, true))];
    }

    public static function authUrl(string $redirectUri, string $state, ?string $challenge = null): string
    {
        return self::OAUTH_URL . '/permission?' . http_build_query(['app_id' => cfg('zalo.appId'), 'redirect_uri' => $redirectUri, 'code_challenge' => $challenge, 'state' => $state]);
    }

    private static function tokenResult(array $res): array
    {
        $d = $res['data'] ?? [];
        if (empty($d['access_token'])) throw new ConnectorError($d['error_description'] ?? $d['error_name'] ?? 'Zalo không trả về token.', true);
        return ['accessToken' => $d['access_token'], 'refreshToken' => $d['refresh_token'] ?? null, 'expiresIn' => (int) ($d['expires_in'] ?? 0) ?: 90000, 'refreshExpiresIn' => 90 * 86400];
    }

    public static function exchange(string $code, string $redirectUri, ?string $verifier = null): array
    {
        return self::tokenResult(Http::request(self::OAUTH_URL . '/access_token', [
            'method' => 'POST',
            'headers' => ['secret_key' => cfg('zalo.appSecret')],
            'form' => ['app_id' => cfg('zalo.appId'), 'code' => $code, 'grant_type' => 'authorization_code', 'code_verifier' => $verifier],
        ]));
    }

    public static function refresh(string $refreshToken): array
    {
        return self::tokenResult(Http::request(self::OAUTH_URL . '/access_token', [
            'method' => 'POST',
            'headers' => ['secret_key' => cfg('zalo.appSecret')],
            'form' => ['app_id' => cfg('zalo.appId'), 'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token'],
        ]));
    }

    public static function fetchStats(array $account, ?array $token): array
    {
        if (empty($token['access_token'])) throw new ConnectorError('Chưa kết nối Zalo OA.', true);
        $res = Http::request('https://openapi.zalo.me/v2.0/oa/getoa', ['headers' => ['access_token' => $token['access_token']]]);
        $d = $res['data'] ?? [];
        if (!$res['ok'] || (!empty($d['error']) && $d['error'] !== 0)) {
            // -216: token không hợp lệ hoặc hết hạn
            throw new ConnectorError($d['message'] ?? "Zalo trả về lỗi {$res['status']}", ($d['error'] ?? 0) === -216);
        }
        $oa = $d['data'] ?? [];
        return [
            'followers' => $oa['num_follower'] ?? null,
            'reach' => null,
            'engagement' => null,
            'profile' => ['name' => $oa['name'] ?? null, 'url' => !empty($oa['oa_id']) ? "https://zalo.me/{$oa['oa_id']}" : null, 'oaId' => $oa['oa_id'] ?? null],
            'posts' => [],
        ];
    }
}
