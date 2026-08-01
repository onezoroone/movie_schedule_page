<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TvmazeService
{
    /**
     * @return array<int, array{
     *     date: string,
     *     label: string,
     *     dayName: string,
     *     items: array<int, array<string, mixed>>
     * }>
     */
    public function fetchCalendar(string $desiredDate): array
    {
        $start = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $desiredDate,
            'Asia/Ho_Chi_Minh',
        )->startOfWeek();
        $dates = collect(range(0, 6))
            ->map(fn (int $offset): string => $start
                ->addDays($offset)
                ->format('Y-m-d'))
            ->all();
        $requestDates = collect(range(-1, 6))
            ->map(fn (int $offset): string => $start
                ->addDays($offset)
                ->format('Y-m-d'))
            ->all();
        $responses = Http::pool(function (Pool $pool) use ($requestDates): array {
            $requests = [];

            foreach ($requestDates as $date) {
                $requests[] = $this->request($pool, "broadcast-{$date}")
                    ->get($this->baseUrl().'/schedule', [
                        'country' => 'US',
                        'date' => $date,
                    ]);
                $requests[] = $this->request($pool, "web-{$date}")
                    ->get($this->baseUrl().'/schedule/web', [
                        'date' => $date,
                    ]);
            }

            return $requests;
        });
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
        $episodes = [];

        foreach ($requestDates as $date) {
            $successful = false;

            foreach (["broadcast-{$date}", "web-{$date}"] as $key) {
                $response = $responses[$key] ?? null;

                if ($response instanceof Response && $response->successful()) {
                    $successful = true;
                    $episodes = [...$episodes, ...$response->json()];
                }
            }

            if (! $successful) {
                throw new RuntimeException("TVmaze không tải được lịch ngày {$date}");
            }
        }

        $episodesByDate = collect($episodes)
            ->unique('id')
            ->map(fn (array $episode): ?array => $this->mapEpisode($episode))
            ->filter()
            ->filter(fn (array $item): bool => in_array(
                $item['_localDate'],
                $dates,
                true,
            ))
            ->groupBy('_localDate');

        foreach ($dates as $date) {

            $day = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $date,
                'Asia/Ho_Chi_Minh',
            );
            $items = collect($episodesByDate->get($date, []))
                ->groupBy('sourceId')
                ->map(fn ($showEpisodes): array => $this->mergeEpisodes(
                    $showEpisodes->all(),
                ))
                ->sortBy('time')
                ->values()
                ->all();

            $days[] = [
                'date' => $date,
                'label' => $day->format('d/m'),
                'dayName' => $weekdays[$day->format('l')],
                'items' => $items,
            ];
        }

        return $days;
    }

    protected function request(Pool $pool, string $key): PendingRequest
    {
        return $pool
            ->as($key)
            ->acceptJson()
            ->withUserAgent('LichPhimCalendar/1.0 (Laravel; TVmaze integration)')
            ->connectTimeout(8)
            ->timeout(25)
            ->retry([500, 1000, 2000], 0, null, false);
    }

    /**
     * @param  array<string, mixed>  $episode
     * @return array<string, mixed>|null
     */
    protected function mapEpisode(array $episode): ?array
    {
        $show = $episode['show'] ?? $episode['_embedded']['show'] ?? null;

        if (! is_array($show) || ($show['language'] ?? '') !== 'English') {
            return null;
        }

        if (! in_array(
            $show['type'] ?? '',
            ['Scripted', 'Animation', 'Documentary'],
            true,
        )) {
            return null;
        }

        $country = $show['network']['country']
            ?? $show['webChannel']['country']
            ?? null;
        $countryCode = $country['code'] ?? null;
        $countryName = match ($countryCode) {
            'US' => 'Mỹ',
            'GB' => 'Anh',
            'CA' => 'Canada',
            'AU' => 'Úc',
            'NZ' => 'New Zealand',
            'IE' => 'Ireland',
            default => $country['name'] ?? 'Quốc tế',
        };
        $season = $episode['season'] ?? null;
        $number = $episode['number'] ?? null;
        $episodeLabel = $season && $number
            ? "Mùa {$season} · Tập {$number}"
            : ($number ? "Tập {$number}" : 'Tập mới');
        $airstamp = $episode['airstamp'] ?? null;
        $localAirtime = $airstamp
            ? CarbonImmutable::parse($airstamp)->setTimezone('Asia/Ho_Chi_Minh')
            : null;
        $time = $localAirtime
            ? $localAirtime->format('H:i')
            : ($episode['airtime'] ?? 'Cả ngày');
        $summary = strip_tags((string) (
            $episode['summary'] ?? $show['summary'] ?? ''
        ));

        return [
            'id' => 'tvmaze-'.($episode['id'] ?? $show['id']),
            'title' => $show['name'],
            'href' => $show['url'],
            'image' => $show['image']['original']
                ?? $show['image']['medium']
                ?? '',
            'episode' => $episodeLabel,
            'time' => $time !== '' ? $time : 'Cả ngày',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'country' => $countryName,
            'contentType' => 'series',
            'sourceName' => 'TVmaze',
            'sourceId' => $show['id'],
            'sourceHref' => $show['url'],
            'overview' => html_entity_decode($summary, ENT_QUOTES | ENT_HTML5),
            'rating' => $show['rating']['average'] ?? null,
            'year' => substr((string) ($show['premiered'] ?? ''), 0, 4),
            '_season' => $season,
            '_number' => $number,
            '_localDate' => $localAirtime?->format('Y-m-d')
                ?? ($episode['airdate'] ?? ''),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $episodes
     * @return array<string, mixed>
     */
    protected function mergeEpisodes(array $episodes): array
    {
        $item = $episodes[0];
        $seasons = collect($episodes)
            ->pluck('_season')
            ->filter(fn ($value): bool => is_numeric($value))
            ->unique()
            ->values();
        $numbers = collect($episodes)
            ->pluck('_number')
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->sort()
            ->values();

        if ($seasons->count() === 1 && $numbers->count() > 1) {
            $range = $numbers->count() === $numbers->last() - $numbers->first() + 1
                ? $numbers->first().'-'.$numbers->last()
                : $numbers->implode(', ');
            $item['episode'] = "Mùa {$seasons->first()} · Tập {$range}";
        }

        unset($item['_season'], $item['_number'], $item['_localDate']);

        return $item;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.tvmaze.base_url'), '/');
    }
}
