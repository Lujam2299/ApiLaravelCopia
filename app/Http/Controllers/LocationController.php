<?php

namespace App\Http\Controllers;

use App\Events\NuevaAlertaPanico;
use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
            ]);

            $user = $request->user();
            $validated['user_id'] = $user->id;
            $location = Location::create($validated);

            try {
                broadcast(new NuevaAlertaPanico(
                    alertId: (int) $location->id,
                    userId: (int) $user->id,
                    userName: $user->name ?? 'Usuario desconocido',
                    createdAt: $location->created_at?->toISOString() ?? now()->toISOString(),
                ));
            } catch (\Throwable $broadcastError) {
                Log::error('No fue posible emitir la alerta de pánico por WebSocket.', [
                    'location_id' => $location->id,
                    'user_id' => $user->id,
                    'error' => $broadcastError->getMessage(),
                ]);
            }

            return response()->json($location, 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al guardar la ubicación',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
