<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ScrapeFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $juego,
        public string $fecha,
        public string $razon,
    ) {}

    public function via($notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram($notifiable): string
    {
        return "🔴 Scraper falló: {$this->juego} ({$this->fecha})\nMotivo: {$this->razon}";
    }
}
