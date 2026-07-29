<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MyDramaListService
{
    /**
     * @return array<int, array{
     *     date: string,
     *     label: string,
     *     dayName: string,
     *     items: array<int, array<string, mixed>>
     * }>
     */
    public function fetchCalendar(): array
    {
        $response = $this->request()
            ->get((string) config('services.mydramalist.calendar_url'));

        if (! $response->successful()) {
            throw new RuntimeException(
                "MyDramaList trả về lỗi {$response->status()}",
            );
        }

        $days = $this->parseCalendar($response->body());

        if ($days === []) {
            throw new RuntimeException('Không đọc được lịch từ MyDramaList');
        }

        return $days;
    }

    protected function request(): PendingRequest
    {
        return Http::retry(3, 250, throw: false)
            ->connectTimeout(8)
            ->timeout(25)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/128 Safari/537.36',
            ]);
    }

    /**
     * @return array<int, array{
     *     date: string,
     *     label: string,
     *     dayName: string,
     *     items: array<int, array<string, mixed>>
     * }>
     */
    public function parseCalendar(string $html): array
    {
        $resultsStart = strpos($html, 'id="episode-calendar-results"');

        if ($resultsStart === false) {
            return [];
        }

        $results = substr($html, $resultsStart);
        preg_match_all(
            '/<div id="d\d{2}">\s*<small>([^<]+)<\/small>\s*<h2[^>]*>([^<]+)<\/h2>/',
            $results,
            $dayMatches,
            PREG_OFFSET_CAPTURE,
        );

        $weekdays = [
            'Monday' => 'Thứ Hai',
            'Tuesday' => 'Thứ Ba',
            'Wednesday' => 'Thứ Tư',
            'Thursday' => 'Thứ Năm',
            'Friday' => 'Thứ Sáu',
            'Saturday' => 'Thứ Bảy',
            'Sunday' => 'Chủ Nhật',
        ];
        $days = [];
        $dayCount = count($dayMatches[0] ?? []);

        for ($dayIndex = 0; $dayIndex < $dayCount; $dayIndex++) {
            $wholeMatch = $dayMatches[0][$dayIndex][0];
            $matchOffset = $dayMatches[0][$dayIndex][1];
            $sectionStart = $matchOffset + strlen($wholeMatch);
            $sectionEnd = $dayMatches[0][$dayIndex + 1][1] ?? strlen($results);
            $section = substr(
                $results,
                $sectionStart,
                $sectionEnd - $sectionStart,
            );
            $rawDate = trim($dayMatches[1][$dayIndex][0]);
            $englishDay = trim($dayMatches[2][$dayIndex][0]);
            $date = CarbonImmutable::createFromFormat(
                'F j, Y',
                $rawDate,
                'Asia/Ho_Chi_Minh',
            );

            preg_match_all(
                '/<div class="col-md-6 col-lg-6 m-b-lg">\s*<div class="el-card">/',
                $section,
                $cardMatches,
                PREG_OFFSET_CAPTURE,
            );

            $items = [];
            $cardCount = count($cardMatches[0] ?? []);

            for ($cardIndex = 0; $cardIndex < $cardCount; $cardIndex++) {
                $cardStart = $cardMatches[0][$cardIndex][1];
                $cardEnd = $cardMatches[0][$cardIndex + 1][1] ?? strlen($section);
                $card = substr($section, $cardStart, $cardEnd - $cardStart);
                $item = $this->parseCard($card);

                if ($item !== null) {
                    $items[] = $item;
                }
            }

            $days[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('d/m'),
                'dayName' => $weekdays[$englishDay] ?? $englishDay,
                'items' => $items,
            ];
        }

        return $days;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseCard(string $card): ?array
    {
        preg_match('/<img\b[^>]*>/i', $card, $imageMatch);
        $imageTag = $imageMatch[0] ?? '';
        $title = $this->decode($this->attribute($imageTag, 'alt'));
        $image = $this->attribute($imageTag, 'src')
            ?: $this->attribute($imageTag, 'data-src');

        preg_match(
            '/<a href="([^"]+)" target="_blank" class="text-primary _600">/',
            $card,
            $titleLinkMatch,
        );
        preg_match(
            '/<div class="cover-sm">\s*<a href="([^"]+)"/',
            $card,
            $coverLinkMatch,
        );
        $href = $titleLinkMatch[1] ?? $coverLinkMatch[1] ?? '';

        if ($title === '' || $href === '') {
            return null;
        }

        preg_match('/^\/(\d+)/', $href, $idMatch);
        preg_match(
            '/<div class="release-time calendar-popover-source"[^>]*>[\s\S]*?<\/i>\s*([^<]+)\s*<\/div>/',
            $card,
            $timeMatch,
        );
        preg_match(
            '/<div class="calendar-popover-title">([^<]+)<\/div>/',
            $card,
            $timezoneMatch,
        );
        preg_match(
            '/<a href="[^"]+" target="_blank" class="text-primary _600">[\s\S]*?<\/a>\s*<div(?: class="text-sm")?>([\s\S]*?)<\/div>/',
            $card,
            $detailMatch,
        );

        $time = $this->decode($timeMatch[1] ?? '');
        $timezone = $this->decode($timezoneMatch[1] ?? '');
        $episode = $this->decode($detailMatch[1] ?? '') ?: 'Lịch phát sóng';
        $contentType = strtolower($episode) === 'movie' ? 'movie' : 'series';
        $countries = [
            'Asia/Seoul' => 'Hàn Quốc',
            'Asia/Shanghai' => 'Trung Quốc',
            'Asia/Tokyo' => 'Nhật Bản',
            'Asia/Bangkok' => 'Thái Lan',
            'Asia/Manila' => 'Philippines',
            'Asia/Taipei' => 'Đài Loan',
            'Asia/Hong_Kong' => 'Hồng Kông',
            'Asia/Singapore' => 'Singapore',
        ];

        return [
            'id' => $idMatch[1] ?? $href,
            'title' => $title,
            'href' => 'https://mydramalist.com'.preg_replace(
                '#/episode/\d+$#',
                '',
                $href,
            ),
            'image' => $image,
            'episode' => preg_replace(
                ['/^(Episode)/i', '/^Movie$/i'],
                ['Tập', 'Phim lẻ'],
                $episode,
            ),
            'time' => strcasecmp($time, 'ALL DAY') === 0 ? 'Cả ngày' : $time,
            'timezone' => $timezone,
            'country' => $countries[$timezone] ?? 'Châu Á',
            'contentType' => $contentType,
        ];
    }

    protected function attribute(string $tag, string $name): string
    {
        if (
            preg_match(
                '/'.preg_quote($name, '/').'=(?:"([^"]*)"|\'([^\']*)\')/i',
                $tag,
                $match,
            )
        ) {
            return $match[1] !== '' ? $match[1] : ($match[2] ?? '');
        }

        return '';
    }

    protected function decode(string $value): string
    {
        return trim(preg_replace(
            '/\s+/',
            ' ',
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ) ?? '');
    }
}
