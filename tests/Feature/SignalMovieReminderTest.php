<?php

namespace Tests\Feature;

use App\Services\MovieReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalMovieReminderTest extends TestCase
{
    public function test_it_sends_one_signal_reminder_per_movie_and_deduplicates_it(): void
    {
        config()->set([
            'services.signal.enabled' => true,
            'services.signal.base_url' => 'http://signal-api:8080',
            'services.signal.number' => '+84111111111',
            'services.signal.recipients' => [
                '+84222222222',
                '+84333333333',
            ],
            'services.signal.lead_minutes' => 60,
            'services.signal.window_minutes' => 10,
            'services.signal.cache_store' => 'array',
        ]);
        Cache::store('array')->clear();
        Http::fake([
            'http://signal-api:8080/v2/send' => Http::response([
                'timestamp' => 123456789,
            ], 201),
        ]);
        $payloads = [[
            'selectedDate' => '2026-08-17',
            'source' => 'western',
            'sourceLabel' => 'TVmaze · Âu Mỹ',
            'items' => [
                $this->movie('western-1', '21:00', 'Phim Âu Mỹ'),
                $this->movie('western-2', '09:00 PM', 'Phim giờ 12 tiếng'),
            ],
        ]];
        $now = CarbonImmutable::parse(
            '2026-08-17 20:02:00',
            'Asia/Ho_Chi_Minh',
        );
        $service = app(MovieReminderService::class);

        $first = $service->sendDue($payloads, $now);
        $second = $service->sendDue($payloads, $now);

        $this->assertCount(2, $first);
        $this->assertCount(0, $second);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://signal-api:8080/v2/send'
                && $request['number'] === '+84111111111'
                && $request['recipients'] === [
                    '+84222222222',
                    '+84333333333',
                ]
                && str_contains($request['message'], 'SẮP CHIẾU SAU 1 GIỜ')
                && str_contains($request['message'], 'TMDB #12345')
                && str_contains(
                    $request['message'],
                    'https://www.themoviedb.org/tv/12345',
                );
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function movie(string $id, string $time, string $title): array
    {
        return [
            'id' => $id,
            'vietnameseTitle' => $title,
            'sourceTitle' => $title,
            'episode' => 'Mùa 1 · Tập 5',
            'time' => $time,
            'country' => 'Mỹ',
            'tmdbId' => 12345,
            'tmdbHref' => 'https://www.themoviedb.org/tv/12345',
        ];
    }
}
