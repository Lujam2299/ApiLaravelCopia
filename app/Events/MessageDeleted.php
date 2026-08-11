<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $conversationId, public int $messageId) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('conversacion.'.$this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'MessageDeleted';
    }
}
