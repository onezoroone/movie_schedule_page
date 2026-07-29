import {
  readCalendarCache,
  writeCalendarCache,
} from "../../../db/calendar-cache";

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
  cache: {
    status: "hit" | "miss" | "refreshed" | "stale";
    updatedAt: string | null;
    checkedAt: string;
  };
};

type ParsedItem = {
  id: string;
  title: string;
  href: string;
  image: string;
  episode: string;
  time: string;
  timezone: string;
  country: string;
  contentType: "movie" | "series";
};

type ParsedDay = {
  date: string;
  label: string;
  dayName: string;
  items: ParsedItem[];
};

type TmdbSearchResult = {
  id: number;
  media_type?: "movie" | "tv" | "person";
  title?: string;
  name?: string;
  original_title?: string;
  original_name?: string;
  poster_path?: string | null;
  overview?: string;
  vote_average?: number;
  release_date?: string;
  first_air_date?: string;
  origin_country?: string[];
  popularity?: number;
};

type TmdbTranslation = {
  iso_639_1?: string;
  iso_3166_1?: string;
  data?: {
    name?: string;
    title?: string;
  };
};

type TmdbDetails = TmdbSearchResult & {
  translations?: {
    translations?: TmdbTranslation[];
  };
  alternative_titles?: {
    results?: Array<{
      iso_3166_1?: string;
      title?: string;
    }>;
  };
};

type TmdbMatch = {
  search: TmdbSearchResult;
  details: TmdbDetails;
  mediaType: "movie" | "tv";
  vietnameseTitle: string | null;
};

const MDL_URL =
  "https://mydramalist.com/episode-calendar?view=small&scope=all&tz=Asia%2FSaigon";
const TMDB_API_URL = "https://api.themoviedb.org/3";
const CACHE_DATA_VERSION = "calendar-v4";
const tmdbCache = new Map<string, Promise<TmdbMatch | null>>();

const countryByTimezone: Record<string, string> = {
  "Asia/Seoul": "Hàn Quốc",
  "Asia/Shanghai": "Trung Quốc",
  "Asia/Tokyo": "Nhật Bản",
  "Asia/Bangkok": "Thái Lan",
  "Asia/Manila": "Philippines",
  "Asia/Taipei": "Đài Loan",
  "Asia/Hong_Kong": "Hồng Kông",
  "Asia/Singapore": "Singapore",
};

const weekdayVi: Record<string, string> = {
  Monday: "Thứ Hai",
  Tuesday: "Thứ Ba",
  Wednesday: "Thứ Tư",
  Thursday: "Thứ Năm",
  Friday: "Thứ Sáu",
  Saturday: "Thứ Bảy",
  Sunday: "Chủ Nhật",
};

function decodeHtml(value: string) {
  return value
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&#039;|&#39;/g, "'")
    .replace(/&apos;/g, "'")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&nbsp;/g, " ")
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
    .replace(/\s+/g, " ")
    .trim();
}

function toIsoDate(raw: string) {
  const parsed = new Date(`${raw} 12:00:00 GMT+0700`);
  if (Number.isNaN(parsed.getTime())) return raw;
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Ho_Chi_Minh",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(parsed);
}

function formatDayLabel(raw: string) {
  const parsed = new Date(`${raw} 12:00:00 GMT+0700`);
  if (Number.isNaN(parsed.getTime())) return raw;
  return new Intl.DateTimeFormat("vi-VN", {
    timeZone: "Asia/Ho_Chi_Minh",
    day: "2-digit",
    month: "2-digit",
  }).format(parsed);
}

function getAttribute(tag: string, name: string) {
  return (
    tag.match(new RegExp(`${name}="([^"]*)"`, "i"))?.[1] ??
    tag.match(new RegExp(`${name}='([^']*)'`, "i"))?.[1] ??
    ""
  );
}

