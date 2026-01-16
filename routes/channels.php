<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('conversacion.{id}', function ($user, $id) {
    \Log::info('API - Autorización canal conversacion: ' . $id, [
        'user_id' => $user->id,
        'user_model' => get_class($user),
        'user_conversations' => $user->conversations->pluck('id')->toArray(),
        'target_conversation' => $id,
        'has_access' => $user->conversations->pluck('id')->contains($id)
    ]);

    return $user->conversations->pluck('id')->contains($id);
});
Broadcast::channel('realtime-positions.all', function ($user) {
    Log::info('Suscripción al canal realtime-positions.all por usuario:', [$user]);
    return true;
});
/*Broadcast::channel('public-conversacion.{id}', function ($user, $id) {
    return true; // Todos pueden acceder (solo para pruebas)
});*/
