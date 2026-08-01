<?php

namespace Tests\Feature;

use App\Services\MyDramaListService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CalendarApiTest extends TestCase
{
    public function test_it_uses_the_vietnamese_title_from_tmdb(): void
    {
        Storage::fake('local');
        config()->set('services.tmdb.api_key', 'test-key');
        config()->set('services.tmdb.base_url', 'https://api.tmdb.org/3');

        Http::fake([
            '*mydramalist.com/*' => Http::response($this->calendarHtml()),
            '*api.tmdb.org/3/search/tv*' => Http::response([
                'results' => [[
                    'id' => 42,
                    'name' => 'A Shop for Killers',
                    'original_name' => 'A Shop for Killers',
                    'popularity' => 100,
                ]],
            ]),
            '*api.tmdb.org/3/tv/42*' => Http::response([
                'id' => 42,
                'name' => 'A Shop for Killers',
                'original_name' => 'A Shop for Killers',
                'first_air_date' => '2024-01-17',
                'translations' => [
                    'translations' => [[
                        'iso_639_1' => 'vi',
                        'data' => ['name' => 'Cửa Hàng Sát Thủ'],
                    ]],
                ],
                'alternative_titles' => ['results' => []],
            ]),
        ]);

        $this->getJson('/api/calendar')
            ->assertOk()
            ->assertJsonPath(
                'items.0.vietnameseTitle',
                'Cửa Hàng Sát Thủ — Mùa 2',
            )
            ->assertJsonPath('items.0.hasVietnameseTitle', true)
            ->assertJsonPath('items.0.titleStatus', 'vietnamese')
            ->assertJsonPath(
                'items.0.tmdbHref',
                'https://www.themoviedb.org/tv/42?language=vi-VN',
            );
    }

    public function test_it_falls_back_to_the_mydramalist_title_when_tmdb_has_no_vietnamese_title(): void
    {
        Storage::fake('local');
        config()->set('services.tmdb.api_key', 'test-key');
        config()->set('services.tmdb.base_url', 'https://api.tmdb.org/3');

        Http::fake([
            '*mydramalist.com/*' => Http::response($this->calendarHtml()),
            '*api.tmdb.org/3/search/tv*' => Http::response([
                'results' => [[
                    'id' => 42,
                    'name' => 'A Shop for Killers',
                    'original_name' => '연애실험실',
                    'popularity' => 100,
                ]],
            ]),
            '*api.tmdb.org/3/tv/42*' => Http::response([
                'id' => 42,
                'name' => '연애실험실',
                'original_name' => '연애실험실',
                'first_air_date' => '2026-01-01',
                'translations' => ['translations' => []],
                'alternative_titles' => ['results' => []],
            ]),
        ]);

        $this->getJson('/api/calendar')
            ->assertOk()
            ->assertJsonPath(
                'items.0.vietnameseTitle',
                'A Shop for Killers Season 2',
            )
            ->assertJsonPath('items.0.hasVietnameseTitle', false)
            ->assertJsonPath('items.0.titleStatus', 'tmdb-original')
            ->assertJsonPath(
                'items.0.tmdbHref',
                'https://www.themoviedb.org/tv/42?language=vi-VN',
            );
    }

    public function test_it_serves_a_file_cache_when_the_source_is_unchanged(): void
    {
        Storage::fake('local');
        config()->set('services.tmdb.api_key', '');
        $html = $this->calendarHtml();

        Http::fake([
            '*mydramalist.com/*' => Http::response($html),
        ]);

        $first = $this->getJson('/api/calendar');
        $first->assertOk()
            ->assertHeader('X-Calendar-Cache', 'miss')
            ->assertJsonPath('items.0.vietnameseTitle', 'A Shop for Killers Season 2')
            ->assertJsonPath('items.0.tmdbHref', null);

        $second = $this->getJson('/api/calendar');
        $second->assertOk()
            ->assertHeader('X-Calendar-Cache', 'hit')
            ->assertJsonPath('cache.status', 'hit');

        Http::assertSentCount(1);
        Storage::disk('local')->assertExists(
            'calendar-cache/laravel-v2/asia/'.now('Asia/Ho_Chi_Minh')->format('Y-m-d').'.json',
        );
    }

    public function test_it_serves_western_shows_from_tvmaze(): void
    {
        Storage::fake('local');
        config()->set('services.tmdb.api_key', '');

        Http::fake([
            '*api.tvmaze.com/schedule/web*' => Http::response([]),
            '*api.tvmaze.com/schedule*' => Http::response([
                $this->tvmazeEpisode(),
                $this->tvmazeEpisode(99124, 5),
            ]),
        ]);

        $response = $this->getJson('/api/calendar?source=western');

        $response->assertOk()
            ->assertJsonPath('source', 'western')
            ->assertJsonPath('sourceLabel', 'TVmaze · Âu Mỹ')
            ->assertJsonPath('items.0.vietnameseTitle', 'The Last Frontier')
            ->assertJsonPath('items.0.sourceName', 'TVmaze')
            ->assertJsonPath('items.0.sourceId', 81234)
            ->assertJsonPath('items.0.episode', 'Mùa 1 · Tập 4-5')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath(
                'items.0.sourceHref',
                'https://www.tvmaze.com/shows/81234/the-last-frontier',
            );

        Storage::disk('local')->assertExists(
            'calendar-cache/laravel-v2/western/'
                .now('Asia/Ho_Chi_Minh')->format('Y-m-d').'.json',
        );
    }

    public function test_parser_extracts_episode_information(): void
    {
        $days = app(MyDramaListService::class)->parseCalendar(
            $this->calendarHtml(),
        );

        $this->assertCount(1, $days);
        $this->assertSame('Tập 3', $days[0]['items'][0]['episode']);
        $this->assertSame('08:00 PM', $days[0]['items'][0]['time']);
        $this->assertSame('Hàn Quốc', $days[0]['items'][0]['country']);
    }

    protected function calendarHtml(): string
    {
        $date = now('Asia/Ho_Chi_Minh')->format('F j, Y');
        $day = now('Asia/Ho_Chi_Minh')->format('l');

        return <<<HTML
        <div id="episode-calendar-results">
          <div id="d01"><small>{$date}</small><h2>{$day}</h2>
            <div class="col-md-6 col-lg-6 m-b-lg"><div class="el-card">
              <div class="cover-sm">
                <a href="/123-a-shop-for-killers/episode/3" target="_blank">
                  <img src="https://example.com/poster.jpg" alt="A Shop for Killers Season 2">
                </a>
              </div>
              <div class="release-time calendar-popover-source"><i></i>08:00 PM</div>
              <div class="calendar-popover-title">Asia/Seoul</div>
              <a href="/123-a-shop-for-killers/episode/3" target="_blank" class="text-primary _600">A Shop for Killers Season 2</a>
              <div class="text-sm">Episode 3</div>
            </div></div>
          </div>
        </div>
        HTML;
    }

    /**
     * @return array<string, mixed>
     */
    protected function tvmazeEpisode(
        int $episodeId = 99123,
        int $number = 4,
    ): array {
        return [
            'id' => $episodeId,
            'name' => "Episode {$number}",
            'season' => 1,
            'number' => $number,
            'airstamp' => now('Asia/Ho_Chi_Minh')->startOfDay()->toIso8601String(),
            'show' => [
                'id' => 81234,
                'url' => 'https://www.tvmaze.com/shows/81234/the-last-frontier',
                'name' => 'The Last Frontier',
                'type' => 'Scripted',
                'language' => 'English',
                'premiered' => '2025-10-10',
                'rating' => ['average' => 7.2],
                'network' => [
                    'country' => [
                        'name' => 'United States',
                        'code' => 'US',
                    ],
                ],
                'webChannel' => null,
                'image' => [
                    'original' => 'https://static.tvmaze.com/poster.jpg',
                ],
                'summary' => '<p>A western drama.</p>',
            ],
        ];
    }
}
