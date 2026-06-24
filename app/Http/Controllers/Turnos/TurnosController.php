<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\turno;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // No olvides importar Storage

class TurnosController extends Controller
{
    public function guardarTurno(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Usuario no autenticado'], 401);
            }

            // Reglas base
            $rules = [
                'Placas_unidad' => 'required|string|max:20',
                'Tipo' => 'required|in:Entrada,Salida',
                'client_operation_id' => 'nullable|string|max:100',
            ];

            // Reglas condicionales
            if ($request->input('Tipo') === 'Entrada') {
                $rules += [
                    'Hora_inicio' => 'required|date_format:H:i',
                    'Km_inicio' => 'required|numeric|min:0',
                    'Rayas_gasolina_inicio' => 'required|numeric|min:0|max:100',
                    'Evidencia_inicio' => 'required|file|mimes:jpg,jpeg,png,pdf|max:20480',
                ];
            } else {
                $rules += [
                    'Hora_final' => 'required|date_format:H:i',
                    'Km_final' => 'required|numeric|min:0',
                    'Rayas_gasolina_final' => 'required|numeric|min:0|max:100',
                    'Evidencia_final' => 'required|file|mimes:jpg,jpeg,png,pdf|max:20480',
                ];
            }

            $validatedData = $request->validate($rules);

            if (!empty($validatedData['client_operation_id'])) {
                $existing = turno::query()
                    ->where('User_id', $user->id)
                    ->where('client_operation_id', $validatedData['client_operation_id'])
                    ->first();
                if ($existing) {
                    return response()->json([
                        'message' => 'El turno ya había sido sincronizado',
                        'data' => $existing,
                    ]);
                }
            }

            // Procesar archivos
            $fileField = $request->input('Tipo') === 'Entrada' ? 'Evidencia_inicio' : 'Evidencia_final';
            $filePath = $request->file($fileField)->store('evidencias', 'public');

            // Crear registro
            $turnoData = [
                'User_id' => $user->id,
                'Nombre_elemento' => $user->name,
                'Punto' => $user->punto,
                'Placas_unidad' => $validatedData['Placas_unidad'],
                'Tipo' => $validatedData['Tipo'],
                $fileField => $filePath
                ,'client_operation_id' => $validatedData['client_operation_id'] ?? null
            ];

            // Asignar campos dinámicamente
            $prefix = $validatedData['Tipo'] === 'Entrada' ? 'inicio' : 'final';
            $turnoData["Hora_$prefix"] = $validatedData["Hora_$prefix"];
            $turnoData["Km_$prefix"] = $validatedData["Km_$prefix"];
            $turnoData["Rayas_gasolina_$prefix"] = $validatedData["Rayas_gasolina_$prefix"];

            $turno = turno::create($turnoData);

            return response()->json([
                'message' => 'Turno guardado exitosamente',
                'data' => $turno
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error del servidor',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}
