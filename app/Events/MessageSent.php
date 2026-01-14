<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // ← Cambiado
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow // ← Cambiado
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message->load('user');
    }

    public function broadcastOn()
    {
        return new PrivateChannel('conversacion.' . $this->message->conversation_id);
    }

    public function broadcastAs()
    {
        return 'MensajeEnviado';
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->message->id,
            'body' => $this->message->body,
            'user_id' => $this->message->user_id,
            'conversation_id' => $this->message->conversation_id,
            'created_at' => $this->message->created_at->toISOString(),
            'user' => [
                'id' => $this->message->user->id,
                'name' => $this->message->user->name,
            ],
            'read_at' => $this->message->read_at,
        ];
    }
}
/*public function broadcastWith()
    {
        // Envía solo los datos básicos que necesitas
        return [
            'id' => $this->message->id,
            'body' => $this->message->body,
            'user_id' => $this->message->user_id,
            'conversation_id' => $this->message->conversation_id,
            'created_at' => $this->message->created_at,
            'user' => [
                'id' => $this->message->user->id,
                'name' => $this->message->user->name,
            ],
            'read_at' => $this->message->read_at,
        ];
    }*/
