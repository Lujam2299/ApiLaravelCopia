<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaAlertaPanico implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $alertId,
        public int $userId,
        public string $userName,
        public string $createdAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('panic-alerts.all')];
    }

    public function broadcastAs(): string
    {
        return 'NuevaAlertaPanico';
    }

    public function broadcastWith(): array
    {
        return [
            'alert' => [
                'id' => $this->alertId,
                'user_id' => $this->userId,
                'user_name' => $this->userName,
                'created_at' => $this->createdAt,
            ],
        ];
    }
}
