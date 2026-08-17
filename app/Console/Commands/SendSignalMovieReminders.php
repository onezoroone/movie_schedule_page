<?php

namespace App\Console\Commands;

use App\Services\CalendarPayloadProvider;
use App\Services\MovieReminderService;
use App\Services\SignalService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SendSignalMovieReminders extends Command
{
    protected $signature = 'calendar:notify-signal
        {--dry-run : Chỉ xem phim đến hạn, không gửi Signal}';

    protected $description = 'Gửi Signal trước giờ phim chiếu một tiếng';

    public function handle(
        CalendarPayloadProvider $calendars,
        MovieReminderService $reminders,
        SignalService $signal,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $signal->isConfigured()) {
            $this->components->info('Signal chưa được bật hoặc chưa đủ cấu hình.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $dates = [
            $now->format('Y-m-d'),
            $now->addDay()->format('Y-m-d'),
        ];
        $payloads = [];

        foreach (['asia', 'western'] as $source) {
            foreach ($dates as $date) {
                try {
                    $payloads[] = $calendars->get($date, $source);
                } catch (Throwable $error) {
                    report($error);
                    $this->components->warn(
                        "Bỏ qua lịch {$source} ngày {$date}: {$error->getMessage()}",
                    );
                }
            }
        }

        try {
            $notifications = $reminders->sendDue($payloads, $now, $dryRun);
        } catch (Throwable $error) {
            report($error);
            $this->components->error(
                'Gửi Signal thất bại: '.$error->getMessage(),
            );

            return self::FAILURE;
        }

        foreach ($notifications as $notification) {
            $airAt = CarbonImmutable::parse($notification['airAt'])
                ->setTimezone('Asia/Ho_Chi_Minh')
                ->format('H:i d/m');
            $this->line("• {$notification['title']} — {$airAt}");
        }

        $action = $dryRun ? 'Tìm thấy' : 'Đã gửi';
        $this->components->info(
            "{$action} ".count($notifications).' thông báo phim.',
        );

        return self::SUCCESS;
    }
}
