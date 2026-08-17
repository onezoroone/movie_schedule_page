# Lịch Phim Châu Á — Laravel

Ứng dụng Laravel 13 lấy lịch châu Á từ MyDramaList, lịch Âu Mỹ từ TVmaze,
đối chiếu TMDB để lấy tên tiếng Việt và lưu JSON xuống
`storage/app/private/calendar-cache`.

## Chạy local

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Điền `TMDB_API_KEY` trong `.env`, sau đó:

```bash
composer dev
```

Mở <http://localhost:3000>. API lịch nằm tại
<http://localhost:3000/api/calendar>.

- Châu Á: `/api/calendar?source=asia`
- Âu Mỹ: `/api/calendar?source=western`

## Cache

- Lần đầu của mỗi ngày: lấy MyDramaList và TMDB, sau đó ghi file JSON.
- Những lần sau: trả file JSON riêng cho từng nguồn ngay.
- MyDramaList mặc định được kiểm tra lại sau 15 phút
  (`MYDRAMALIST_CHECK_SECONDS=900`); TVmaze sau 30 phút
  (`TVMAZE_CHECK_SECONDS=1800`).
- Khi kiểm tra lại, nếu lịch nguồn không đổi thì giữ nguyên dữ liệu TMDB đã lưu.
- Thêm `?refresh=1` để buộc tạo lại cache.
- Nếu MyDramaList tạm lỗi, API trả file cache gần nhất.

## Thông báo Signal trước giờ chiếu

Ứng dụng hỗ trợ
[`signal-cli-rest-api`](https://github.com/bbernhard/signal-cli-rest-api)
và gửi một thông báo cho từng phim trước giờ chiếu 60 phút. Thông báo đã gửi
được đánh dấu trong file cache nên không bị gửi trùng.

Khởi động Signal REST API:

```bash
docker compose -f docker-compose.signal.yml up -d
```

Mở URL sau và quét QR trong **Signal → Cài đặt → Thiết bị đã liên kết**:

```text
http://localhost:8080/v1/qrcodelink?device_name=calendar
```

Điền cấu hình vào `.env`; các số điện thoại phải dùng định dạng quốc tế:

```dotenv
SIGNAL_ENABLED=true
SIGNAL_API_URL=http://127.0.0.1:8080
SIGNAL_NUMBER=+84123456789
SIGNAL_RECIPIENTS=+84987654321,+84111222333
SIGNAL_LEAD_MINUTES=60
SIGNAL_WINDOW_MINUTES=10
```

Kiểm tra kết nối và xem trước phim đang đến hạn:

```bash
php artisan signal:test
php artisan calendar:notify-signal --dry-run
```

Khi chạy local, mở thêm một terminal cho Laravel scheduler:

```bash
php artisan schedule:work
```

Trên máy chủ production, thêm cron chạy mỗi phút:

```cron
* * * * * cd /duong-dan/calendar && php artisan schedule:run >> /dev/null 2>&1
```
