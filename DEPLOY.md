# Hướng dẫn deploy: GitHub → Hostinger

Website viết bằng **PHP 8.2 trở lên** và **MySQL**, chạy được trên mọi gói web hosting của Hostinger (kể cả gói Single, Premium). Không cần Node.js. Tên menu trong hPanel có thể khác đôi chút theo thời điểm.

Thư viện PHP (thư mục `vendor/`) đã có sẵn trong repo, nên không cần chạy Composer trên máy chủ.

## 1. Đưa code lên GitHub

Repo: `https://github.com/thangtri/binbinmkt` (nên để **Private**). File `.env`, thư mục `data/` và `uploads/` nằm trong `.gitignore`, nên mật khẩu và dữ liệu không bị đưa lên GitHub.

Bản Node.js cũ vẫn được giữ trong nhánh `nodejs`.

## 2. Chuẩn bị trên Hostinger

1. Mua một gói web hosting và gắn tên miền `binbinmkt.online` vào gói đó (hPanel tự gợi ý khi mua).
2. **Phiên bản PHP**: hPanel → Websites → binbinmkt.online → **Advanced → PHP Configuration** → chọn **PHP 8.3** (tối thiểu 8.2). Các extension cần có (mặc định đã bật): `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`.
3. **SSL**: hPanel → **Security → SSL** → cài SSL miễn phí cho `binbinmkt.online`.

## 3. Tạo database MySQL

1. hPanel → **Databases → MySQL Databases**.
2. Tạo database và user mới, đặt mật khẩu mạnh. Hostinger tự thêm tiền tố, ví dụ `u123456789_binbin`.
3. Ghi lại: tên database, user, mật khẩu. Host thường là `localhost`.

## 4. Đưa code từ GitHub lên Hostinger

1. hPanel → **Advanced → Git**.
2. Vì repo để Private: bấm tạo **SSH key** trong trang Git của hPanel, sao chép khoá, rồi trên GitHub vào repo → **Settings → Deploy keys → Add deploy key**, dán khoá (không cần tick quyền ghi).
3. Trong hPanel, mục **Create a New Repository**:
   - Repository: `git@github.com:thangtri/binbinmkt.git`
   - Branch: `main`
   - Directory: để trống (code vào thẳng `public_html`)
   Thư mục `public_html` phải trống. Nếu có file mặc định (`default.php`, `index.html`), xoá trong **File Manager** trước.
4. Bấm **Create**, rồi **Deploy**. Bật **Auto Deployment** và thêm webhook mà hPanel đưa ra vào GitHub (Settings → Webhooks) để mỗi lần push code, website tự cập nhật.

## 5. Tạo file cấu hình `binbin.env`

File cấu hình đặt ở **thư mục cha của `public_html`**, để không ai truy cập được từ web và không bị ghi đè khi deploy.

1. hPanel → **File Manager**, mở thư mục chứa `public_html` (thường là `/home/u123456789/domains/binbinmkt.online/`).
2. Tạo file mới tên `binbin.env`, dán nội dung theo mẫu `.env.example` trong repo và điền:

| Biến | Giá trị |
|---|---|
| `APP_ENV` | `production` |
| `APP_URL` | `https://binbinmkt.online` |
| `SESSION_SECRET` | Chuỗi ngẫu nhiên dài ít nhất 32 ký tự (gõ bừa chữ và số cũng được, miễn đủ dài và không chia sẻ) |
| `DB_CLIENT` | `mysql` |
| `DB_HOST` | `localhost` |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | thông tin ở bước 3 |
| `ADMIN_USERNAME` | tên đăng nhập của quản trị viên đầu tiên (mặc định `admin`) |
| `ADMIN_EMAIL` | email của quản trị viên đầu tiên |
| `ADMIN_PASSWORD` | mật khẩu tạm, đổi ngay sau lần đăng nhập đầu (mục Tài khoản của tôi) |
| `UPLOAD_DIR` | `../binbin-uploads` (thư mục ảnh/video nằm ngoài `public_html`, không mất khi deploy lại) |
| `BREVO_*` | xem mục 7 |

3. Mở `https://binbinmkt.online`. Lần đầu, website tự tạo bảng trong MySQL và tạo tài khoản admin. Đăng nhập bằng `ADMIN_USERNAME` / `ADMIN_PASSWORD`.

## 6. Việc nền (Cron Job)

Tự đăng bài đúng giờ, gửi email chiến dịch, cập nhật số liệu kênh và phân tích AI chạy qua một cron mỗi phút.

