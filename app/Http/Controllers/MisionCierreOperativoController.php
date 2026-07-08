<?php

namespace App\Http\Controllers;

use App\Models\MisionCierreOperativo;
use App\Models\Misiones;
use App\Support\MissionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MisionCierreOperativoController extends Controller
{
    public function index(Request $request, int $misionId): JsonResponse
    {
        $mision = Misiones::query()->findOrFail($misionId);
        $user = $request->user();

        $this->assertUserCanAccessMission($mision, (int) $user->id);

        $cierres = MisionCierreOperativo::query()
            ->where('mision_id', $mision->id)
            ->where('user_id', $user->id)
            ->orderByDesc('fecha')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $cierres,
        ]);
    }

    public function store(Request $request, int $misionId): JsonResponse
    {
        $mision = Misiones::query()->findOrFail($misionId);
        $user = $request->user();

        $validated = $request->validate([
            'fecha' => ['required', 'date'],
            'resumen' => ['required', 'string', 'max:5000'],
            'novedades' => ['nullable', 'string', 'max:5000'],
            'incidencias' => ['nullable', 'string', 'max:5000'],
            'pendientes' => ['nullable', 'string', 'max:5000'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'client_operation_id' => ['nullable', 'string', 'max:100'],
            'client_created_at' => ['nullable', 'date'],
        ]);

        $this->assertUserCanAccessMission($mision, (int) $user->id);
        $this->assertMissionAcceptsClosure($mision);
        $this->assertDateInsideMission($mision, $validated['fecha']);

        $fecha = Carbon::parse($validated['fecha'])->toDateString();

        if (! empty($validated['client_operation_id'])) {
            $existingByClientId = MisionCierreOperativo::query()
                ->where('client_operation_id', $validated['client_operation_id'])
                ->first();

            if ($existingByClientId) {
                return response()->json([
                    'success' => true,
                    'message' => 'El cierre operativo ya habÃ­a sido sincronizado.',
                    'data' => $existingByClientId,
                ]);
            }
        }

        $cierre = MisionCierreOperativo::query()->updateOrCreate(
            [
                'mision_id' => $mision->id,
                'user_id' => $user->id,
                'fecha' => $fecha,
            ],
            [
                'resumen' => $validated['resumen'],
                'novedades' => $validated['novedades'] ?? null,
                'incidencias' => $validated['incidencias'] ?? null,
                'pendientes' => $validated['pendientes'] ?? null,
                'observaciones' => $validated['observaciones'] ?? null,
                'client_operation_id' => $validated['client_operation_id'] ?? null,
                'client_created_at' => $validated['client_created_at'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => $cierre->wasRecentlyCreated
                ? 'Cierre operativo registrado correctamente.'
                : 'Cierre operativo actualizado correctamente.',
            'data' => $cierre,
        ], $cierre->wasRecentlyCreated ? 201 : 200);
    }

    private function assertUserCanAccessMission(Misiones $mision, int $userId): void
    {
        if (! $mision->tieneAgente($userId)) {
            throw ValidationException::withMessages([
                'mision_id' => 'El usuario no estÃ¡ asignado a esta misiÃ³n.',
            ]);
        }
    }

    private function assertMissionAcceptsClosure(Misiones $mision): void
    {
        if (! MissionStatus::acceptsOperationalEntries($mision->estatus)) {
            throw ValidationException::withMessages([
                'mision_id' => 'No se puede registrar cierre operativo en una misiÃ³n finalizada o cancelada.',
            ]);
        }
    }

    private function assertDateInsideMission(Misiones $mision, string $fecha): void
    {
        $fechaCierre = Carbon::parse($fecha)->startOfDay();
        $inicio = Carbon::parse($mision->fecha_inicio)->startOfDay();
        $fin = $mision->fecha_fin
            ? Carbon::parse($mision->fecha_fin)->endOfDay()
            : $inicio->copy()->endOfDay();

        if ($fechaCierre->lt($inicio) || $fechaCierre->gt($fin)) {
            throw ValidationException::withMessages([
                'fecha' => 'La fecha del cierre debe estar dentro del periodo de la misiÃ³n.',
            ]);
        }
    }
}
