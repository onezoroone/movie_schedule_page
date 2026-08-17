<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MovieReminderService
{
    public function __construct(
        protected SignalService $signal,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array{title: string, airAt: string, message: string}>
     */
    public function sendDue(
        array $payloads,
        ?CarbonImmutable $now = null,
        bool $dryRun = false,
    ): array {
        $now ??= CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $leadMinutes = max(1, (int) config(
            'services.signal.lead_minutes',
            60,
        ));
        $windowMinutes = max(1, (int) config(
            'services.signal.window_minutes',
            10,
        ));
        $sent = [];

        foreach ($payloads as $payload) {
            $date = (string) ($payload['selectedDate'] ?? '');
            $source = (string) ($payload['source'] ?? 'asia');

            foreach ((array) ($payload['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $airAt = $this->parseAirTime(
                    $date,
                    (string) ($item['time'] ?? ''),
                );

                if ($airAt === null) {
                    continue;
                }

                $notifyAt = $airAt->subMinutes($leadMinutes);

                if (
                    $now->lessThan($notifyAt)
                    || ! $now->lessThan($notifyAt->addMinutes($windowMinutes))
                ) {
                    continue;
                }

                $key = $this->notificationKey($source, $item, $airAt);
                $cache = Cache::store((string) config(
                    'services.signal.cache_store',
                    'file',
                ));

                if (! $dryRun && $cache->has($key)) {
                    continue;
                }

                $message = $this->message($payload, $item, $airAt);

                if (! $dryRun) {
                    $this->signal->sendText($message);
                    $cache->put($key, true, now()->addDays(3));
                }

                $sent[] = [
                    'title' => (string) (
                        $item['vietnameseTitle']
                        ?? $item['sourceTitle']
                        ?? 'Phim sắp chiếu'
                    ),
                    'airAt' => $airAt->toIso8601String(),
                    'message' => $message,
                ];
            }
        }

        return $sent;
    }

    protected function parseAirTime(
        string $date,
        string $time,
    ): ?CarbonImmutable {
        $time = trim($time);

        if ($date === '' || $time === '' || strcasecmp($time, 'Cả ngày') === 0) {
            return null;
        }

        try {
            if (preg_match('/^\d{1,2}:\d{2}\s*(AM|PM)$/i', $time)) {
                return CarbonImmutable::createFromFormat(
                    'Y-m-d h:i A',
                    $date.' '.strtoupper($time),
                    'Asia/Ho_Chi_Minh',
                );
            }

            if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                return CarbonImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $date.' '.$time,
                    'Asia/Ho_Chi_Minh',
                );
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function notificationKey(
        string $source,
        array $item,
        CarbonImmutable $airAt,
    ): string {
        return 'signal-movie-reminder:'.hash('sha256', implode('|', [
            $source,
            (string) ($item['id'] ?? $item['tmdbId'] ?? 'unknown'),
            $airAt->toIso8601String(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $item
     */
    protected function message(
        array $payload,
        array $item,
        CarbonImmutable $airAt,
    ): string {
        $title = (string) (
            $item['vietnameseTitle']
            ?? $item['sourceTitle']
            ?? 'Phim sắp chiếu'
        );
        $url = (string) ($item['tmdbHref'] ?? $item['href'] ?? '');
        $lines = [
            '🎬 SẮP CHIẾU SAU 1 GIỜ',
            '',
            $title,
            '📺 '.($item['episode'] ?? 'Tập mới'),
            '🕒 '.$airAt->format('H:i · d/m/Y').' (GMT+7)',
            '🌏 '.($item['country'] ?? 'Quốc tế'),
            'Nguồn: '.($payload['sourceLabel'] ?? 'Lịch phim'),
        ];

        if (($item['tmdbId'] ?? null) !== null) {
            $lines[] = 'TMDB #'.$item['tmdbId'];
        }

        if ($url !== '') {
            $lines[] = '🔗 '.$url;
        }

        return implode("\n", $lines);
    }
}
