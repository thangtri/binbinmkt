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
| `ADMIN_USERNAME` | tên đăng nhập của quản trị viên đầu tiên (mặc định `admin`) |
| `ADMIN_EMAIL` | email của quản trị viên đầu tiên |
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

## 5. Kết nối kênh: tự động lấy số liệu và AI phân tích

Sau khi cấu hình, vào **Kênh → chọn kênh → Kết nối**. Số liệu tự cập nhật mỗi 12 giờ; AI viết nhận xét và đề xuất mỗi tuần (hoặc bấm "Phân tích ngay"). Mỗi kênh cần một ứng dụng nhà phát triển riêng, do chính chủ tài khoản kênh tạo.

Địa chỉ quay về (redirect URI) phải khai báo đúng trong từng ứng dụng:
`https://<tên-miền-app>/channels/oauth/<kênh>/callback`, với `<kênh>` là `facebook`, `tiktok` hoặc `zalo`.

### Google Maps (chỉ cần dán link)
1. console.cloud.google.com → tạo project → **APIs & Services → Library** → bật **Places API (New)**. Google yêu cầu gắn tài khoản thanh toán; một lần cập nhật mỗi 12 giờ nằm trong hạn mức miễn phí hằng tháng.
2. **Credentials → Create credentials → API key**. Nên giới hạn key chỉ dùng cho Places API (New).
3. Đặt `GOOGLE_PLACES_API_KEY`.
4. Thêm các chi nhánh trong mục **Thông tin khách sạn → Chi nhánh**.
5. Vào **Kênh → Google Maps**, dán link chia sẻ của từng chi nhánh (Google Maps → Chia sẻ → Sao chép đường liên kết).

Lấy được cho từng chi nhánh: điểm đánh giá, số đánh giá, tối đa 5 đánh giá tiêu biểu. Trang Google Maps hiện điểm trung bình cả chuỗi (tính theo số đánh giá) và bảng so sánh các chi nhánh; AI so sánh chi nhánh khi phân tích. Facebook, TikTok, Zalo OA dùng chung cho cả chuỗi. Lượt xem hồ sơ và lượt gọi chỉ có qua Google Business Profile Performance API (cần Google duyệt riêng), chưa nằm trong bản này.

### Facebook Page
1. developers.facebook.com → **My Apps → Create app**. Ở bước chọn trường hợp sử dụng, chọn mục quản lý Page (hoặc **Other → Business** nếu không có).
2. Trong phần tuỳ chỉnh trường hợp sử dụng, thêm quyền `pages_show_list`, `pages_read_engagement`, `read_insights` và `pages_manage_posts` (quyền cuối dùng để tự đăng bài).
3. Mở cài đặt **Facebook Login** (không chọn "Login for Business", loại này cần cấu hình riêng) → **Valid OAuth Redirect URIs** → dán redirect URI ở trên.
4. **App settings → Basic**: điền Privacy Policy URL `https://<tên-miền-app>/chinh-sach-bao-mat`, Terms of Service URL `https://<tên-miền-app>/dieu-khoan`; sao chép App ID và App Secret.
5. Đặt `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`.

Khi app còn ở chế độ Development, chỉ người có vai trò trong app (admin, developer, tester) mới kết nối được; với Page của chính khách sạn như vậy là đủ, không cần gửi Meta duyệt.

Lấy được: người theo dõi, tiếp cận 28 ngày (nếu Meta còn cung cấp chỉ số này), thích/bình luận/chia sẻ của bài 28 ngày gần nhất.

Tự đăng: bài chữ, album tới 30 ảnh, hoặc một video (Facebook không gộp video với ảnh trong cùng bài, nên khi có video thì chỉ đăng video).

### TikTok
1. developers.tiktok.com → **Manage apps → Create app**, thêm **Login Kit** và **Display API**. Điền Terms of Service URL `https://<tên-miền-app>/dieu-khoan` và Privacy Policy URL `https://<tên-miền-app>/chinh-sach-bao-mat`.
2. Scope: `user.info.basic`, `user.info.profile`, `user.info.stats`, `video.list`, `video.publish`; khai báo redirect URI. Thêm sản phẩm **Content Posting API** và bật **Direct Post**.
   Để đăng bài ảnh, TikTok tự tải ảnh từ website của bạn: trong mục **URL properties**, xác minh tiền tố `https://<tên-miền-app>/m/`.
3. Gửi app để TikTok duyệt (thường vài ngày). Trong lúc chờ, dùng chế độ Sandbox và thêm tài khoản TikTok của khách sạn làm người dùng thử.
4. Đặt `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`.

Lấy được: người theo dõi, tổng lượt thích, số video, lượt xem/thích/bình luận/chia sẻ của 20 video mới nhất.

Tự đăng: một video (tối đa 50 MB) hoặc bài ảnh tới 35 ảnh JPG/WebP (TikTok không nhận PNG). **Khi app chưa được TikTok duyệt, bài tự đăng ở chế độ riêng tư (chỉ mình bạn xem)**; sau khi được duyệt mới đăng công khai.

