<?php

namespace App\Http\Controllers;

use App\Services\CalendarFileCache;
use App\Services\MyDramaListService;
use App\Services\TmdbService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CalendarController extends Controller
{
    public function __construct(
        protected MyDramaListService $myDramaList,
        protected TmdbService $tmdb,
        protected CalendarFileCache $fileCache,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $requestedDate = $request->date('date')?->format('Y-m-d');
        $desiredDate = $requestedDate ?? CarbonImmutable::now(
            'Asia/Ho_Chi_Minh',
        )->format('Y-m-d');
        $forceRefresh = $request->boolean('refresh');
        $checkedAt = now()->toIso8601String();
        $cached = $this->fileCache->read($desiredDate);

        if (
            ! $forceRefresh
            && $this->fileCache->isFresh(
                $cached,
                (int) config('services.mydramalist.check_interval', 900),
            )
        ) {
            return $this->cachedResponse(
                $cached,
                $cached['payload']['days'] ?? [],
                'hit',
                $checkedAt,
            );
        }

        try {
            $days = $this->myDramaList->fetchCalendar();
            $selectedDay = collect($days)->firstWhere('date', $desiredDate)
                ?? $days[0];
            $sourceHash = $this->fileCache->fingerprint($selectedDay);
            $cached = $this->fileCache->read($selectedDay['date']);
            $dayOptions = $this->dayOptions($days);

            if (
                ! $forceRefresh
                && ($cached['source_hash'] ?? null) === $sourceHash
            ) {
                $cached = $this->fileCache->markChecked($cached);

                return $this->cachedResponse(
                    $cached,
                    $dayOptions,
                    'hit',
                    $checkedAt,
                );
            }

            return Cache::store('file')->lock(
                'calendar-refresh:'.$selectedDay['date'],
                180,
            )->block(180, function () use (
                $selectedDay,
                $sourceHash,
                $dayOptions,
                $checkedAt,
                $forceRefresh,
            ): JsonResponse {
                $latest = $this->fileCache->read($selectedDay['date']);

                if (
                    ! $forceRefresh
                    && ($latest['source_hash'] ?? null) === $sourceHash
                ) {
                    $latest = $this->fileCache->markChecked($latest);

                    return $this->cachedResponse(
                        $latest,
                        $dayOptions,
                        'hit',
                        $checkedAt,
                    );
                }

                $items = $this->tmdb->enrich($selectedDay['items']);
                $payload = [
                    'selectedDate' => $selectedDay['date'],
                    'days' => $dayOptions,
                    'items' => $items,
                    'tmdbEnabled' => trim((string) config(
                        'services.tmdb.api_key',
                    )) !== '',
                    'syncedAt' => now()->toIso8601String(),
                    'timezone' => 'Asia/Ho_Chi_Minh',
                ];
                $record = $this->fileCache->write(
                    $selectedDay['date'],
                    $sourceHash,
                    $payload,
                );

                return $this->json([
                    ...$payload,
                    'cache' => [
                        'status' => $latest ? 'refreshed' : 'miss',
                        'updatedAt' => $record['updated_at'],
                        'checkedAt' => $checkedAt,
                    ],
                ]);
            });
        } catch (Throwable $error) {
            $cached = $this->fileCache->read($desiredDate);

            if ($cached) {
                return $this->cachedResponse(
                    $cached,
                    $cached['payload']['days'] ?? [],
                    'stale',
                    $checkedAt,
                );
            }

            report($error);

            return response()->json([
                'error' => 'Không thể tải lịch phát sóng.',
            ], 502);
        }
    }

    /**
     * @param  array<string, mixed>  $cached
     * @param  array<int, array<string, mixed>>  $days
     */
    protected function cachedResponse(
        array $cached,
        array $days,
        string $status,
        string $checkedAt,
    ): JsonResponse {
        return $this->json([
            ...$cached['payload'],
            'days' => $days,
            'cache' => [
                'status' => $status,
                'updatedAt' => $cached['updated_at'] ?? null,
                'checkedAt' => $checkedAt,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return array<int, array<string, mixed>>
     */
    protected function dayOptions(array $days): array
    {
        return array_map(
            static fn (array $day): array => [
                'date' => $day['date'],
                'label' => $day['label'],
                'dayName' => $day['dayName'],
                'count' => count($day['items']),
            ],
            $days,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function json(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, no-store')
            ->header(
                'X-Calendar-Cache',
                $payload['cache']['status'] ?? 'unknown',
            );
    }
}
