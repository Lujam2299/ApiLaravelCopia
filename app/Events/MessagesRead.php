<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $conversationId, public int $readerId) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('conversacion.' . $this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'MessagesRead';
    }
}
