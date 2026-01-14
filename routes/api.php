<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Gastos\GastosController;
use App\Http\Controllers\Turnos\TurnosController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MisionItinerarioController;
use App\Http\Controllers\MisionController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/test', function () { return response()->json(['message' => 'OK']); });

// Rutas protegidas por Sanctum
Route::middleware('auth:sanctum')->group(function () {
    /*Route::post('/broadcasting/auth', function (Request $request) {
    // Verificar si el usuario está autenticado
    if (!$request->user()) {
        abort(403, 'Forbidden');
    }

    $channelName = $request->channel_name;

    // Verificar si es un canal de conversación
    if (Str::startsWith($channelName, 'private-conversacion.')) {
        $conversationId = Str::after($channelName, 'private-conversacion.');

        // Verificar que el usuario tenga acceso a la conversación
        $hasAccess = $request->user()->conversations->pluck('id')->contains($conversationId);

        if ($hasAccess) {
            return response()->json([
                'channel_data' => [
                    'user_id' => $request->user()->id,
                    'user_info' => [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                    ]
                ]
            ]);
        }

        // Si no tiene acceso, prohibido
        abort(403, 'No tienes acceso a esta conversación');
    }

    // Para otros canales privados, puedes agregar lógica adicional
    abort(403, 'Canal no autorizado');
})->middleware('auth:sanctum');*/

    Route::post('/broadcasting/auth', function (Request $request) {
        // Asegura que el usuario esté autenticado
        if (! auth()->check()) {
            abort(403, 'Unauthenticated.');
        }

        // Devuelve la respuesta de autenticación
        return \Illuminate\Support\Facades\Broadcast::auth($request);
    })->middleware('auth:sanctum');

    // Rutas de autenticación
    Route::post('/logout', [AuthController::class, 'logout']);
//    Route::get('/user', fn(Request $request) => $request->user());
    Route::get('/user', [AuthController::class, 'user']);

    // Rutas de gastos y turnos
    Route::post('/guardarTurno', [TurnosController::class, 'guardarTurno']);
    Route::post('/guardarGastos', [GastosController::class, 'guardarGastos']);

    // Rutas de ubicación
    Route::post('/locations', [LocationController::class, 'store']);

    // Mensajería
    Route::get('/messages/search-users', [MessageController::class, 'searchUsers']);
    Route::post('/messages/start-conversation', [MessageController::class, 'startConversation']);
    Route::post('/messages/send', [MessageController::class, 'sendMessage']);
    Route::get('/messages/{conversation}', [MessageController::class, 'getMessages']);
    Route::post('/messages/{message}/read', [MessageController::class, 'markAsRead']);
    Route::get('/conversations', [MessageController::class, 'getConversations']);

    // Rutas de itinerario - VERSIÓN CORREGIDA
    Route::prefix('misiones/{mision}/itinerarios')->group(function () {
        Route::post('/', [MisionItinerarioController::class, 'store'])->name('misiones.itinerarios.store');
        Route::get('/', [MisionItinerarioController::class, 'index'])->name('misiones.itinerarios.index');
        Route::get('/user/{user_id}', [MisionItinerarioController::class, 'show'])->name('misiones.itinerarios.show');
    });

    // Nuevas rutas para manejo de archivos de misión
    Route::prefix('misiones')->group(function () {
         // Obtener misiones del usuario (activas y pendientes)
    Route::get('/usuario', [MisionController::class, 'misionesUsuario'])
        ->name('misiones.usuario');

    // Obtener archivo de misión específica
    Route::get('/{mision}/archivo', [MisionController::class, 'archivoMision'])
        ->name('misiones.archivo');

    // Descargar archivo
    Route::get('/{mision}/descargar', [MisionController::class, 'descargarArchivo'])
        ->name('misiones.descargar');
    });

    Route::get('/user-photo/{solicitud_id}', function ($solicitud_id) {
    $documentacion = App\Models\DocumentacionAltas::find($solicitud_id);

    if ($documentacion && $documentacion->arch_foto) {
        // Asegurarnos de que la ruta es correcta
        $photoPath = $documentacion->arch_foto;

        if (Storage::exists($photoPath)) {
            $url = asset(str_replace('storage/', 'storage/', $photoPath));
            return response()->json(['photo_url' => $url]);
        }
    }

    return response()->json(['photo_url' => null], 404);
})->where('solicitud_id', '[0-9]+');
});
