<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RealtimePosition;
use App\Events\NuevaUbicacionRealtime; // Importar el evento
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // Importar Log

class RealtimePositionController extends Controller
{
    public function store(Request $request)
    {
        Log::info('RealtimePositionController@store: Iniciando almacenamiento de ubicación', ['request_data' => $request->all()]);

        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'device_id' => 'nullable|string|max:255',
        ]);

        Log::info('RealtimePositionController@store: Validación pasada');

        $position = RealtimePosition::create([
            'user_id' => Auth::id(),
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'device_id' => $request->device_id,
            'recorded_at' => now(),
        ]);

        Log::info('RealtimePositionController@store: Ubicación guardada en DB', ['position_id' => $position->id, 'user_id' => $position->user_id]);

        // --- EMITIR EL EVENTO LOCALMENTE DESDE EL API ---
        Log::info('RealtimePositionController@store: Intentando emitir evento WebSocket NuevaUbicacionRealtime', ['position_id' => $position->id]);
        broadcast(new NuevaUbicacionRealtime($position))->toOthers();
        Log::info('RealtimePositionController@store: Evento WebSocket NuevaUbicacionRealtime emitido', ['position_id' => $position->id]);
        // --- FIN EMISIÓN ---

        Log::info('RealtimePositionController@store: Finalizando almacenamiento de ubicación', ['position_id' => $position->id]);

        return response()->json(['status' => 'success', 'position' => $position]);
    }

    public function getUserRecentPositions($id, Request $request)
    {
        $periodo = Carbon::now()->subHours(24);

        $positions = RealtimePosition::where('user_id', $id)
            ->where('recorded_at', '>', $periodo)
            ->orderBy('recorded_at', 'desc')
            ->select('latitude', 'longitude', 'recorded_at', 'device_id')
            ->get();

        return response()->json([
            'user_id' => $id,
            'positions' => $positions,
            'total' => $positions->count()
        ]);
    }
}