function parseCalendar(html: string): ParsedDay[] {
  const resultsStart = html.indexOf('id="episode-calendar-results"');
  if (resultsStart === -1) return [];
  const results = html.slice(resultsStart);
  const dayPattern =
    /<div id="d\d{2}">\s*<small>([^<]+)<\/small>\s*<h2[^>]*>([^<]+)<\/h2>/g;
  const dayMatches = Array.from(results.matchAll(dayPattern));

  return dayMatches.map((match, index) => {
    const start = (match.index ?? 0) + match[0].length;
    const end = dayMatches[index + 1]?.index ?? results.length;
    const section = results.slice(start, end);
    const cardStarts = Array.from(
      section.matchAll(
        /<div class="col-md-6 col-lg-6 m-b-lg">\s*<div class="el-card">/g,
      ),
    );

    const items = cardStarts
      .map((cardStart, cardIndex): ParsedItem | null => {
        const cardEnd =
          cardStarts[cardIndex + 1]?.index ?? section.length;
        const card = section.slice(cardStart.index ?? 0, cardEnd);
        const imgTag = card.match(/<img\b[^>]*>/i)?.[0] ?? "";
        const title = decodeHtml(getAttribute(imgTag, "alt"));
        const image = getAttribute(imgTag, "src") || getAttribute(imgTag, "data-src");
        const hrefMatch = card.match(
          /<a href="([^"]+)" target="_blank" class="text-primary _600">/,
        );
        const href =
          hrefMatch?.[1] ??
          card.match(/<div class="cover-sm">\s*<a href="([^"]+)"/)?.[1] ??
          "";
        const id = href.match(/^\/(\d+)/)?.[1] ?? href;
        const time = decodeHtml(
          card.match(
            /<div class="release-time calendar-popover-source"[^>]*>[\s\S]*?<\/i>\s*([^<]+)\s*<\/div>/,
          )?.[1] ?? "",
        );
        const timezone = decodeHtml(
          card.match(/<div class="calendar-popover-title">([^<]+)<\/div>/)?.[1] ??
            "",
        );
        const detail =
          card.match(
            /<a href="[^"]+" target="_blank" class="text-primary _600">[\s\S]*?<\/a>\s*<div(?: class="text-sm")?>([\s\S]*?)<\/div>/,
          )?.[1] ?? "";
        const episode = decodeHtml(detail) || "Lịch phát sóng";
        const contentType = episode.toLowerCase() === "movie" ? "movie" : "series";

        if (!title || !href) return null;

        return {
          id,
          title,
          href: `https://mydramalist.com${href.replace(/\/episode\/\d+$/, "")}`,
          image,
          episode: episode.replace(/^Episode/i, "Tập").replace(/^Movie$/i, "Phim lẻ"),
          time: time.replace(/^ALL DAY$/i, "Cả ngày"),
          timezone,
          country: countryByTimezone[timezone] ?? "Châu Á",
          contentType,
        };
      })
      .filter((item): item is ParsedItem => item !== null);

    const rawDate = match[1];
    const englishDay = match[2].trim();
    return {
      date: toIsoDate(rawDate),
      label: formatDayLabel(rawDate),
      dayName: weekdayVi[englishDay] ?? englishDay,
      items,
    };
  });
}

function normalizeTitle(value: string) {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/\b(season|part)\s+\d+\b/g, "")
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function titleForTmdbSearch(value: string) {
  return value
    .replace(/\s+(?:season|part)\s+\d+(?:\s+master\s+ver\.?)?$/i, "")
    .replace(/\s+\d+(?:st|nd|rd|th)\s+season$/i, "")
    .trim();
}

function decorateVietnameseTitle(localizedTitle: string, sourceTitle: string) {
  const season = sourceTitle.match(/\s+season\s+(\d+)/i)?.[1];
  if (!season) return localizedTitle;

  const normalizedLocalized = normalizeTitle(localizedTitle);
  if (
    normalizedLocalized.includes(`mua ${season}`) ||
    normalizedLocalized.endsWith(` ${season}`)
  ) {
    return localizedTitle;
  }

  const masterSuffix = /master\s+ver\.?/i.test(sourceTitle)
    ? " · Bản Master"
    : "";
  return `${localizedTitle} — Mùa ${season}${masterSuffix}`;
}

function titleScore(source: string, candidate: TmdbSearchResult) {
  const wanted = normalizeTitle(source);
  const names = [
    candidate.title,
    candidate.name,
    candidate.original_title,
    candidate.original_name,
  ]
    .filter(Boolean)
    .map((name) => normalizeTitle(name as string));

  const exact = names.some((name) => name === wanted) ? 100 : 0;
  const contained = names.some(
    (name) => name.includes(wanted) || wanted.includes(name),
  )
    ? 30
    : 0;
  return exact + contained + Math.min(candidate.popularity ?? 0, 20);
}

async function fetchTmdb<T>(path: string, params: URLSearchParams) {
  const url = `${TMDB_API_URL}${path}?${params}`;
  let response: Response | null = null;

  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      response = await fetch(url, {
        headers: { accept: "application/json" },
      });
      if (response.ok || response.status < 500) break;
    } catch {
      response = null;
    }
  }

  if (!response?.ok) return null;
  return (await response.json()) as T;
}

