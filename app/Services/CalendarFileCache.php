<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class CalendarFileCache
{
    public const VERSION = 'laravel-v3';

    /**
     * @param  array<string, mixed>  $day
     */
    public function fingerprint(array $day, string $source = 'asia'): string
    {
        $items = array_map(
            static fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['title'],
                'episode' => $item['episode'],
                'time' => $item['time'],
                'timezone' => $item['timezone'],
                'image' => $item['image'],
            ],
            $day['items'],
        );

        return hash('sha256', json_encode([
            'version' => self::VERSION,
            'source' => $source,
            'date' => $day['date'],
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $date, string $source = 'asia'): ?array
    {
        $path = $this->path($date, $source);

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode(
            Storage::disk('local')->get($path),
            true,
        );

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(
        string $date,
        string $sourceHash,
        array $payload,
        string $source = 'asia',
    ): array {
        $now = now()->toIso8601String();
        $record = [
            'source_hash' => $sourceHash,
            'updated_at' => $now,
            'checked_at' => $now,
            'payload' => $payload,
        ];

        $this->put($date, $record, $source);

        return $record;
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    public function isFresh(?array $record, int $seconds): bool
    {
        if ($record === null || $seconds <= 0) {
            return false;
        }

        $checkedAt = $record['checked_at'] ?? $record['updated_at'] ?? null;

        if (! is_string($checkedAt)) {
            return false;
        }

        try {
            return CarbonImmutable::parse($checkedAt)
                ->addSeconds($seconds)
                ->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function markChecked(array $record): array
    {
        $record['checked_at'] = now()->toIso8601String();
        $date = $record['payload']['selectedDate'] ?? null;
        $source = $record['payload']['source'] ?? 'asia';

        if (is_string($date)) {
            $this->put($date, $record, (string) $source);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function put(
        string $date,
        array $record,
        string $source = 'asia',
    ): void {
        Storage::disk('local')->put(
            $this->path($date, $source),
            json_encode(
                $record,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    protected function path(string $date, string $source): string
    {
        return 'calendar-cache/'.self::VERSION."/{$source}/{$date}.json";
    }
}
