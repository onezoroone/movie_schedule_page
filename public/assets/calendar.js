(() => {
    const state = {
        data: null,
        query: "",
        country: "Tất cả",
        vietnameseOnly: false,
        loading: false,
    };

    const elements = {
        totalCount: document.querySelector("#totalCount"),
        vietnameseCount: document.querySelector("#vietnameseCount"),
        selectedDayTitle: document.querySelector("#selectedDayTitle"),
        refreshButton: document.querySelector("#refreshButton"),
        dayTabs: document.querySelector("#dayTabs"),
        searchInput: document.querySelector("#searchInput"),
        countryFilters: document.querySelector("#countryFilters"),
        vietnameseOnly: document.querySelector("#vietnameseOnly"),
        resultCount: document.querySelector("#resultCount"),
        cacheStatus: document.querySelector("#cacheStatus"),
        errorState: document.querySelector("#errorState"),
        errorMessage: document.querySelector("#errorMessage"),
        retryButton: document.querySelector("#retryButton"),
        posterGrid: document.querySelector("#posterGrid"),
    };

    const escapeHtml = (value) => String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");

    const safeUrl = (value, fallback = "#") => {
        try {
            const url = new URL(value, window.location.origin);
            return ["http:", "https:"].includes(url.protocol) ? url.href : fallback;
        } catch {
            return fallback;
        }
    };

    const cacheLabel = {
        hit: "Đọc từ file cache",
        miss: "Vừa tạo file cache",
        refreshed: "Đã cập nhật file cache",
        stale: "Đang dùng cache gần nhất",
    };

    function skeleton() {
        elements.posterGrid.innerHTML = Array.from({ length: 10 }, () => `
            <article class="movie-card skeleton">
                <div class="poster"></div>
                <i></i><i></i>
            </article>
        `).join("");
    }

    async function loadCalendar(date = "", refresh = false) {
        if (state.loading) return;
        state.loading = true;
        elements.refreshButton.disabled = true;
        elements.errorState.hidden = true;
        skeleton();

        const url = new URL(window.calendarConfig.apiUrl, window.location.origin);
        url.searchParams.set("v", "1");
        if (date) url.searchParams.set("date", date);
        if (refresh) url.searchParams.set("refresh", "1");

        try {
            const response = await fetch(url, { cache: "no-store" });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || "Không thể tải dữ liệu");
            state.data = payload;
            state.country = "Tất cả";
            render();
        } catch (error) {
            elements.posterGrid.innerHTML = "";
            elements.errorMessage.textContent = error.message;
            elements.errorState.hidden = false;
            elements.resultCount.textContent = "Không có dữ liệu";
        } finally {
            state.loading = false;
            elements.refreshButton.disabled = false;
        }
    }

    function render() {
        if (!state.data) return;
        const selected = state.data.days.find(
            (day) => day.date === state.data.selectedDate,
        );
        const vietnameseCount = state.data.items.filter(
            (item) => item.hasVietnameseTitle,
        ).length;

        elements.totalCount.textContent = state.data.items.length;
        elements.vietnameseCount.textContent = vietnameseCount;
        elements.selectedDayTitle.textContent = selected
            ? `${selected.dayName}, ${selected.label}`
            : "Lịch phát sóng";
        elements.cacheStatus.textContent =
            cacheLabel[state.data.cache?.status] || "";

        renderDays();
        renderCountries();
        renderCards();
    }

    function renderDays() {
        elements.dayTabs.innerHTML = state.data.days.map((day) => `
            <button
                type="button"
                data-date="${escapeHtml(day.date)}"
                class="${day.date === state.data.selectedDate ? "active" : ""}"
            >
                <span>${escapeHtml(day.dayName.replace("Thứ ", "T."))}</span>
                <strong>${escapeHtml(day.label)}</strong>
                <small>${Number(day.count)} lịch</small>
            </button>
        `).join("");
    }

    function renderCountries() {
        const countries = [
            "Tất cả",
            ...new Set(state.data.items.map((item) => item.country)),
        ].sort((a, b) => a === "Tất cả" ? -1 : a.localeCompare(b, "vi"));

        if (!countries.includes(state.country)) state.country = "Tất cả";

        elements.countryFilters.innerHTML = countries.map((country) => `
            <button
                type="button"
                data-country="${escapeHtml(country)}"
                class="${country === state.country ? "active" : ""}"
            >${escapeHtml(country)}</button>
        `).join("");
    }

    function renderCards() {
        const needle = state.query.trim().toLocaleLowerCase("vi");
        const items = state.data.items.filter((item) => {
            const searchable = [
                item.vietnameseTitle,
                item.mdlTitle,
                item.originalTitle,
                item.episode,
            ].join(" ").toLocaleLowerCase("vi");
            return (!needle || searchable.includes(needle))
                && (state.country === "Tất cả" || item.country === state.country)
                && (!state.vietnameseOnly || item.hasVietnameseTitle);
        });

        elements.resultCount.innerHTML = `<b>${items.length}</b> nội dung phù hợp`;

        if (!items.length) {
            elements.posterGrid.innerHTML = `
                <div class="empty">
                    <strong>Không tìm thấy phim phù hợp</strong>
                    <p>Hãy đổi từ khóa hoặc bộ lọc.</p>
                </div>
            `;
            return;
        }

        elements.posterGrid.innerHTML = items.map((item) => {
            const poster = safeUrl(item.tmdbImage || item.image, "/favicon.svg");
            const link = safeUrl(item.href);
            const status = item.titleStatus === "vietnamese"
                ? "Tên Việt từ TMDB"
                : item.titleStatus === "tmdb-original"
                    ? "TMDB chưa có tên Việt"
                    : "Chưa khớp TMDB";
            return `
                <article class="movie-card">
                    <a class="poster" href="${escapeHtml(link)}" target="_blank" rel="noreferrer">
                        <img src="${escapeHtml(poster)}" alt="Áp phích ${escapeHtml(item.vietnameseTitle)}" loading="lazy">
                        <span>${escapeHtml(item.country)}</span>
                        ${item.rating ? `<b>★ ${Number(item.rating).toFixed(1)}</b>` : ""}
                    </a>
                    <div class="air-row">
                        <span>${escapeHtml(item.episode)}</span>
                        <time>${escapeHtml(item.time)}</time>
                    </div>
                    <h3><a href="${escapeHtml(link)}" target="_blank" rel="noreferrer">${escapeHtml(item.vietnameseTitle)}</a></h3>
                    <p>${escapeHtml(item.mdlTitle)}${item.year ? ` · ${escapeHtml(item.year)}` : ""}</p>
                    <footer><span>${escapeHtml(item.country)}</span><span data-status="${escapeHtml(item.titleStatus)}">${status}</span></footer>
                </article>
            `;
        }).join("");
    }

    elements.dayTabs.addEventListener("click", (event) => {
        const button = event.target.closest("[data-date]");
        if (button) loadCalendar(button.dataset.date);
    });
    elements.countryFilters.addEventListener("click", (event) => {
        const button = event.target.closest("[data-country]");
        if (!button) return;
        state.country = button.dataset.country;
        renderCountries();
        renderCards();
    });
    elements.searchInput.addEventListener("input", (event) => {
        state.query = event.target.value;
        renderCards();
    });
    elements.vietnameseOnly.addEventListener("change", (event) => {
        state.vietnameseOnly = event.target.checked;
        renderCards();
    });
    elements.refreshButton.addEventListener("click", () => {
        loadCalendar(state.data?.selectedDate || "", true);
    });
    elements.retryButton.addEventListener("click", () => loadCalendar());

    loadCalendar();
})();
