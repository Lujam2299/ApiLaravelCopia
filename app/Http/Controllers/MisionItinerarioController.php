<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Misiones;
use Illuminate\Support\Carbon;

class MisionItinerarioController extends Controller
{
    // Agregar evento al itinerario
    public function store(Request $request, $mision_id)
    {
        $request->validate([
            'user_id' => 'required|integer|min:1',
            'fecha' => 'required|date|after_or_equal:today',
            'hora' => 'required|date_format:H:i',
            'descripcion' => 'required|string|max:255',
            'ubicacion' => 'nullable|string|max:255'
        ]);

        $mision = Misiones::findOrFail($mision_id);
        $currentUser = $request->user();

        // Verificar que la misión esté activa
        if (strtolower($mision->estatus) !== 'activa') {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden agregar eventos a una misión inactiva'
            ], 400);
        }

        // Verificar que el usuario autenticado tenga permisos
        if ($currentUser->id != $request->user_id && !$currentUser->esAdministrador()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para agregar eventos a este usuario'
            ], 403);
        }

        // Obtener agentes asignados de forma segura
        $agents = $this->getAgentesFromMision($mision);

        if (!in_array($request->user_id, $agents)) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no está asignado a esta misión'
            ], 403);
        }

        // Crear el evento con marca de tiempo
        $evento = [
            'user_id' => (int)$request->user_id,
            'fecha' => $request->fecha,
            'hora' => $request->hora,
            'descripcion' => $request->descripcion,
            'ubicacion' => $request->ubicacion ?? null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString()
        ];

        // Obtener itinerarios existentes de forma segura
        $itinerarios = $this->getItinerariosFromMision($mision);

        // Buscar o crear entrada para el usuario
        $userIndex = $this->findUserIndexInItinerarios($itinerarios, $request->user_id);

        if ($userIndex !== false) {
            // Agregar evento al usuario existente
            $itinerarios[$userIndex]['eventos'][] = $evento;
        } else {
            // Crear nueva entrada para el usuario
            $itinerarios[] = [
                'user_id' => $request->user_id,
                'eventos' => [$evento]
            ];
        }

        // Ordenar eventos por fecha y hora para cada usuario
        $itinerarios = $this->sortItinerarios($itinerarios);

        // Guardar los cambios
        $mision->itinerarios = $itinerarios;
        $mision->save();

        return response()->json([
            'success' => true,
            'message' => 'Evento agregado al itinerario correctamente',
            'data' => $this->getUserItinerarios($itinerarios, $request->user_id)
        ]);
    }

    // Obtener itinerarios de un usuario específico
    public function show($mision_id, $user_id)
    {
        $mision = Misiones::findOrFail($mision_id);
        $currentUser = auth()->user();

        // Verificar que la misión esté activa
        if (strtolower($mision->estatus) !== 'activa') {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden ver itinerarios de una misión inactiva'
            ], 400);
        }

        // Verificar permisos del usuario
        if ($currentUser->id != $user_id && !$currentUser->esAdministrador()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para ver este itinerario'
            ], 403);
        }

        // Verificar que el usuario esté asignado a la misión
        $agents = $this->getAgentesFromMision($mision);
        if (!in_array($user_id, $agents)) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no está asignado a esta misión'
            ], 403);
        }

        // Obtener y preparar los itinerarios
        $itinerarios = $this->getItinerariosFromMision($mision);
        $userItinerarios = $this->getUserItinerarios($itinerarios, $user_id);

        return response()->json([
            'success' => true,
            'data' => [
                // 'user_id' => (int)$user_id,
                'eventos' => $userItinerarios
            ]
        ]);
    }

    // Obtener todos los itinerarios de la misión (solo para administradores)
    public function index($mision_id)
    {
        $mision = Misiones::findOrFail($mision_id);
        $currentUser = auth()->user();

        // Solo administradores pueden ver todos los itinerarios
        if (!$currentUser->esAdministrador()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para ver todos los itinerarios'
            ], 403);
        }

        $itinerarios = $this->getItinerariosFromMision($mision);

        // Ordenar todos los itinerarios
        $itinerarios = $this->sortItinerarios($itinerarios);

        return response()->json([
            'success' => true,
            'data' => $itinerarios
        ]);
    }

    // Métodos auxiliares protegidos
    protected function getAgentesFromMision(Misiones $mision): array
    {
        if (is_array($mision->agentes_id)) {
            return $mision->agentes_id;
        }

        $decoded = json_decode($mision->agentes_id, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function getItinerariosFromMision(Misiones $mision): array
    {
        if (is_array($mision->itinerarios)) {
            return $mision->itinerarios;
        }

        $decoded = json_decode($mision->itinerarios, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function findUserIndexInItinerarios(array $itinerarios, int $userId)
    {
        foreach ($itinerarios as $index => $itinerario) {
            if (isset($itinerario['user_id']) && $itinerario['user_id'] == $userId) {
                return $index;
            }
        }
        return false;
    }

    protected function sortItinerarios(array $itinerarios): array
    {
        foreach ($itinerarios as &$itinerario) {
            if (isset($itinerario['eventos']) && is_array($itinerario['eventos'])) {
                usort($itinerario['eventos'], function ($a, $b) {
                    $dateA = Carbon::parse($a['fecha'] . ' ' . $a['hora']);
                    $dateB = Carbon::parse($b['fecha'] . ' ' . $b['hora']);
                    return $dateA <=> $dateB;
                });
            }
        }

        return $itinerarios;
    }

    protected function getUserItinerarios(array $itinerarios, int $userId): array
    {
        foreach ($itinerarios as $itinerario) {
            if (isset($itinerario['user_id']) && $itinerario['user_id'] == $userId) {
                return $itinerario['eventos'] ?? [];
            }
        }
        return [];
    }
}