1. hPanel → **Advanced → Cron Jobs** → tạo cron mới.
2. Chọn kiểu **PHP**, lệnh (đổi `u123456789` theo tài khoản của bạn, xem trong File Manager):
   ```
   /home/u123456789/domains/binbinmkt.online/public_html/cron.php
   ```
   Nếu hPanel yêu cầu lệnh đầy đủ: `/usr/bin/php /home/u123456789/domains/binbinmkt.online/public_html/cron.php`
3. Lịch chạy: **mỗi phút** (`* * * * *`).

Nếu không tạo được cron kiểu PHP, đặt `CRON_KEY` (chuỗi ngẫu nhiên ít nhất 16 ký tự) trong `binbin.env` rồi tạo cron gọi link `https://binbinmkt.online/cron/<CRON_KEY>` mỗi phút.

Khi gửi chiến dịch email, website gửi ngay sau khi bấm; phần còn lại (danh sách lớn) được cron gửi tiếp mỗi phút.

## 7. Cấu hình Brevo (email marketing)

1. Tạo tài khoản tại brevo.com. Gói miễn phí gửi được 300 email/ngày.
2. **Senders, Domains & Dedicated IPs**: thêm và xác thực tên miền `binbinhotel.vn` (thêm bản ghi DNS Brevo đưa ra, trong mục DNS của Hostinger). Xác thực tên miền giúp email không vào thư rác.
3. **SMTP & API → API Keys**: tạo API key, dán vào `BREVO_API_KEY`.
4. `BREVO_SENDER_EMAIL`: email gửi đã xác thực, ví dụ `uudai@binbinhotel.vn`.
5. **Webhook** (để có số liệu mở/nhấp): trong phần cài đặt Transactional → Webhooks, thêm URL
   `https://binbinmkt.online/webhooks/brevo/<BREVO_WEBHOOK_SECRET>`
   và chọn các sự kiện: Delivered, Opened, Clicked, Hard bounce, Soft bounce, Unsubscribed.
   `BREVO_WEBHOOK_SECRET` là một chuỗi ngẫu nhiên do bạn tự đặt.

Khi chưa có `BREVO_API_KEY`, app chạy **chế độ thử**: chiến dịch được ghi nhận như đã gửi nhưng không email nào được gửi thật, và trang email luôn hiện cảnh báo.

## 8. Kết nối kênh: tự động lấy số liệu và AI phân tích

Sau khi cấu hình, vào **Kênh → chọn kênh → Kết nối**. Số liệu tự cập nhật mỗi 12 giờ; AI viết nhận xét và đề xuất mỗi tuần (hoặc bấm "Phân tích ngay"). Mỗi kênh cần một ứng dụng nhà phát triển riêng, do chính chủ tài khoản kênh tạo.

Địa chỉ quay về (redirect URI) phải khai báo đúng trong từng ứng dụng:
`https://binbinmkt.online/channels/oauth/<kênh>/callback`, với `<kênh>` là `facebook`, `tiktok` hoặc `zalo`.

### Google Maps (chỉ cần dán link)
1. console.cloud.google.com → tạo project → **APIs & Services → Library** → bật **Places API (New)**. Google yêu cầu gắn tài khoản thanh toán; một lần cập nhật mỗi 12 giờ nằm trong hạn mức miễn phí hằng tháng.
2. **Credentials → Create credentials → API key**. Nên giới hạn key chỉ dùng cho Places API (New).
3. Điền `GOOGLE_PLACES_API_KEY` vào `binbin.env`.
4. Thêm các chi nhánh trong mục **Thông tin khách sạn → Chi nhánh**.
5. Vào **Kênh → Google Maps**, dán link chia sẻ của từng chi nhánh (Google Maps → Chia sẻ → Sao chép đường liên kết).

Lấy được cho từng chi nhánh: điểm đánh giá, số đánh giá, tối đa 5 đánh giá tiêu biểu. Trang Google Maps hiện điểm trung bình cả chuỗi (tính theo số đánh giá) và bảng so sánh các chi nhánh; AI so sánh chi nhánh khi phân tích. Facebook, TikTok, Zalo OA dùng chung cho cả chuỗi. Lượt xem hồ sơ và lượt gọi chỉ có qua Google Business Profile Performance API (cần Google duyệt riêng), chưa nằm trong bản này.

### Facebook Page
1. developers.facebook.com → **My Apps → Create app**. Ở bước chọn trường hợp sử dụng, chọn mục quản lý Page (hoặc **Other → Business** nếu không có).
2. Trong phần tuỳ chỉnh trường hợp sử dụng, thêm quyền `pages_show_list`, `pages_read_engagement`, `read_insights` và `pages_manage_posts` (quyền cuối dùng để tự đăng bài).
3. Mở cài đặt **Facebook Login** (không chọn "Login for Business", loại này cần cấu hình riêng) → **Valid OAuth Redirect URIs** → dán redirect URI ở trên.
4. **App settings → Basic**: điền Privacy Policy URL `https://binbinmkt.online/chinh-sach-bao-mat`, Terms of Service URL `https://binbinmkt.online/dieu-khoan`; sao chép App ID và App Secret.
5. Điền `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET` vào `binbin.env`.

