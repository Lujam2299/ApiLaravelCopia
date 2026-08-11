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
use App\Http\Controllers\MisionCierreOperativoController;
use App\Http\Controllers\SupervisorController;

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
    Route::put('/user/password', [AuthController::class, 'updatePassword']);
//    Route::get('/user', fn(Request $request) => $request->user());
    Route::get('/user', [AuthController::class, 'user']);

    Route::prefix('supervisores')->group(function () {
        Route::get('/dashboard', [SupervisorController::class, 'dashboard']);
        Route::get('/personal', [SupervisorController::class, 'people']);
        Route::get('/asistencias', [SupervisorController::class, 'attendanceIndex']);
        Route::get('/asistencias/actual', [SupervisorController::class, 'attendanceCurrent']);
        Route::post('/asistencias', [SupervisorController::class, 'saveAttendance']);
        Route::get('/tiempos-extra', [SupervisorController::class, 'overtimeIndex']);
        Route::get('/vacaciones', [SupervisorController::class, 'vacationIndex']);
        Route::get('/vacaciones/usuario/{user}/resumen', [SupervisorController::class, 'vacationSummary']);
        Route::get('/vacaciones/usuario/{user}/kardex', [SupervisorController::class, 'vacationKardex']);
        Route::post('/vacaciones/usuario/{user}', [SupervisorController::class, 'storeVacation']);
        Route::post('/vacaciones/{solicitud}/resolver', [SupervisorController::class, 'resolveVacation']);
        Route::post('/vacaciones/{solicitud}/archivo', [SupervisorController::class, 'uploadVacationFile']);
        Route::post('/vacaciones/{solicitud}/cancelar', [SupervisorController::class, 'cancelVacation']);
        Route::get('/altas', [SupervisorController::class, 'hiresIndex']);
        Route::post('/altas', [SupervisorController::class, 'storeHire']);
        Route::post('/altas/{solicitud}', [SupervisorController::class, 'updateHire']);
        Route::get('/bajas', [SupervisorController::class, 'terminationsIndex']);
        Route::post('/bajas/{user}', [SupervisorController::class, 'storeTermination']);
    });

    // Rutas de gastos y turnos
    Route::middleware('module.enabled:mobile_custodios')->group(function () {
    Route::post('/guardarTurno', [TurnosController::class, 'guardarTurno']);
    Route::post('/guardarGastos', [GastosController::class, 'guardarGastos']);

    // Rutas de ubicación
    Route::post('/locations', [LocationController::class, 'store']);
    Route::post('/realtime-positions', [RealtimePositionController::class, 'store']);
    Route::get('/realtime-position/user/{id}/recent', [RealtimePositionController::class, 'getUserRecentPositions']);
    });


    // Mensajería
    Route::get('/messages/search-users', [MessageController::class, 'searchUsers']);
    Route::post('/messages/start-conversation', [MessageController::class, 'startConversation']);
    Route::post('/messages/send', [MessageController::class, 'sendMessage']);
    Route::get('/messages/{conversation}', [MessageController::class, 'getMessages']);
    Route::post('/messages/{message}/read', [MessageController::class, 'markAsRead']);
    Route::delete('/messages/{message}', [MessageController::class, 'deleteMessage']);
    Route::get('/conversations', [MessageController::class, 'getConversations']);

    // Rutas de itinerario - VERSIÓN CORREGIDA
    Route::middleware('module.enabled:mobile_custodios')->group(function () {
    Route::prefix('misiones/{mision}/itinerarios')->group(function () {
        Route::post('/', [MisionItinerarioController::class, 'store'])->name('misiones.itinerarios.store');
        Route::get('/', [MisionItinerarioController::class, 'index'])->name('misiones.itinerarios.index');
        Route::get('/user/{user_id}', [MisionItinerarioController::class, 'show'])->name('misiones.itinerarios.show');
    });

    Route::prefix('misiones/{mision}/cierres-operativos')->group(function () {
        Route::get('/', [MisionCierreOperativoController::class, 'index'])->name('misiones.cierres-operativos.index');
        Route::post('/', [MisionCierreOperativoController::class, 'store'])->name('misiones.cierres-operativos.store');
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
