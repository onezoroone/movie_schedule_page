<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SignalService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.signal.enabled')
            && trim((string) config('services.signal.number')) !== ''
            && $this->recipients() !== [];
    }

    public function sendText(string $message): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Signal chưa được bật hoặc thiếu số gửi/người nhận.',
            );
        }

        return Http::baseUrl(rtrim(
            (string) config('services.signal.base_url'),
            '/',
        ))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->retry([500, 1000, 2000], 0, null, false)
            ->post('/v2/send', [
                'message' => $message,
                'number' => (string) config('services.signal.number'),
                'recipients' => $this->recipients(),
            ])
            ->throw();
    }

    /**
     * @return array<int, string>
     */
    protected function recipients(): array
    {
        return array_values(array_filter(
            (array) config('services.signal.recipients', []),
            fn ($recipient): bool => is_string($recipient)
                && trim($recipient) !== '',
        ));
    }
}