Khi app còn ở chế độ Development, chỉ người có vai trò trong app (admin, developer, tester) mới kết nối được; với Page của chính khách sạn như vậy là đủ, không cần gửi Meta duyệt.

Lấy được: người theo dõi, tiếp cận 28 ngày (nếu Meta còn cung cấp chỉ số này), thích/bình luận/chia sẻ của bài 28 ngày gần nhất.

Tự đăng: bài chữ, album tới 30 ảnh, hoặc một video (Facebook không gộp video với ảnh trong cùng bài, nên khi có video thì chỉ đăng video).

### TikTok
1. developers.tiktok.com → **Manage apps → Create app**, thêm **Login Kit** và **Display API**. Điền Terms of Service URL `https://binbinmkt.online/dieu-khoan` và Privacy Policy URL `https://binbinmkt.online/chinh-sach-bao-mat`.
2. Scope: `user.info.basic`, `user.info.profile`, `user.info.stats`, `video.list`, `video.publish`; khai báo redirect URI. Thêm sản phẩm **Content Posting API** và bật **Direct Post**.
   Để đăng bài ảnh, TikTok tự tải ảnh từ website của bạn: trong mục **URL properties**, xác minh tiền tố `https://binbinmkt.online/m/`.
3. Gửi app để TikTok duyệt (thường vài ngày). Trong lúc chờ, dùng chế độ Sandbox và thêm tài khoản TikTok của khách sạn làm người dùng thử.
4. Điền `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` vào `binbin.env`.

Lấy được: người theo dõi, tổng lượt thích, số video, lượt xem/thích/bình luận/chia sẻ của 20 video mới nhất.

Tự đăng: một video (tối đa 50 MB) hoặc bài ảnh tới 35 ảnh JPG/WebP (TikTok không nhận PNG). **Khi app chưa được TikTok duyệt, bài tự đăng ở chế độ riêng tư (chỉ mình bạn xem)**; sau khi được duyệt mới đăng công khai.

### Zalo OA
1. developers.zalo.me → tạo ứng dụng → **Official Account** → liên kết với OA của khách sạn, khai báo **Callback URL** là redirect URI ở trên.
2. Điền `ZALO_APP_ID`, `ZALO_APP_SECRET` (Khoá bí mật của ứng dụng) vào `binbin.env`.

Lấy được: số người quan tâm OA.

### AI phân tích (Claude)
1. console.anthropic.com → **API Keys → Create Key**, nạp credit vào tài khoản.
2. Điền `ANTHROPIC_API_KEY` vào `binbin.env`. Mặc định dùng model `claude-opus-5-5`; đổi bằng `AI_MODEL` nếu cần.

Mỗi lần phân tích một kênh tốn khoảng 1.000–5.000 đồng tiền API tuỳ lượng dữ liệu; mặc định chạy mỗi tuần một lần cho mỗi kênh đã kết nối.

### Tự đăng bài
- Bài **đã duyệt** và có tick "Tự đăng lên Facebook, TikTok khi đến giờ" sẽ được hệ thống đăng trong vòng 1 phút sau giờ hẹn, lên các kênh đã kết nối (cần Cron Job ở mục 6).
- Google Maps và Zalo OA chưa tự đăng được (cần quyền API riêng): team vẫn đăng tay rồi dán link vào bảng "Trên các kênh". Khi cả 4 kênh đều có link, bài tự chuyển sang "Đã đăng".
- Nếu đã kết nối Facebook/TikTok **trước** khi có tính năng tự đăng, bấm **Kết nối lại** để cấp thêm quyền đăng bài.
- Kênh bị lỗi hiện lý do trong trang bài; sửa xong bấm "Đăng ngay" hoặc "Thử lại".

### Lưu ý
- Token kết nối được mã hoá bằng `ENCRYPTION_KEY` (hoặc `SESSION_SECRET` nếu để trống). Đổi khoá này thì phải bấm Kết nối lại các kênh.
- TikTok và Zalo tự làm mới token; nếu không dùng app quá lâu (Zalo khoảng 3 tháng, TikTok 1 năm), trang kênh sẽ báo "Cần kết nối lại".
- Bài trong lịch đã đánh dấu "Đã đăng" và có link trùng với bài trên Facebook/TikTok sẽ được cập nhật lượt thích, bình luận, chia sẻ (và lượt xem với TikTok) mỗi lần đồng bộ.
- Mục "Nhập số liệu thủ công" vẫn còn ở cuối trang kênh, chỉ dùng khi một kênh chưa kết nối được.

