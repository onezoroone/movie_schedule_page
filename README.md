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
