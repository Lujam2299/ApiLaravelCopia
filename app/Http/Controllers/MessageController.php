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
    $query = $request->input('query');

    $users = User::where('name', 'LIKE', "%{$query}%")
                ->orWhere('email', 'LIKE', "%{$query}%")
                ->with('documentacionAltas:arch_foto,id,solicitud_id') // Cargar la relación
                ->limit(10)
                ->get()
                ->map(function ($user) {
                    $photoUrl = null;

                    if ($user->documentacionAltas && $user->documentacionAltas->arch_foto) {
                        $relativePath = str_replace(['storage/', 'storage\\'], '', $user->documentacionAltas->arch_foto);
                        $publicPath = storage_path('app/public/' . $relativePath);

                        if (file_exists($publicPath)) {
                            $photoUrl = asset('storage/' . $relativePath);
                        }
                    }

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'photo_url' => $photoUrl
                    ];
                });

    return response()->json($users);
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

        \Log::info('Message created for broadcast', [
            'message_id' => $message->id,
            'user_id' => $message->user_id,
            'conversation_id' => $message->conversation_id,
            'user_name' => $message->user->name,
        ]);

        // Disparar evento de WebSocket - SIN toOthers()
        $event = new MessageSent($message);

        \Log::info('MessageSent event created', [
            'event_class' => get_class($event),
            'message_id' => $event->message->id,
        ]);

        // Quitar toOthers() para que se envíe a todos
        event(new MessageSent($message));

        \Log::info('Broadcast sent without toOthers');

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

        \Log::info('Usuario actual en getConversations:', ['user_id' => $user->id]);

        $conversations = $user->conversations()
            ->with(['users:id,name,sol_docs_id'])
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
                    $conversation->load('users:id,name,sol_docs_id');
                }

                \Log::info('Usuarios en conversación:', [
                    'conversation_id' => $conversation->id,
                    'users' => $conversation->users->toArray()
                ]);

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

                // Procesar usuarios para incluir la URL de la foto
                $processedUsers = $conversation->users->map(function ($u) {
                    $photoUrl = null;

                    \Log::info('Procesando usuario para foto:', [
                        'user_id' => $u->id,
                        'sol_docs_id' => $u->sol_docs_id
                    ]);

                    if ($u->sol_docs_id) {
                        // Cargar la documentación para obtener la ruta de la foto
                        $documentacion = \App\Models\DocumentacionAltas::find($u->sol_docs_id);

                        if ($documentacion && $documentacion->arch_foto) {
                            $relativePath = str_replace(['storage/', 'storage\\'], '', $documentacion->arch_foto);
                            $publicPath = storage_path('app/public/' . $relativePath);

                            if (file_exists($publicPath)) {
                                $photoUrl = asset('storage/' . $relativePath);
                                \Log::info('Foto encontrada y URL generada:', [
                                    'user_id' => $u->id,
                                    'url' => $photoUrl
                                ]);
                            } else {
                                \Log::info('Archivo no existe en storage:', [
                                    'user_id' => $u->id,
                                    'path' => $publicPath
                                ]);
                            }
                        } else {
                            \Log::info('No hay documentación o foto para el usuario:', [
                                'user_id' => $u->id,
                                'sol_docs_id' => $u->sol_docs_id
                            ]);
                        }
                    } else {
                        \Log::info('Usuario no tiene sol_docs_id:', ['user_id' => $u->id]);
                    }

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'photo_url' => $photoUrl
                    ];
                });

                return [
                    'id' => $conversation->id,
                    'is_group' => $conversation->is_group,
                    'latest_message' => $conversation->latestMessage,
                    'users' => $processedUsers,
                    'title' => $conversation->is_group ? $conversation->title : ($otherUser ? $otherUser->name : 'Conversación'),
                    'unread_count' => $unreadCount,
                    'last_read_at' => $lastReadAt
                ];
            });

        \Log::info('Conversaciones retornadas:', ['count' => count($conversations)]);

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
