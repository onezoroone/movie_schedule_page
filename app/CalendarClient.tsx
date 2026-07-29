"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

type CalendarItem = {
  id: string;
  mdlTitle: string;
  vietnameseTitle: string;
  originalTitle: string;
  href: string;
  image: string;
  tmdbImage: string | null;
  episode: string;
  time: string;
  timezone: string;
  country: string;
  contentType: "movie" | "series";
  tmdbId: number | null;
  tmdbType: "movie" | "tv" | null;
  overview: string;
  rating: number | null;
  year: string;
  hasVietnameseTitle: boolean;
  titleStatus: "vietnamese" | "tmdb-original" | "unmatched";
};

type DayOption = {
  date: string;
  label: string;
  dayName: string;
  count: number;
};

type CalendarPayload = {
  selectedDate: string;
  days: DayOption[];
  items: CalendarItem[];
  tmdbEnabled: boolean;
  syncedAt: string;
  timezone: string;
  error?: string;
};

const countryCode: Record<string, string> = {
  "Hàn Quốc": "KR",
  "Trung Quốc": "CN",
  "Nhật Bản": "JP",
  "Thái Lan": "TH",
  Philippines: "PH",
  "Đài Loan": "TW",
  "Hồng Kông": "HK",
  Singapore: "SG",
  "Châu Á": "AS",
};

function SearchIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="11" cy="11" r="6.5" />
      <path d="m16 16 4 4" />
    </svg>
  );
}

function CalendarIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <rect x="3.5" y="5.5" width="17" height="15" rx="2.5" />
      <path d="M8 3.5v4M16 3.5v4M3.5 10h17" />
    </svg>
  );
}

function RefreshIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M20 7v5h-5M4 17v-5h5" />
      <path d="M18.5 9A7.5 7.5 0 0 0 5 7.2M5.5 15A7.5 7.5 0 0 0 19 16.8" />
    </svg>
  );
}

function EmptyState({ query }: { query: string }) {
  return (
    <div className="empty-state">
      <span>Không tìm thấy phim phù hợp</span>
      <p>
        {query
          ? `Thử đổi từ khóa “${query}” hoặc chọn quốc gia khác.`
          : "Ngày này chưa có lịch phát sóng phù hợp với bộ lọc."}
      </p>
    </div>
  );
}

