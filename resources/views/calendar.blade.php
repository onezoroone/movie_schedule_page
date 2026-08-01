<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Lịch phim châu Á và Âu Mỹ theo giờ Việt Nam, đối chiếu tên Việt từ TMDB.">
    <meta name="theme-color" content="#d84132">
    <title>Lịch Phim Châu Á & Âu Mỹ</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/calendar.css?v=5">
    <script>
        window.calendarConfig = {
            apiUrl: @json(route('api.calendar')),
        };
    </script>
    <script src="/assets/calendar.js?v=5" defer></script>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/" aria-label="Lịch Phim Châu Á và Âu Mỹ">
            <span class="brand-mark" aria-hidden="true">日</span>
            <span><strong>LỊCH PHIM</strong><small>Châu Á & Âu Mỹ</small></span>
        </a>
        <div class="source-note"><span></span><b id="sourceNoteText">Cập nhật từ MyDramaList</b></div>
    </header>

    <main>
        <section class="hero">
            <div>
                <p class="eyebrow">Lịch phát sóng tuần này</p>
                <h1>Tối nay,<br><em>xem gì?</em></h1>
                <p class="hero-description">
                    Lịch phim châu Á và Âu Mỹ theo giờ Việt Nam, với tên tiếng Việt
                    được đối chiếu từ TMDB.
                </p>
            </div>
            <div class="hero-stats">
                <div><strong id="totalCount">—</strong><span>Lịch phát hôm nay</span></div>
                <div><strong id="vietnameseCount">—</strong><span>Có tên Việt từ TMDB</span></div>
                <p><b>GMT+7</b> Asia/Ho_Chi_Minh</p>
            </div>
        </section>

        <section class="calendar-shell">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Tuần phát sóng</p>
                    <h2 id="selectedDayTitle">Đang tải lịch…</h2>
                </div>
                <div class="section-actions">
                    <div id="sourceTabs" class="source-tabs" aria-label="Chọn nguồn lịch">
                        <button type="button" data-source="asia" class="active">Châu Á</button>
                        <button type="button" data-source="western">Âu Mỹ</button>
                    </div>
                    <button id="refreshButton" class="refresh-button" type="button">
                        Làm mới
                    </button>
                </div>
            </div>

            <nav id="dayTabs" class="day-tabs" aria-label="Chọn ngày phát sóng"></nav>

            <div class="toolbar">
                <label class="search-field">
                    <span aria-hidden="true">⌕</span>
                    <input id="searchInput" type="search" placeholder="Tìm tên phim hoặc số tập…">
                </label>
                <div id="countryFilters" class="country-filters"></div>
                <label class="switch-control">
                    <input id="vietnameseOnly" type="checkbox">
                    <span aria-hidden="true"></span>
                    Chỉ tên Việt
                </label>
            </div>

            <div class="result-line">
                <span id="resultCount">Đang tải dữ liệu…</span>
                <span id="cacheStatus"></span>
            </div>

            <div id="errorState" class="state-panel" hidden>
                <strong>Chưa tải được lịch</strong>
                <p id="errorMessage"></p>
                <button id="retryButton" type="button">Thử lại</button>
            </div>

            <div id="posterGrid" class="poster-grid" aria-live="polite"></div>
        </section>
    </main>

    <footer>
        <div><strong>LỊCH PHIM CHÂU Á & ÂU MỸ</strong><p>Đúng giờ Việt · Đúng tên Việt</p></div>
        <p>
            Lịch phát sóng từ MyDramaList và
            <a href="https://www.tvmaze.com" target="_blank" rel="noreferrer">TVmaze</a>.
            Tên và hình ảnh bổ sung từ TMDB.
            Sản phẩm này sử dụng TMDB API nhưng không được TMDB xác nhận hoặc chứng thực.
        </p>
    </footer>
</body>
</html>
