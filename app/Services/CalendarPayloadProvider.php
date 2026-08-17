<?php

namespace App\Services;

use App\Http\Controllers\CalendarController;
use Illuminate\Http\Request;
use RuntimeException;

class CalendarPayloadProvider
{
    public function __construct(
        protected CalendarController $controller,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $date, string $source): array
    {
        $response = $this->controller->show(Request::create(
            '/api/calendar',
            'GET',
            [
                'date' => $date,
                'source' => $source,
            ],
        ));

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(
                "Không tải được lịch {$source} ngày {$date}",
            );
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            throw new RuntimeException('API lịch trả về dữ liệu không hợp lệ.');
        }

        return $payload;
    }
}
