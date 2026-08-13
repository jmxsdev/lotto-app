<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sends notifications to Telegram via the Bot API.
 * Silently no-ops when the token/chat id are not configured.
 */
class TelegramChannel
{
    public function send($notifiable, Notification $notification): void
    {
        $token = config('scrapers.telegram.bot_token');
        $chatId = config('scrapers.telegram.chat_id');

        if (! $token || ! $chatId) {
            return;
        }

        $message = method_exists($notification, 'toTelegram')
            ? $notification->toTelegram($notifiable)
            : $notification->toArray($notifiable);

        Http::timeout(10)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);
    }
}
