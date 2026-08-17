<?php

namespace App\Console\Commands;

use App\Services\SignalService;
use Illuminate\Console\Command;
use Throwable;

class TestSignalConnection extends Command
{
    protected $signature = 'signal:test
        {--message=Kiểm tra thông báo lịch phim từ Laravel : Nội dung tin nhắn}';

    protected $description = 'Gửi một tin nhắn Signal kiểm tra';

    public function handle(SignalService $signal): int
    {
        try {
            $signal->sendText((string) $this->option('message'));
        } catch (Throwable $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Đã gửi tin nhắn Signal kiểm tra.');

        return self::SUCCESS;
    }
}
