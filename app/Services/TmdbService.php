<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TmdbService
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $items): array
    {
        $apiKey = trim((string) config('services.tmdb.api_key'));

        if ($apiKey === '') {
            return array_map(
                fn (array $item): array => $this->withoutMatch($item),
                $items,
            );
        }

        $matches = $this->searchMatches($items, $apiKey);
        $details = $this->fetchDetails($matches, $apiKey);

        return array_map(function (array $item, int $index) use (
            $matches,
            $details,
        ): array {
            $match = $matches[$index] ?? null;
            $detail = $details[$index] ?? null;

            if ($match === null || $detail === null) {
                return $this->withoutMatch($item);
            }

            $mediaType = $match['mediaType'];
            $vietnameseTitle = $this->vietnameseTitle($detail, $mediaType);
            $localizedTitle = $vietnameseTitle
                ? $this->decorateSeason($vietnameseTitle, $item['title'])
                : $item['title'];
            $originalTitle = $detail[
                $mediaType === 'movie' ? 'original_title' : 'original_name'
            ] ?? $item['title'];
            $date = $detail[
                $mediaType === 'movie' ? 'release_date' : 'first_air_date'
            ] ?? '';
            $poster = $detail['poster_path']
                ?? $match['result']['poster_path']
                ?? null;
            $rating = (float) ($detail['vote_average'] ?? 0);
            $sourceTitle = $item['title'];
            $tmdbId = $detail['id'] ?? $match['result']['id'];
            $tmdbHref = sprintf(
                'https://www.themoviedb.org/%s/%s?language=vi-VN',
                $mediaType,
                $tmdbId,
            );

            return [
                'id' => $item['id'],
                'mdlTitle' => $sourceTitle,
                'sourceTitle' => $sourceTitle,
                'sourceName' => $item['sourceName'] ?? 'MyDramaList',
                'sourceId' => $item['sourceId'] ?? $item['id'],
                'sourceHref' => $item['sourceHref'] ?? $item['href'],
                'vietnameseTitle' => $localizedTitle,
                'originalTitle' => $originalTitle,
                'href' => $tmdbHref,
                'image' => $item['image'],
                'tmdbImage' => $poster
                    ? rtrim((string) config('services.tmdb.image_url'), '/').$poster
                    : null,
                'episode' => $item['episode'],
                'time' => $item['time'],
                'timezone' => $item['timezone'],
                'country' => $item['country'],
                'contentType' => $item['contentType'],
                'tmdbId' => $tmdbId,
                'tmdbType' => $mediaType,
                'tmdbHref' => $tmdbHref,
                'overview' => ($detail['overview'] ?? '')
                    ?: ($item['overview'] ?? ''),
                'rating' => $rating > 0
                    ? round($rating, 1)
                    : ($item['rating'] ?? null),
                'year' => substr($date, 0, 4) ?: ($item['year'] ?? ''),
                'hasVietnameseTitle' => $vietnameseTitle !== null,
                'titleStatus' => $vietnameseTitle
                    ? 'vietnamese'
                    : 'tmdb-original',
            ];
        }, $items, array_keys($items));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function searchMatches(array $items, string $apiKey): array
    {
        $matches = [];

        foreach (array_chunk($items, 8, true) as $chunk) {
            try {
                $responses = Http::pool(
                    function (Pool $pool) use ($chunk, $apiKey): array {
                        $requests = [];

                        foreach ($chunk as $index => $item) {
                            $mediaType = $item['contentType'] === 'movie'
                                ? 'movie'
                                : 'tv';
                            $requests[] = $pool
                                ->as((string) $index)
                                ->acceptJson()
                                ->connectTimeout(6)
                                ->timeout(12)
                                ->get(
                                    $this->baseUrl()."/search/{$mediaType}",
                                    [
                                        'api_key' => $apiKey,
                                        'language' => 'en-US',
                                        'query' => $this->searchTitle($item['title']),
                                        'include_adult' => 'false',
                                    ],
                                );
                        }

                        return $requests;
                    },
                );
            } catch (Throwable) {
                continue;
            }

            foreach ($chunk as $index => $item) {
                $response = $responses[(string) $index] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    continue;
                }

                $mediaType = $item['contentType'] === 'movie' ? 'movie' : 'tv';
                $queryTitle = $this->searchTitle($item['title']);
                $ranked = collect($response->json('results', []))
                    ->map(fn (array $candidate): array => [
                        'candidate' => $candidate,
                        'score' => $this->titleScore($queryTitle, $candidate),
                    ])
                    ->sortByDesc('score')
                    ->values();
                $best = $ranked->first();

                if (! $best || $best['score'] < 30) {
                    continue;
                }

                $matches[$index] = [
                    'mediaType' => $mediaType,
                    'result' => $best['candidate'],
                ];
            }
        }

        return $matches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    protected function fetchDetails(array $matches, string $apiKey): array
    {
        $details = [];

        foreach (array_chunk($matches, 8, true) as $chunk) {
            try {
                $responses = Http::pool(
                    function (Pool $pool) use ($chunk, $apiKey): array {
                        $requests = [];

                        foreach ($chunk as $index => $match) {
                            $requests[] = $pool
                                ->as((string) $index)
                                ->acceptJson()
                                ->connectTimeout(6)
                                ->timeout(12)
                                ->get(
                                    $this->baseUrl()
                                        ."/{$match['mediaType']}/{$match['result']['id']}",
                                    [
                                        'api_key' => $apiKey,
                                        'language' => 'vi-VN',
                                        'append_to_response' => 'translations,alternative_titles',
                                    ],
                                );
                        }

                        return $requests;
                    },
                );
            } catch (Throwable) {
                continue;
            }

            foreach ($chunk as $index => $match) {
                $response = $responses[(string) $index] ?? null;

                if ($response instanceof Response && $response->successful()) {
                    $details[$index] = $response->json();
                }
            }
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    protected function vietnameseTitle(
        array $detail,
        string $mediaType,
    ): ?string {
        $translation = collect(
            $detail['translations']['translations'] ?? [],
        )->firstWhere('iso_639_1', 'vi');
        $translated = $translation['data'][
            $mediaType === 'movie' ? 'title' : 'name'
        ] ?? null;

        if (is_string($translated) && trim($translated) !== '') {
            return trim($translated);
        }

        $alternative = collect(
            $detail['alternative_titles']['results'] ?? [],
        )->first(
            fn (array $item): bool => ($item['iso_3166_1'] ?? '') === 'VN'
                && trim((string) ($item['title'] ?? '')) !== '',
        );

        if ($alternative) {
            return trim($alternative['title']);
        }

        $localized = trim((string) (
            $detail[$mediaType === 'movie' ? 'title' : 'name'] ?? ''
        ));
        $original = trim((string) (
            $detail[
                $mediaType === 'movie' ? 'original_title' : 'original_name'
            ] ?? ''
        ));

        return $localized !== ''
            && $this->normalize($localized) !== $this->normalize($original)
                ? $localized
                : null;
    }

    protected function decorateSeason(
        string $localizedTitle,
        string $sourceTitle,
    ): string {
        if (! preg_match('/\s+season\s+(\d+)/i', $sourceTitle, $match)) {
            return $localizedTitle;
        }

        $season = $match[1];
        $normalized = $this->normalize($localizedTitle);

        if (
            str_contains($normalized, "mua {$season}")
            || str_ends_with($normalized, " {$season}")
        ) {
            return $localizedTitle;
        }

        $master = preg_match('/master\s+ver\.?/i', $sourceTitle)
            ? ' · Bản Master'
            : '';

        return "{$localizedTitle} — Mùa {$season}{$master}";
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function titleScore(string $source, array $candidate): float
    {
        $wanted = $this->normalize($source);
        $names = array_filter([
            $candidate['title'] ?? null,
            $candidate['name'] ?? null,
            $candidate['original_title'] ?? null,
            $candidate['original_name'] ?? null,
        ]);
        $normalizedNames = array_map(
            fn (string $name): string => $this->normalize($name),
            $names,
        );
        $exact = in_array($wanted, $normalizedNames, true) ? 100 : 0;
        $contained = collect($normalizedNames)->contains(
            fn (string $name): bool => str_contains($name, $wanted)
                || str_contains($wanted, $name),
        ) ? 30 : 0;

        return $exact
            + $contained
            + min((float) ($candidate['popularity'] ?? 0), 20);
    }

    protected function searchTitle(string $title): string
    {
        return trim((string) preg_replace(
            [
                '/\s+(?:season|part)\s+\d+(?:\s+master\s+ver\.?)?$/i',
                '/\s+\d+(?:st|nd|rd|th)\s+season$/i',
            ],
            '',
            $title,
        ));
    }

    protected function normalize(string $title): string
    {
        return Str::of($title)
            ->ascii()
            ->lower()
            ->replaceMatches('/\b(?:season|part)\s+\d+\b/', '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function withoutMatch(array $item): array
    {
        return [
            'id' => $item['id'],
            'mdlTitle' => $item['title'],
            'sourceTitle' => $item['title'],
            'sourceName' => $item['sourceName'] ?? 'MyDramaList',
            'sourceId' => $item['sourceId'] ?? $item['id'],
            'sourceHref' => $item['sourceHref'] ?? $item['href'],
            'vietnameseTitle' => $item['title'],
            'originalTitle' => $item['title'],
            'href' => $item['href'],
            'image' => $item['image'],
            'tmdbImage' => null,
            'episode' => $item['episode'],
            'time' => $item['time'],
            'timezone' => $item['timezone'],
            'country' => $item['country'],
            'contentType' => $item['contentType'],
            'tmdbId' => null,
            'tmdbType' => null,
            'tmdbHref' => null,
            'overview' => $item['overview'] ?? '',
            'rating' => $item['rating'] ?? null,
            'year' => $item['year'] ?? '',
            'hasVietnameseTitle' => false,
            'titleStatus' => 'unmatched',
        ];
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.tmdb.base_url'), '/');
    }
}
