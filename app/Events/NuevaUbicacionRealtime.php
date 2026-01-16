<?php

namespace App\Events;

use App\Models\RealtimePosition;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NuevaUbicacionRealtime implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $position;

    public function __construct(RealtimePosition $position)
    {
        $this->position = $position->load('user'); // Cargar la relación user si es necesario
    }

    public function broadcastOn()
    {
        //return new PrivateChannel('realtime-positions.usuario.'.$this->position->user_id);
        return new Channel('realtime-positions.all'); // Canal público
    }

    public function broadcastAs()
    {
        return 'NuevaUbicacionRealtime';
    }
}
