<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message->load(['user', 'parent.user']);
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('conversacion.' . $this->message->conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'MensajeEnviado';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->toArray(),
            'conversation_id' => $this->message->conversation_id,
        ];
    }
}