function getVietnameseTitle(
  details: TmdbDetails,
  mediaType: "movie" | "tv",
) {
  const translation = details.translations?.translations?.find(
    (item) => item.iso_639_1 === "vi",
  );
  const translatedTitle =
    mediaType === "movie"
      ? translation?.data?.title
      : translation?.data?.name;
  if (translatedTitle?.trim()) return translatedTitle.trim();

  const vietnamAlternative = details.alternative_titles?.results?.find(
    (item) => item.iso_3166_1 === "VN" && item.title?.trim(),
  )?.title;
  if (vietnamAlternative?.trim()) return vietnamAlternative.trim();

  const localizedTitle =
    mediaType === "movie" ? details.title : details.name;
  const originalTitle =
    mediaType === "movie" ? details.original_title : details.original_name;
  if (
    localizedTitle?.trim() &&
    normalizeTitle(localizedTitle) !== normalizeTitle(originalTitle ?? "")
  ) {
    return localizedTitle.trim();
  }

  return null;
}

async function searchTmdb(
  title: string,
  contentType: "movie" | "series",
  apiKey: string,
) {
  const cacheKey = `${contentType}:${normalizeTitle(title)}`;
  const cached = tmdbCache.get(cacheKey);
  if (cached) return cached;

  const promise = (async () => {
    const mediaType = contentType === "movie" ? "movie" : "tv";
    const queryTitle = titleForTmdbSearch(title);
    const searchParams = new URLSearchParams({
      api_key: apiKey,
      language: "en-US",
      query: queryTitle,
      include_adult: "false",
    });
    const payload = await fetchTmdb<{ results?: TmdbSearchResult[] }>(
      `/search/${mediaType}`,
      searchParams,
    );
    const candidates = payload?.results ?? [];
    if (!candidates.length) return null;
    const ranked = candidates
      .map((candidate) => ({
        candidate,
        score: titleScore(queryTitle, candidate),
      }))
      .sort((a, b) => b.score - a.score);
    if (ranked[0].score < 30) return null;

    const search = ranked[0].candidate;
    const detailParams = new URLSearchParams({
      api_key: apiKey,
      language: "vi-VN",
      append_to_response: "translations,alternative_titles",
    });
    const details = await fetchTmdb<TmdbDetails>(
      `/${mediaType}/${search.id}`,
      detailParams,
    );
    if (!details) return null;

    return {
      search,
      details,
      mediaType,
      vietnameseTitle: getVietnameseTitle(details, mediaType),
    } satisfies TmdbMatch;
  })()
    .catch(() => null)
    .then((result) => {
      if (!result) tmdbCache.delete(cacheKey);
      return result;
    });

  tmdbCache.set(cacheKey, promise);
  return promise;
}

async function mapWithConcurrency<T, R>(
  items: T[],
  limit: number,
  mapper: (item: T) => Promise<R>,
) {
  const results = new Array<R>(items.length);
  let cursor = 0;
  async function worker() {
    while (cursor < items.length) {
      const index = cursor++;
      results[index] = await mapper(items[index]);
    }
  }
  await Promise.all(
    Array.from({ length: Math.min(limit, items.length) }, () => worker()),
  );
  return results;
}

async function enrichItems(items: ParsedItem[], apiKey: string) {
  return mapWithConcurrency(items, 4, async (item): Promise<CalendarItem> => {
    const match = await searchTmdb(item.title, item.contentType, apiKey);
    const details = match?.details;
    const search = match?.search;
    const localizedTitle = match?.vietnameseTitle
      ? decorateVietnameseTitle(match.vietnameseTitle, item.title)
      : details?.title ||
        details?.name ||
        search?.title ||
        search?.name ||
        item.title;
    const originalTitle =
      details?.original_title ||
      details?.original_name ||
      search?.original_title ||
      search?.original_name ||
      item.title;
    const hasVietnameseTitle = Boolean(match?.vietnameseTitle);
    const date =
      details?.release_date ||
      details?.first_air_date ||
      search?.release_date ||
      search?.first_air_date ||
      "";

    return {
      id: item.id,
      mdlTitle: item.title,
      vietnameseTitle: localizedTitle,
      originalTitle,
      href: item.href,
      image: item.image,
      tmdbImage: (details?.poster_path || search?.poster_path)
        ? `https://image.tmdb.org/t/p/w500${details?.poster_path || search?.poster_path}`
        : null,
      episode: item.episode,
      time: item.time,
      timezone: item.timezone,
      country: item.country,
      contentType: item.contentType,
      tmdbId: details?.id ?? search?.id ?? null,
      tmdbType: match?.mediaType ?? null,
      overview: details?.overview || search?.overview || "",
      rating:
        typeof (details?.vote_average ?? search?.vote_average) === "number" &&
        (details?.vote_average ?? search?.vote_average ?? 0) > 0
          ? Math.round(
              (details?.vote_average ?? search?.vote_average ?? 0) * 10,
            ) / 10
          : null,
      year: date.slice(0, 4),
      hasVietnameseTitle,
      titleStatus: hasVietnameseTitle
        ? "vietnamese"
        : match
          ? "tmdb-original"
          : "unmatched",
    };
  });
}

