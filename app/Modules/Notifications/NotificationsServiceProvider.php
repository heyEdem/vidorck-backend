<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Mail\EmailNotificationSender;
use App\Modules\Notifications\Sms\ArkeselSmsProvider;
use App\Modules\Notifications\Sms\LogSmsProvider;
use App\Modules\Notifications\Sms\SmsNotificationSender;
use App\Modules\Notifications\Sms\SmsOnlineGh\SdkSmsOnlineGhClient;
use App\Modules\Notifications\Sms\SmsOnlineGh\SmsOnlineGhClient;
use App\Modules\Notifications\Sms\SmsOnlineGhSmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationDispatcher::class);
        $this->app->bind(SmsOnlineGhClient::class, SdkSmsOnlineGhClient::class);
        $this->app->singleton(SmsProvider::class, static fn (): SmsProvider => match ((string) config('services.sms.driver')) {
            'log' => new LogSmsProvider,
            'arkesel' => new ArkeselSmsProvider,
            'smsonlinegh' => app(SmsOnlineGhSmsProvider::class),
            default => throw new \InvalidArgumentException('Unsupported SMS driver.'),
        });
        $this->app->tag([SmsNotificationSender::class, EmailNotificationSender::class], 'notification.senders');
    }
}
