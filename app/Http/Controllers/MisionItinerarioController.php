<?php

namespace App\Http\Controllers;

use App\Models\Misiones;
use App\Support\MissionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MisionItinerarioController extends Controller
{
    // Agregar evento al itinerario
    public function store(Request $request, $mision_id)
    {
        $request->validate([
            'user_id' => 'required|integer|min:1',
            'fecha' => 'required|date',
            'hora' => 'required|date_format:H:i',
            'descripcion' => 'required|string|max:255',
            'ubicacion' => 'nullable|string|max:255',
            'client_operation_id' => 'nullable|string|max:100',
            'client_created_at' => 'nullable|date',
        ]);

        $minimumDate = $request->filled('client_created_at')
            ? Carbon::parse($request->client_created_at)->startOfDay()
            : today();
        abort_if(
            Carbon::parse($request->fecha)->startOfDay()->lt($minimumDate),
            422,
            'La fecha no puede ser anterior a la creación del movimiento.'
        );

        $mision = Misiones::findOrFail($mision_id);
        $currentUser = $request->user();

        // Verificar que la misión esté activa
        if (! MissionStatus::acceptsOperationalEntries($mision->estatus)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden agregar eventos a una misión inactiva',
            ], 400);
        }

        // Verificar que el usuario autenticado tenga permisos
        if ($currentUser->id != $request->user_id && ! $currentUser->esAdministrador()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para agregar eventos a este usuario',
            ], 403);
        }

        // Obtener agentes asignados de forma segura
        $agents = $this->getAgentesFromMision($mision);

        if (! in_array($request->user_id, $agents)) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no está asignado a esta misión',
            ], 403);
        }

        $itinerarios = $this->getItinerariosFromMision($mision);

        if ($request->filled('client_operation_id')) {
            $alreadyStored = collect($itinerarios)
                ->flatMap(fn ($itinerario) => $itinerario['eventos'] ?? [])
                ->contains(fn ($evento) => ($evento['client_operation_id'] ?? null) === $request->client_operation_id
                );

            if ($alreadyStored) {
                return response()->json([
                    'success' => true,
                    'message' => 'El evento ya había sido sincronizado',
                    'data' => $this->getUserItinerarios($itinerarios, $request->user_id),
                ]);
            }
        }

        // Crear el evento con marca de tiempo
        $evento = [
            'user_id' => (int) $request->user_id,
            'fecha' => $request->fecha,
            'hora' => $request->hora,
            'descripcion' => $request->descripcion,
            'ubicacion' => $request->ubicacion ?? null,
            'client_operation_id' => $request->client_operation_id,
            'client_created_at' => $request->client_created_at,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        // Buscar o crear entrada para el usuario
        $userIndex = $this->findUserIndexInItinerarios($itinerarios, $request->user_id);

        if ($userIndex !== false) {
            // Agregar evento al usuario existente
            $itinerarios[$userIndex]['eventos'][] = $evento;
        } else {
            // Crear nueva entrada para el usuario
            $itinerarios[] = [
                'user_id' => $request->user_id,
                'eventos' => [$evento],
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
            'data' => $this->getUserItinerarios($itinerarios, $request->user_id),
        ]);
    }

    // Obtener itinerarios de un usuario específico
    public function show($mision_id, $user_id)
    {
        $mision = Misiones::findOrFail($mision_id);
        $currentUser = auth()->user();

        // Verificar que la misión esté activa
        if (! MissionStatus::acceptsOperationalEntries($mision->estatus)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden ver itinerarios de una misión inactiva',
            ], 400);
        }

        // Verificar permisos del usuario
        if ($currentUser->id != $user_id && ! $currentUser->esAdministrador()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para ver este itinerario',
            ], 403);
        }

        // Verificar que el usuario esté asignado a la misión
        $agents = $this->getAgentesFromMision($mision);
        if (! in_array($user_id, $agents)) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no está asignado a esta misión',
            ], 403);
        }

        // Obtener y preparar los itinerarios
        $itinerarios = $this->getItinerariosFromMision($mision);
        $userItinerarios = $this->getUserItinerarios($itinerarios, $user_id);

        return response()->json([
            'success' => true,
            'data' => [
                // 'user_id' => (int)$user_id,
                'eventos' => $userItinerarios,
            ],
        ]);
    }

    // Obtener todos los itinerarios de la misión (solo para administradores)
    public function index($mision_id)
    {
        $mision = Misiones::findOrFail($mision_id);
        $currentUser = auth()->user();

        // Solo administradores pueden ver todos los itinerarios
        if (! $currentUser->esAdministrador()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para ver todos los itinerarios',
            ], 403);
        }

        $itinerarios = $this->getItinerariosFromMision($mision);

        // Ordenar todos los itinerarios
        $itinerarios = $this->sortItinerarios($itinerarios);

        return response()->json([
            'success' => true,
            'data' => $itinerarios,
        ]);
    }

    // Métodos auxiliares protegidos
    protected function getAgentesFromMision(Misiones $mision): array
    {
        return $mision->agentesIdsNormalizados();
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
                    $dateA = Carbon::parse($a['fecha'].' '.$a['hora']);
                    $dateB = Carbon::parse($b['fecha'].' '.$b['hora']);

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
