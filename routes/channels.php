<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('conversacion.{id}', function ($user, $id) {
    return $user->conversations()->whereKey($id)->exists()
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('realtime-positions.all', function ($user) {
    Log::info('Suscripción al canal realtime-positions.all por usuario:', [$user]);
    return true;
});
