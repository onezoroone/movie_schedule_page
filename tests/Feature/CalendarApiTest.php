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
            ->assertJsonPath('items.0.titleStatus', 'vietnamese');
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
            ->assertJsonPath('items.0.titleStatus', 'tmdb-original');
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
            ->assertJsonPath('items.0.vietnameseTitle', 'A Shop for Killers Season 2');

        $second = $this->getJson('/api/calendar');
        $second->assertOk()
            ->assertHeader('X-Calendar-Cache', 'hit')
            ->assertJsonPath('cache.status', 'hit');

        Http::assertSentCount(1);
        Storage::disk('local')->assertExists(
            'calendar-cache/laravel-v1/'.now('Asia/Ho_Chi_Minh')->format('Y-m-d').'.json',
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
}
