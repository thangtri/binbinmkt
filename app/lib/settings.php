<?php
// Thông tin khách sạn dùng chung (AI viết bài dựa vào đây). Lưu trong bảng settings.

final class Settings
{
    const FIELDS = [
        ['key' => 'hotel_name', 'label' => 'Tên khách sạn', 'default' => 'Bin Bin Hotel'],
        ['key' => 'address', 'label' => 'Địa chỉ chung', 'default' => '', 'hint' => 'Địa chỉ văn phòng hoặc chi nhánh chính; địa chỉ từng chi nhánh khai báo ở mục Chi nhánh'],
        ['key' => 'hotline', 'label' => 'Hotline đặt phòng', 'default' => ''],
        ['key' => 'zalo', 'label' => 'Zalo đặt phòng', 'default' => '', 'hint' => 'Số Zalo hoặc link zalo.me'],
        ['key' => 'booking_url', 'label' => 'Link đặt phòng', 'default' => '', 'hint' => 'Website, Booking, Agoda…'],
        ['key' => 'highlights', 'label' => 'Điểm mạnh của khách sạn', 'default' => '', 'long' => true, 'hint' => 'Mỗi dòng một ý: loại phòng, tiện ích, vị trí, bữa sáng…'],
        ['key' => 'audience', 'label' => 'Khách hàng chính', 'default' => '', 'long' => true, 'hint' => 'Ví dụ: cặp đôi cuối tuần, khách công tác, gia đình có trẻ nhỏ'],
        ['key' => 'voice', 'label' => 'Giọng văn', 'default' => 'Thân thiện, ấm áp, lịch sự; xưng "Bin Bin" và gọi khách là "bạn".', 'long' => true],
        ['key' => 'avoid', 'label' => 'Điều cần tránh', 'default' => '', 'long' => true, 'hint' => 'Từ ngữ, cam kết hoặc chủ đề không được dùng'],
        ['key' => 'hashtags', 'label' => 'Hashtag mặc định', 'default' => '#BinBinHotel'],
    ];

    public static function all(): array
    {
        $saved = array_column(DB::all('SELECT name, value FROM settings'), 'value', 'name');
        $out = [];
        foreach (self::FIELDS as $f) $out[$f['key']] = $saved[$f['key']] ?? $f['default'];
        return $out;
    }

    public static function save(array $values): void
    {
        $now = D::now();
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f['key'], $values)) continue;
            $v = mb_substr(trim((string) $values[$f['key']]), 0, 5000);
            if (DB::get('SELECT name FROM settings WHERE name = ?', [$f['key']])) DB::run('UPDATE settings SET value = ?, updated_at = ? WHERE name = ?', [$v, $now, $f['key']]);
            else DB::run('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [$f['key'], $v, $now]);
        }
    }
}
