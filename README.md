# Bin Bin Marketing

Ứng dụng nội bộ cho team marketing của **Bin Bin Hotel**: lên lịch đăng bài, duyệt nội dung, theo dõi sự kiện, quản lý 4 kênh mạng xã hội (Facebook, TikTok, Google Maps, Zalo OA) và gửi email marketing qua Brevo.

## Chức năng

| Mục | Làm được gì |
|---|---|
| **Tổng quan** | Bài cần đăng hôm nay (đánh dấu đã đăng ngay tại chỗ), bài chờ duyệt, sự kiện 45 ngày tới và cảnh báo sự kiện chưa có bài, số bài trong tuần theo kênh, kết quả email gần nhất, hoạt động gần đây. |
| **Lịch đăng bài** | *Bảng kênh*: 2 tuần, mỗi kênh một hàng, sự kiện là các thanh kéo dài qua nhiều ngày; kéo thả bài sang ngày khác. *Lịch tháng*: lọc theo kênh. Bấm dấu + ở một ô để soạn bài cho đúng ngày và kênh đó. |
| **Bài đăng** | Soạn bài theo kênh (định dạng, gợi ý, đếm ký tự), đính kèm ảnh/video tới 50 MB, gắn với sự kiện. Quy trình: Nháp → Chờ duyệt → Đã duyệt → Đã đăng. Quản trị viên duyệt hoặc trả về kèm góp ý. Nút "Sao chép để đăng", nhân bản bài sang kênh khác, ghi link và số liệu (xem, thích, bình luận, chia sẻ) sau khi đăng. |
| **Sự kiện** | Lịch lễ, Tết Việt Nam có sẵn; thêm mùa du lịch, ưu đãi của khách sạn, sự kiện địa phương. Sự kiện có thể lặp lại hằng năm. |
| **Kênh** | Tự động lấy số liệu: dán link Google Maps (điểm, số đánh giá, đánh giá mới); kết nối Facebook Page, TikTok, Zalo OA (người theo dõi, tiếp cận, tương tác, số liệu từng bài). Cập nhật mỗi 12 giờ; AI (Claude) viết nhận xét và đề xuất mỗi tuần. |
| **Email marketing** | Danh bạ (thêm tay, nhập/xuất CSV, gắn nhóm), chiến dịch HTML với `{{ten}}`, xem trước, gửi thử, gửi theo nhóm; thống kê nhận/mở/nhấp/trả về qua webhook Brevo; trang huỷ nhận email cho khách. |
| **Tài khoản** | 3 vai trò: Quản trị, Biên tập, Chỉ xem. Quản trị viên tạo tài khoản, đổi vai trò, khoá tài khoản, đặt lại mật khẩu. |

Đăng bài lên các kênh vẫn do team tự bấm đăng; app lo lịch, duyệt nội dung, lấy số liệu và phân tích. Cách lấy khoá API cho từng kênh xem trong [DEPLOY.md](DEPLOY.md), mục 5.

## Chạy thử trên máy

Cần Node.js 22.5 trở lên (dùng SQLite có sẵn trong Node).

```bash
npm install
cp .env.example .env      # rồi điền ADMIN_USERNAME, ADMIN_EMAIL, ADMIN_PASSWORD
npm run dev
```

Mở http://localhost:3000 và đăng nhập bằng `ADMIN_USERNAME` / `ADMIN_PASSWORD` trong `.env`.

## Cấu trúc

```
src/
  server.js          Khởi động Express, bảo mật, phiên đăng nhập, CSRF
  config.js          Đọc biến môi trường
  db/                Kết nối MySQL/SQLite, migration, dữ liệu nền
  lib/               Đăng nhập & phân quyền, ngày giờ VN, Brevo, upload, CSV
  routes/            Mỗi mục một file: posts, calendar, events, channels, email, users
views/               Giao diện EJS
public/              CSS, JS, logo
```

Không có bước build. Database tự tạo bảng khi khởi động lần đầu.

## Deploy

Xem [DEPLOY.md](DEPLOY.md): đưa code lên GitHub, tạo MySQL và app Node.js trên Hostinger, cấu hình Brevo.
