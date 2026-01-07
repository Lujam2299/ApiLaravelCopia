<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Events\MessageSent;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    public function searchUsers(Request $request)
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string|min:3'
            ]);

            $users = User::where('name', 'like', '%' . $validated['query'] . '%')
                ->orWhere('email', 'like', '%' . $validated['query'] . '%')
                ->orWhere('telefono', 'like', '%' . $validated['query'] . '%')
                ->select('id', 'name', 'email', 'telefono')
                ->limit(10)
                ->get();

            return response()->json($users);
        } catch (\Exception $e) {
            // Log del error (ver storage/logs/laravel.log)
            Log::error("Error en searchUsers: " . $e->getMessage());
            return response()->json(['error' => 'Error interno del servidor'], 500);
        }
    }

    public function startConversation(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        $user = User::find($request->user_id);
        $currentUser = Auth::user();

        // Verificar si ya existe una conversación entre estos usuarios
        $conversation = $currentUser->conversations()
            ->whereHas('users', fn($q) => $q->where('users.id', $user->id))
            ->where('is_group', false)
            ->first();

        if (!$conversation) {
            $conversation = DB::transaction(function () use ($currentUser, $user) {
                $conversation = Conversation::create(['is_group' => false]);
                $conversation->users()->attach([$currentUser->id, $user->id]);
                return $conversation;
            });
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->messages()->with('user')->get()
        ]);
    }
    public function sendMessage(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'body' => 'required|string'
        ]);

        $message = Message::create([
            'conversation_id' => $request->conversation_id,
            'user_id' => Auth::id(),
            'body' => $request->body
        ]);

        // Cargar relaciones necesarias
        $message->load('user');


        // Disparar evento de WebSocket
        broadcast(new MessageSent($message))->toOthers();

        return response()->json([
            'status' => 'success',
            'message' => $message
        ]);
    }


    public function getMessages($conversationId)
    {
        // Verificar acceso a la conversación
        $conversation = Auth::user()->conversations()
            ->where('conversations.id', $conversationId)
            ->firstOrFail();

        // Obtener mensajes ordenados
        $messages = $conversation->messages()
            ->with(['user' => function ($query) {
                $query->select('id', 'name'); // Solo datos básicos del usuario
            }])
            ->orderBy('created_at', 'asc')
            ->get();

        // Marcar mensajes como leídos
        $conversation->messages()
            ->whereNull('read_at')
            ->where('user_id', '!=', Auth::id())
            ->update(['read_at' => now()]);

        return response()->json($messages);
    }

    public function markAsRead(Message $message)
    {
        if ($message->conversation->users->contains(Auth::id())) {
            $message->markAsRead();
            return response()->json(['success' => true]);
        }

        return response()->json(['error' => 'Unauthorized'], 403);
    }
public function getConversations(Request $request)
{
    try {
        $user = $request->user();

        $conversations = $user->conversations()
            ->with(['users:id,name', 'latestMessage:id,conversation_id,body,created_at,user_id'])
            ->orderByDesc(
                Message::select('created_at')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->latest()
                    ->take(1)
            )
            ->get()
            ->map(function ($conversation) use ($user) {
                // Cargar la relación users si no está cargada
                if (!$conversation->relationLoaded('users')) {
                    $conversation->load('users:id,name');
                }

                // Obtener el otro usuario (excluyendo al usuario actual)
                $otherUser = $conversation->users->firstWhere('id', '!=', $user->id);

                // Obtener last_read_at desde la tabla pivote
                $pivotData = DB::table('conversation_user')
                    ->where('conversation_id', $conversation->id)
                    ->where('api_user_id', $user->id)
                    ->first();

                $lastReadAt = $pivotData ? $pivotData->last_read_at : null;

                // Contar mensajes no leídos
                $unreadCount = 0;
                if ($lastReadAt) {
                    $unreadCount = $conversation->messages()
                        ->where('user_id', '!=', $user->id)
                        ->where('created_at', '>', $lastReadAt)
                        ->count();
                } else {
                    $unreadCount = $conversation->messages()
                        ->where('user_id', '!=', $user->id)
                        ->count();
                }

                return [
                    'id' => $conversation->id,
                    'is_group' => $conversation->is_group,
                    'latest_message' => $conversation->latestMessage,
                    'users' => $conversation->users->map(function ($u) {
                        return [
                            'id' => $u->id,
                            'name' => $u->name
                        ];
                    }),
                    'title' => $conversation->is_group ? $conversation->title : ($otherUser ? $otherUser->name : 'Conversación'),
                    'unread_count' => $unreadCount,
                    'last_read_at' => $lastReadAt
                ];
            });

        return response()->json($conversations);
    } catch (\Exception $e) {
        Log::error('Error en getConversations: ' . $e->getMessage() . ' en línea ' . $e->getLine() . ' en archivo ' . $e->getFile());
        return response()->json([
            'message' => 'Error al obtener conversaciones',
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ], 500);
    }
}
}