### Zalo OA
1. developers.zalo.me → tạo ứng dụng → **Official Account** → liên kết với OA của khách sạn, khai báo **Callback URL** là redirect URI ở trên.
2. Đặt `ZALO_APP_ID`, `ZALO_APP_SECRET` (Khoá bí mật của ứng dụng).

Lấy được: số người quan tâm OA.

### AI phân tích (Claude)
1. console.anthropic.com → **API Keys → Create Key**, nạp credit vào tài khoản.
2. Đặt `ANTHROPIC_API_KEY`. Mặc định dùng model `claude-opus-5-5`; đổi bằng `AI_MODEL` nếu cần.

Mỗi lần phân tích một kênh tốn khoảng 1.000–5.000 đồng tiền API tuỳ lượng dữ liệu; mặc định chạy mỗi tuần một lần cho mỗi kênh đã kết nối.

### Tự đăng bài
- Bài **đã duyệt** và có tick "Tự đăng lên Facebook, TikTok khi đến giờ" sẽ được hệ thống đăng trong vòng 1 phút sau giờ hẹn, lên các kênh đã kết nối.
- Google Maps và Zalo OA chưa tự đăng được (cần quyền API riêng): team vẫn đăng tay rồi dán link vào bảng "Trên các kênh". Khi cả 4 kênh đều có link, bài tự chuyển sang "Đã đăng".
- Nếu đã kết nối Facebook/TikTok **trước** khi có tính năng tự đăng, bấm **Kết nối lại** để cấp thêm quyền đăng bài.
- Kênh bị lỗi hiện lý do trong trang bài; sửa xong bấm "Đăng ngay" hoặc "Thử lại".

### Lưu ý
- Token kết nối được mã hoá bằng `ENCRYPTION_KEY` (hoặc `SESSION_SECRET` nếu để trống). Đổi khoá này thì phải bấm Kết nối lại các kênh.
- TikTok và Zalo tự làm mới token; nếu không dùng app quá lâu (Zalo khoảng 3 tháng, TikTok 1 năm), trang kênh sẽ báo "Cần kết nối lại".
- Bài trong lịch đã đánh dấu "Đã đăng" và có link trùng với bài trên Facebook/TikTok sẽ được cập nhật lượt thích, bình luận, chia sẻ (và lượt xem với TikTok) mỗi lần đồng bộ.
- Mục "Nhập số liệu thủ công" vẫn còn ở cuối trang kênh, chỉ dùng khi một kênh chưa kết nối được.

### Quên mật khẩu
Trang đăng nhập có link "Quên mật khẩu?". Email đặt lại gửi qua Brevo, nên cần cấu hình Brevo (mục 4) thì người dùng mới nhận được email. Khi chưa có Brevo, link đặt lại chỉ được ghi vào log máy chủ.

### Thông tin khách sạn cho AI
Quản trị viên vào mục **Thông tin khách sạn** điền địa chỉ, hotline, Zalo, link đặt phòng, điểm mạnh, giọng văn. AI dùng phần này khi viết bài ("Nhờ AI viết bài" trong trang soạn bài) và khi gợi ý bài cho sự kiện ("Gợi ý bài bằng AI" trong trang Sự kiện).

## 6. Cập nhật về sau

Mỗi lần sửa code: `git push` lên nhánh `main`, rồi bấm **Redeploy** trong hPanel (hoặc bật tự động deploy nếu hPanel có tuỳ chọn này). Bảng mới trong database được tạo tự động khi app khởi động.

## 7. Sao lưu

- Database: hPanel → Databases → phpMyAdmin → Export, hoặc dùng bản sao lưu tự động của Hostinger.
- Ảnh/video: tải thư mục `UPLOAD_DIR` về định kỳ bằng File Manager.
- Danh bạ email: nút **Xuất CSV** trong mục Email marketing → Danh bạ.

## Xử lý sự cố

| Hiện tượng | Cách kiểm tra |
|---|---|
| App không khởi động, log báo `SESSION_SECRET` | Đặt `SESSION_SECRET` dài ít nhất 32 ký tự. |
| Log báo lỗi kết nối MySQL | Kiểm tra lại `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`; user phải được gán quyền vào database. |
| Không đăng nhập được lần đầu | `ADMIN_USERNAME`/`ADMIN_EMAIL`/`ADMIN_PASSWORD` chỉ dùng để tạo admin khi database chưa có tài khoản nào. Đổi biến sau đó không đổi mật khẩu. |
| Đăng nhập xong bị đẩy ra ngay | Truy cập bằng `https://` (cookie đăng nhập chỉ gửi qua HTTPS trên production). |
| Email không có số liệu mở/nhấp | Kiểm tra URL webhook trong Brevo khớp với `APP_URL` và `BREVO_WEBHOOK_SECRET`. |
| Tải video bị lỗi | Giới hạn 50 MB; nén video trước khi tải lên. |