function currentDateInVietnam() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Ho_Chi_Minh",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function calendarCacheKey(date: string) {
  return `${CACHE_DATA_VERSION}:${date}`;
}

async function createSourceHash(day: ParsedDay) {
  const source = JSON.stringify({
    version: CACHE_DATA_VERSION,
    date: day.date,
    items: day.items.map((item) => ({
      id: item.id,
      title: item.title,
      episode: item.episode,
      time: item.time,
      timezone: item.timezone,
      image: item.image,
    })),
  });
  const digest = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(source),
  );
  return Array.from(new Uint8Array(digest), (byte) =>
    byte.toString(16).padStart(2, "0"),
  ).join("");
}

function dayOptionsFrom(days: ParsedDay[]): DayOption[] {
  return days.map((day) => ({
    date: day.date,
    label: day.label,
    dayName: day.dayName,
    count: day.items.length,
  }));
}

function responseWithCache(payload: CalendarPayload) {
  return Response.json(payload, {
    headers: {
      "Cache-Control": "private, no-store",
      "X-Calendar-Cache": payload.cache.status,
    },
  });
}

export async function GET(request: Request) {
  const searchParams = new URL(request.url).searchParams;
  const requestedDate = searchParams.get("date");
  const forceRefresh = searchParams.get("refresh") === "1";
  const desiredDate = requestedDate || currentDateInVietnam();
  const desiredCacheKey = calendarCacheKey(desiredDate);
  const checkedAt = new Date().toISOString();

  try {
    const response = await fetch(MDL_URL, {
      headers: {
        accept: "text/html,application/xhtml+xml",
        "accept-language": "en-US,en;q=0.9",
        "user-agent":
          "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/128 Safari/537.36",
      },
    });

    if (!response.ok) throw new Error(`MyDramaList trả về lỗi ${response.status}`);

    const days = parseCalendar(await response.text());
    if (!days.length) {
      throw new Error("Không đọc được lịch từ MyDramaList");
    }

    const selectedDay =
      days.find((day) => day.date === requestedDate) ??
      days.find((day) => day.date === currentDateInVietnam()) ??
      days[0];
    const sourceHash = await createSourceHash(selectedDay);
    const cacheKey = calendarCacheKey(selectedDay.date);
    const stored = await readCalendarCache(cacheKey);
    const freshDays = dayOptionsFrom(days);

    if (stored?.sourceHash === sourceHash && !forceRefresh) {
      const cachedPayload = JSON.parse(stored.payload) as CalendarPayload;
      return responseWithCache({
        ...cachedPayload,
        days: freshDays,
        cache: {
          status: "hit",
          updatedAt: stored.updatedAt,
          checkedAt,
        },
      });
    }

    const apiKey = process.env.TMDB_API_KEY?.trim() ?? "";
    const items = apiKey
      ? await enrichItems(selectedDay.items, apiKey)
      : selectedDay.items.map(
          (item): CalendarItem => ({
            id: item.id,
            mdlTitle: item.title,
            vietnameseTitle: item.title,
            originalTitle: item.title,
            href: item.href,
            image: item.image,
            tmdbImage: null,
            episode: item.episode,
            time: item.time,
            timezone: item.timezone,
            country: item.country,
            contentType: item.contentType,
            tmdbId: null,
            tmdbType: null,
            overview: "",
            rating: null,
            year: "",
            hasVietnameseTitle: false,
            titleStatus: "unmatched",
          }),
        );
    const payload: CalendarPayload = {
      selectedDate: selectedDay.date,
      days: freshDays,
      items,
      tmdbEnabled: Boolean(apiKey),
      syncedAt: new Date().toISOString(),
      timezone: "Asia/Ho_Chi_Minh",
      cache: {
        status: stored ? "refreshed" : "miss",
        updatedAt: stored?.updatedAt ?? null,
        checkedAt,
      },
    };

    await writeCalendarCache(cacheKey, sourceHash, JSON.stringify(payload));
    return responseWithCache(payload);
  } catch (error) {
    try {
      const stored = await readCalendarCache(desiredCacheKey);
      if (stored) {
        const cachedPayload = JSON.parse(stored.payload) as CalendarPayload;
        return responseWithCache({
          ...cachedPayload,
          cache: {
            status: "stale",
            updatedAt: stored.updatedAt,
            checkedAt,
          },
        });
      }
    } catch {
      // Fall through to the original source error when durable cache is absent.
    }

    const message =
      error instanceof Error ? error.message : "Không thể tải lịch phát sóng";
    return Response.json({ error: message }, { status: 502 });
  }
}
