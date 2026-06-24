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
use App\Http\Controllers\RealtimePositionController;

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/test', function () { return response()->json(['message' => 'OK']); });

// Rutas protegidas por Sanctum
Route::middleware('auth:sanctum')->group(function () {
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
    Route::post('/realtime-positions', [RealtimePositionController::class, 'store']);
    Route::get('/realtime-position/user/{id}/recent', [RealtimePositionController::class, 'getUserRecentPositions']);


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
    $user = App\Models\User::query()
        ->with('documentacionAltas')
        ->where('sol_docs_id', $solicitud_id)
        ->first();

    if ($user?->photo_url) {
        return response()->json(['photo_url' => $user->photo_url]);
    }

    return response()->json(['photo_url' => null], 404);
})->where('solicitud_id', '[0-9]+');
});
