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
use App\Events\MessagesRead;
use App\Events\ConversationUpdated;
use App\Events\ToastNotification;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            'id' => $conversation->id,
            'conversation_id' => $conversation->id,
            'messages' => $conversation->messages()->with(['user', 'parent.user'])->get()
        ]);
    }
    public function sendMessage(Request $request)
{
    $request->validate([
        'conversation_id' => 'required|exists:conversations,id',
        'body' => 'required|string|max:1000',
        'parent_id' => 'nullable|integer|exists:messages,id',
    ]);

    $conversation = Auth::user()->conversations()
        ->where('conversations.id', $request->conversation_id)
        ->firstOrFail();

    if ($request->filled('parent_id')) {
        $belongsToConversation = Message::query()
            ->whereKey($request->parent_id)
            ->where('conversation_id', $conversation->id)
            ->exists();
        abort_unless($belongsToConversation, 422, 'El mensaje respondido no pertenece a esta conversación.');
    }

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'user_id' => Auth::id(),
        'body' => trim($request->body),
        'parent_id' => $request->parent_id,
    ]);

    // Cargar relaciones necesarias
    $message->load(['user', 'parent.user']);

    \Log::info('Message created for broadcast', [
        'message_id' => $message->id,
        'user_id' => $message->user_id,
        'conversation_id' => $message->conversation_id,
        'user_name' => $message->user->name,
    ]);

    // ✅ ACTUALIZAR EL UNREAD_COUNT PARA LOS DEMÁS USUARIOS EN LA CONVERSACIÓN
    $this->incrementUnreadCount($message->conversation, $message->user);

    // ✅ ACTUALIZAR updated_at DE LA CONVERSAIÓN
    $message->conversation->touch(); // Esto actualiza el updated_at

    $recipientIds = $conversation->users()
        ->pluck('users.id')
        ->map(fn ($id) => (int) $id)
        ->all();

    broadcast(new ConversationUpdated($message, $recipientIds));

    $toastRecipientIds = array_values(array_filter(
        $recipientIds,
        fn ($id) => $id !== (int) Auth::id()
    ));

    if ($toastRecipientIds !== []) {
        broadcast(new ToastNotification($toastRecipientIds, [
            'icon' => 'info',
            'title' => 'Nuevo mensaje de ' . ($message->user?->name ?? 'un participante'),
            'text' => Str::limit($message->body, 120),
            'url' => '/mensajes/' . $message->conversation_id,
            'key' => 'message:' . $message->id,
            'conversation_id' => $message->conversation_id,
        ]));
    }

    return response()->json([
        'status' => 'success',
        'message' => $message
    ]);
}

// ✅ NUEVA FUNCIÓN: Incrementar unread_count para otros usuarios
private function incrementUnreadCount($conversation, $senderUser)
{
    // Obtener todos los usuarios de la conversación excepto el remitente
    $recipientIds = $conversation->users()
        ->where('users.id', '!=', $senderUser->id)
        ->pluck('users.id'); // Asegúrate de usar 'users.id' aquí

    if ($recipientIds->isNotEmpty()) {
        // Incrementar el unread_count para cada destinatario
        DB::table('conversation_user')
            ->whereIn('api_user_id', $recipientIds)
            ->where('conversation_id', $conversation->id)
            ->increment('unread_count', 1); // Incrementar en 1
    }
}

    public function getMessages($conversationId)
{
    // Verificar acceso a la conversación
    $conversation = Auth::user()->conversations()
        ->where('conversations.id', $conversationId)
        ->firstOrFail();

    // Obtener mensajes ordenados
    $messages = $conversation->messages()
        ->with(['user:id,name', 'parent.user:id,name'])
        ->orderBy('created_at', 'asc')
        ->get();

    // Marcar mensajes como leídos para el usuario actual
    $readMessageIds = $conversation->messages()
        ->whereNull('read_at')
        ->where('user_id', '!=', Auth::id())
        ->pluck('id');

    if ($readMessageIds->isNotEmpty()) {
        Message::whereIn('id', $readMessageIds)->update(['read_at' => now()]);
        $messages = $messages->map(function ($message) use ($readMessageIds) {
            if ($readMessageIds->contains($message->id)) {
                $message->read_at = now();
            }
            return $message;
        });

        broadcast(new MessagesRead((int) $conversation->id, (int) Auth::id()));
    }

    // ✅ ACTUALIZAR EL CONTADOR DE MENSAJES NO LEÍDOS EN LA TABLA PIVOTE
    $this->updateUnreadCount($conversation, Auth::user());

    return response()->json($messages);
}

// ✅ NUEVA FUNCIÓN: Actualizar el unread_count en la tabla pivote
private function updateUnreadCount($conversation, $user)
{
    $lastReadAt = now();

    // Actualizar el last_read_at en la tabla pivote
    DB::table('conversation_user')
        ->where('conversation_id', $conversation->id)
        ->where('api_user_id', $user->id)
        ->update([
            'last_read_at' => $lastReadAt,
            'unread_count' => 0 // Resetear el contador ya que marco como leídos
        ]);
}

    public function markAsRead(Message $message)
    {
        $hasAccess = Auth::user()->conversations()
            ->where('conversations.id', $message->conversation_id)
            ->exists();

        abort_unless($hasAccess, 403);

        if ($message->user_id !== Auth::id() && $message->read_at === null) {
            $message->update(['read_at' => now()]);
            $this->updateUnreadCount($message->conversation, Auth::user());
            broadcast(new MessagesRead((int) $message->conversation_id, (int) Auth::id()));
        }

        return response()->json(['success' => true]);
    }
public function getConversations(Request $request)
{
    try {
        $user = $request->user();

        \Log::info('Usuario actual en getConversations:', ['user_id' => $user->id]);

        $conversations = $user->conversations()
            ->with([
                'users' => function($query) { // ✅ CORRECTO: Sin columnas en el nombre de la relación
                    $query->select('id', 'name', 'sol_docs_id'); // ✅ CORRECTO: select() dentro del closure
                    $query->withPivot(['last_read_at', 'unread_count']); // ✅ Cargar los campos del pivote
                },
                'latestMessage'
            ])
            ->orderByDesc('updated_at') // ✅ Ordenar por updated_at
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

                // Obtener el pivot del usuario actual en esta conversación
                $currentUserPivot = $conversation->users->first(function ($u) use ($user) {
                    return $u->id === $user->id;
                })?->pivot;

                // ✅ Usar los valores directamente del modelo pivote
                $lastReadAt = $currentUserPivot->last_read_at ?? null;
                $unreadCount = $currentUserPivot->unread_count ?? 0; // ✅ Valor del modelo pivote

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
                    'updated_at' => $conversation->updated_at, // Asegurar que se incluya
                    // ✅ CAMBIO IMPORTANTE: Incluir el campo pivot con unread_count del modelo
                    'pivot' => [
                        'last_read_at' => $lastReadAt,
                        'unread_count' => $unreadCount // ✅ Valor del modelo pivote
                    ]
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
