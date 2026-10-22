# Hướng dẫn deploy: GitHub → Hostinger

Gói Hostinger cần hỗ trợ **Node.js** (Business hoặc Cloud). Tên menu trong hPanel có thể khác đôi chút theo thời điểm; nếu không thấy đúng tên, tìm mục "Node.js" trong trang quản lý website.

## 1. Đưa code lên GitHub

1. Tạo repository mới trên GitHub, nên để **Private**. Không tick "Add README" để repo trống.
2. Trong thư mục dự án:

```bash
git remote add origin https://github.com/<tai-khoan>/binbin-marketing.git
git push -u origin main
```

File `.env`, thư mục `data/` và `uploads/` đã nằm trong `.gitignore`, nên mật khẩu và dữ liệu không bị đưa lên GitHub.

## 2. Tạo database MySQL trên Hostinger

1. hPanel → **Databases → MySQL Databases**.
2. Tạo database và user mới, đặt mật khẩu mạnh. Hostinger tự thêm tiền tố, ví dụ `u123456789_binbin`.
3. Ghi lại 4 thông tin: tên database, user, mật khẩu, host (thường là `localhost`, hoặc địa chỉ ghi trong trang MySQL).

## 3. Tạo app Node.js

1. hPanel → **Websites → Add website → Node.js Apps** (hoặc mục Node.js trong website có sẵn).
2. Chọn **Import Git repository**, kết nối tài khoản GitHub và chọn repo `binbin-marketing`, nhánh `main`.
3. Cấu hình build:
   - Node.js version: **20** trở lên (khuyên dùng bản mới nhất có trong danh sách)
   - Install/build command: `npm install`
   - Start command: `npm start` (entry file: `src/server.js`)
4. Thêm **Environment variables** như bảng dưới.
5. Bấm Deploy. Lần chạy đầu, app tự tạo bảng trong MySQL và tạo tài khoản admin.
6. Gắn tên miền (ví dụ `marketing.binbinhotel.vn`) và bật SSL trong hPanel.

| Biến | Giá trị |
|---|---|
| `NODE_ENV` | `production` |
| `APP_URL` | `https://marketing.binbinhotel.vn` (đúng tên miền của app) |
| `SESSION_SECRET` | Chuỗi ngẫu nhiên dài ít nhất 32 ký tự. Tạo bằng lệnh: `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"` |
| `DB_CLIENT` | `mysql` |
| `DB_HOST` | host MySQL ở bước 2 |
| `DB_PORT` | `3306` |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | thông tin ở bước 2 |
| `ADMIN_EMAIL` | email đăng nhập của quản trị viên đầu tiên |
| `ADMIN_PASSWORD` | mật khẩu tạm, đổi ngay sau lần đăng nhập đầu (mục Tài khoản của tôi) |
| `UPLOAD_DIR` | thư mục lưu ảnh/video **ngoài** thư mục code, ví dụ `/home/<user>/binbin-uploads` (xem đường dẫn `/home/...` trong File Manager) |
| `BREVO_API_KEY`, `BREVO_SENDER_EMAIL`, `BREVO_SENDER_NAME`, `BREVO_WEBHOOK_SECRET` | xem bước 4 |

`PORT` do Hostinger tự cấp, không cần đặt.

> **Vì sao UPLOAD_DIR phải ở ngoài thư mục code?** Mỗi lần deploy lại, Hostinger có thể làm mới thư mục code. Ảnh/video lưu trong đó sẽ mất.

## 4. Cấu hình Brevo (email marketing)

1. Tạo tài khoản tại brevo.com. Gói miễn phí gửi được 300 email/ngày.
2. **Senders, Domains & Dedicated IPs**: thêm và xác thực tên miền `binbinhotel.vn` (thêm bản ghi DNS Brevo đưa ra, trong mục DNS của Hostinger). Xác thực tên miền giúp email không vào thư rác.
3. **SMTP & API → API Keys**: tạo API key, dán vào `BREVO_API_KEY`.
4. `BREVO_SENDER_EMAIL`: email gửi đã xác thực, ví dụ `uudai@binbinhotel.vn`.
5. **Webhook** (để có số liệu mở/nhấp): trong phần cài đặt Transactional → Webhooks, thêm URL
   `https://marketing.binbinhotel.vn/webhooks/brevo/<BREVO_WEBHOOK_SECRET>`
   và chọn các sự kiện: Delivered, Opened, Clicked, Hard bounce, Soft bounce, Unsubscribed.
   `BREVO_WEBHOOK_SECRET` là một chuỗi ngẫu nhiên do bạn tự đặt.

Khi chưa có `BREVO_API_KEY`, app chạy **chế độ thử**: chiến dịch được ghi nhận như đã gửi nhưng không email nào được gửi thật, và trang email luôn hiện cảnh báo.

## 5. Cập nhật về sau

Mỗi lần sửa code: `git push` lên nhánh `main`, rồi bấm **Redeploy** trong hPanel (hoặc bật tự động deploy nếu hPanel có tuỳ chọn này). Bảng mới trong database được tạo tự động khi app khởi động.

## 6. Sao lưu

- Database: hPanel → Databases → phpMyAdmin → Export, hoặc dùng bản sao lưu tự động của Hostinger.
- Ảnh/video: tải thư mục `UPLOAD_DIR` về định kỳ bằng File Manager.
- Danh bạ email: nút **Xuất CSV** trong mục Email marketing → Danh bạ.

## Xử lý sự cố

| Hiện tượng | Cách kiểm tra |
|---|---|
| App không khởi động, log báo `SESSION_SECRET` | Đặt `SESSION_SECRET` dài ít nhất 32 ký tự. |
| Log báo lỗi kết nối MySQL | Kiểm tra lại `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`; user phải được gán quyền vào database. |
| Không đăng nhập được lần đầu | `ADMIN_EMAIL`/`ADMIN_PASSWORD` chỉ dùng để tạo admin khi database chưa có tài khoản nào. Đổi biến sau đó không đổi mật khẩu. |
| Đăng nhập xong bị đẩy ra ngay | Truy cập bằng `https://` (cookie đăng nhập chỉ gửi qua HTTPS trên production). |
| Email không có số liệu mở/nhấp | Kiểm tra URL webhook trong Brevo khớp với `APP_URL` và `BREVO_WEBHOOK_SECRET`. |
| Tải video bị lỗi | Giới hạn 50 MB; nén video trước khi tải lên. |