export default function CalendarClient() {
  const [data, setData] = useState<CalendarPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [query, setQuery] = useState("");
  const [country, setCountry] = useState("Tất cả");
  const [onlyVietnamese, setOnlyVietnamese] = useState(false);

  const loadCalendar = useCallback(async (date?: string, bustCache = false) => {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (date) params.set("date", date);
      if (bustCache) params.set("_", Date.now().toString());
      const response = await fetch(`/api/calendar?${params}`, {
        cache: bustCache ? "no-store" : "default",
      });
      const payload = (await response.json()) as CalendarPayload;
      if (!response.ok) throw new Error(payload.error || "Không thể tải dữ liệu");
      setData(payload);
    } catch (loadError) {
      setError(
        loadError instanceof Error
          ? loadError.message
          : "Không thể tải lịch phát sóng",
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadCalendar();
  }, [loadCalendar]);

  const countries = useMemo(() => {
    const values = new Set(data?.items.map((item) => item.country) ?? []);
    return ["Tất cả", ...Array.from(values).sort()];
  }, [data]);

  useEffect(() => {
    if (!countries.includes(country)) setCountry("Tất cả");
  }, [countries, country]);

  const filteredItems = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase("vi");
    return (data?.items ?? []).filter((item) => {
      const matchesQuery =
        !needle ||
        [
          item.vietnameseTitle,
          item.mdlTitle,
          item.originalTitle,
          item.episode,
        ].some((value) => value.toLocaleLowerCase("vi").includes(needle));
      const matchesCountry = country === "Tất cả" || item.country === country;
      const matchesVietnamese = !onlyVietnamese || item.hasVietnameseTitle;
      return matchesQuery && matchesCountry && matchesVietnamese;
    });
  }, [country, data, onlyVietnamese, query]);

  const selectedDay = data?.days.find(
    (day) => day.date === data.selectedDate,
  );
  const vietnameseCount =
    data?.items.filter((item) => item.hasVietnameseTitle).length ?? 0;

  return (
    <main>
      <header className="site-header">
        <a className="brand" href="/" aria-label="Lịch Phim Châu Á">
          <span className="brand-mark">
            <CalendarIcon />
          </span>
          <span>
            <strong>LỊCH PHIM</strong>
            <small>Châu Á</small>
          </span>
        </a>
        <div className="source-note">
          <span className="live-dot" />
          Cập nhật từ MyDramaList
        </div>
      </header>

      <section className="hero">
        <div className="hero-copy">
          <p className="eyebrow">Lịch phát sóng tuần này</p>
          <h1>
            Tối nay,
            <br />
            <em>xem gì?</em>
          </h1>
          <p className="hero-description">
            Lịch phim châu Á theo giờ Việt Nam, với tên tiếng Việt được đối
            chiếu tự động từ TMDB.
          </p>
        </div>
        <div className="hero-meta">
          <div>
            <strong>{data?.items.length ?? "—"}</strong>
            <span>Lịch phát hôm nay</span>
          </div>
          <div>
            <strong>{vietnameseCount}</strong>
            <span>Có tên Việt từ TMDB</span>
          </div>
          <div className="timezone-chip">
            <span>GMT+7</span>
            Asia/Ho_Chi_Minh
          </div>
        </div>
      </section>

      <section className="calendar-shell">
        <div className="week-header">
          <div>
            <p className="section-kicker">Tuần phát sóng</p>
            <h2>
              {selectedDay
                ? `${selectedDay.dayName}, ${selectedDay.label}`
                : "Đang tải lịch…"}
            </h2>
          </div>
          <button
            className="refresh-button"
            type="button"
            onClick={() => void loadCalendar(data?.selectedDate, true)}
            disabled={loading}
          >
            <RefreshIcon />
            Làm mới
          </button>
        </div>

        <nav className="day-tabs" aria-label="Chọn ngày phát sóng">
          {(data?.days ?? Array.from({ length: 7 }, (_, i) => ({
            date: String(i),
            dayName: "Đang tải",
            label: "—",
            count: 0,
          }))).map((day) => (
            <button
              key={day.date}
              type="button"
              className={day.date === data?.selectedDate ? "active" : ""}
              onClick={() => void loadCalendar(day.date)}
              disabled={loading || !data}
            >
              <span>{day.dayName.replace("Thứ ", "T.")}</span>
              <strong>{day.label}</strong>
              <small>{day.count} lịch</small>
            </button>
          ))}
        </nav>

        <div className="toolbar">
          <label className="search-field">
            <SearchIcon />
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Tìm tên phim hoặc số tập…"
            />
          </label>
          <div className="country-filters" aria-label="Lọc theo quốc gia">
            {countries.map((item) => (
              <button
                key={item}
                type="button"
                className={item === country ? "active" : ""}
                onClick={() => setCountry(item)}
              >
                {item}
              </button>
            ))}
          </div>
          <label className="switch-control">
            <input
              type="checkbox"
              checked={onlyVietnamese}
              onChange={(event) => setOnlyVietnamese(event.target.checked)}
            />
            <span />
            Chỉ tên Việt
          </label>
        </div>

        {error ? (
          <div className="error-state">
            <strong>Chưa tải được lịch</strong>
            <p>{error}</p>
            <button type="button" onClick={() => void loadCalendar()}>
              Thử lại
            </button>
          </div>
        ) : loading ? (
          <div className="poster-grid" aria-label="Đang tải lịch">
            {Array.from({ length: 8 }, (_, index) => (
              <div className="movie-card skeleton-card" key={index}>
                <div className="skeleton-poster" />
                <div className="skeleton-line wide" />
                <div className="skeleton-line" />
              </div>
            ))}
          </div>
        ) : filteredItems.length ? (
          <>
            <div className="result-line">
              <span>
                <b>{filteredItems.length}</b> nội dung phù hợp
              </span>
              {!data?.tmdbEnabled && (
                <span className="warning-chip">Chưa cấu hình TMDB</span>
              )}
            </div>
            <div className="poster-grid">
              {filteredItems.map((item) => (
                <article className="movie-card" key={`${item.id}-${item.episode}`}>
                  <a
                    className="poster-wrap"
                    href={item.href}
                    target="_blank"
                    rel="noreferrer"
                    aria-label={`Xem ${item.vietnameseTitle} trên MyDramaList`}
                  >
                    <img
                      src={item.tmdbImage || item.image}
                      alt={`Áp phích ${item.vietnameseTitle}`}
                      loading="lazy"
                    />
                    <span className="country-badge">
                      {countryCode[item.country] ?? "AS"}
                    </span>
                    {item.rating && (
                      <span className="rating-badge">★ {item.rating}</span>
                    )}
                    <span className="poster-overlay">Mở MyDramaList ↗</span>
                  </a>
                  <div className="card-copy">
                    <div className="air-row">
                      <span>{item.episode}</span>
                      <time>{item.time}</time>
                    </div>
                    <h3>
                      <a href={item.href} target="_blank" rel="noreferrer">
                        {item.vietnameseTitle}
                      </a>
                    </h3>
                    <p className="original-title">
                      {item.mdlTitle}
                      {item.year ? ` · ${item.year}` : ""}
                    </p>
                    <div className="card-footer">
                      <span>{item.country}</span>
                      <span data-status={item.titleStatus}>
                        {item.titleStatus === "vietnamese"
                          ? "Tên Việt từ TMDB"
                          : item.titleStatus === "tmdb-original"
                            ? "TMDB chưa có tên Việt"
                            : "Chưa khớp TMDB"}
                      </span>
                    </div>
                  </div>
                </article>
              ))}
            </div>
          </>
        ) : (
          <EmptyState query={query} />
        )}
      </section>

      <footer>
        <div className="footer-brand">
          <span className="brand-mark small">
            <CalendarIcon />
          </span>
          <div>
            <strong>LỊCH PHIM CHÂU Á</strong>
            <p>Đúng giờ Việt · Đúng tên Việt</p>
          </div>
        </div>
        <p className="credits">
          Lịch phát sóng từ{" "}
          <a href="https://mydramalist.com/episode-calendar" target="_blank" rel="noreferrer">
            MyDramaList
          </a>
          . Tên và hình ảnh từ{" "}
          <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">
            TMDB
          </a>
          . Sản phẩm này sử dụng TMDB API nhưng không được TMDB xác nhận hoặc
          chứng thực.
        </p>
      </footer>
    </main>
  );
}
