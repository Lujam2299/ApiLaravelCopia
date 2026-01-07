<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    // Verificar si el usuario pertenece a la conversación
    $conversation = \App\Models\Conversation::find($conversationId);

    if (!$conversation) {
        return false;
    }

    // Verificar si el usuario es parte de la conversación
    return $conversation->users()->where('user_id', $user->id)->exists();
});