### Quên mật khẩu
Trang đăng nhập có link "Quên mật khẩu?". Email đặt lại gửi qua Brevo, nên cần cấu hình Brevo (mục 7) thì người dùng mới nhận được email. Khi chưa có Brevo, link đặt lại chỉ được ghi vào log máy chủ.

### Ưu đãi, kế hoạch tháng, báo cáo, đối thủ
- **Ưu đãi**: mục Ưu đãi. Mã gốc như COMBO15 sinh ra mã cho từng kênh. Biên tập viên ghi nhận khách dùng mã (lễ tân cần tài khoản vai trò Biên tập).
- **Kế hoạch tháng bằng AI**: trong Lịch đăng bài, bấm "Kế hoạch tháng bằng AI". Cần `ANTHROPIC_API_KEY`. Mỗi lần lập tốn khoảng 5.000–20.000 đồng tiền API tuỳ số bài.
- **Báo cáo**: mục Báo cáo. Nút "In / Lưu PDF" dùng chức năng in của trình duyệt (chọn "Lưu dưới dạng PDF").
- **Đối thủ**: Kênh → Google Maps → So sánh với đối thủ, dán link Google Maps của khách sạn đối thủ. Cập nhật cùng lúc với các chi nhánh, mỗi đối thủ tính thêm một lượt gọi Google Places API.

### Thông tin khách sạn cho AI
Quản trị viên vào mục **Thông tin khách sạn** điền địa chỉ, hotline, Zalo, link đặt phòng, điểm mạnh, giọng văn. AI dùng phần này khi viết bài ("Nhờ AI viết bài" trong trang soạn bài) và khi gợi ý bài cho sự kiện ("Gợi ý bài bằng AI" trong trang Sự kiện).

## 9. Cập nhật về sau

Mỗi lần sửa code: `git push` lên nhánh `main`. Nếu đã bật Auto Deployment, website tự cập nhật; nếu chưa, vào hPanel → Git → bấm **Deploy**. Bảng mới trong database được tạo tự động ở lần truy cập đầu tiên sau khi cập nhật.

## 10. Sao lưu

- Database: hPanel → Databases → phpMyAdmin → Export, hoặc dùng bản sao lưu tự động của Hostinger.
- Ảnh/video: tải thư mục `binbin-uploads` về định kỳ bằng File Manager.
- Danh bạ email: nút **Xuất CSV** trong mục Email marketing → Danh bạ.

## Chạy thử trên máy

Cần PHP 8.2+ (có `pdo_sqlite`). Chép `.env.example` thành `.env`, điền `ADMIN_USERNAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, rồi:

```bash
php -S localhost:8000 index.php
```

Mở http://localhost:8000. Chạy thử dùng SQLite trong thư mục `data/`. Việc nền chạy bằng `php cron.php`.

## Xử lý sự cố

| Hiện tượng | Cách kiểm tra |
|---|---|
| Trang trắng hoặc lỗi 500 | hPanel → Websites → **Error logs**. Kiểm tra đã chọn PHP 8.2 trở lên. |
| Báo lỗi `SESSION_SECRET` | Đặt `SESSION_SECRET` dài ít nhất 32 ký tự trong `binbin.env`. |
| Lỗi kết nối MySQL | Kiểm tra lại `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`; user phải được gán quyền vào database. |
| Website không đọc được cấu hình | `binbin.env` phải nằm cùng cấp với thư mục `public_html` (không phải bên trong). |
| Không đăng nhập được lần đầu | `ADMIN_*` chỉ dùng để tạo admin khi database chưa có tài khoản nào. Đổi biến sau đó không đổi mật khẩu. |
| Đăng nhập xong bị đẩy ra ngay | Truy cập bằng `https://` (cookie đăng nhập chỉ gửi qua HTTPS khi `APP_ENV=production`). |
| Bài không tự đăng đúng giờ | Kiểm tra Cron Job đang chạy mỗi phút (mục 6). |
| Email không có số liệu mở/nhấp | Kiểm tra URL webhook trong Brevo khớp với `APP_URL` và `BREVO_WEBHOOK_SECRET`. |
| Tải video bị lỗi | Giới hạn 50 MB mỗi file; nén video trước khi tải lên. Nếu vẫn lỗi, tăng `upload_max_filesize` và `post_max_size` trong hPanel → PHP Configuration. |
