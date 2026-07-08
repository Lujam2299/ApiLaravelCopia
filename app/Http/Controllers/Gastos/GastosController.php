<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Models\gastos;
use App\Models\Misiones;
use App\Support\MissionStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GastosController extends Controller
{
    public function guardarGastos(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['error' => 'Usuario no autenticado'], 401);
            }

            $validated = $request->validate([
                'Monto' => 'required|numeric',
                'Fecha' => 'required|date',
                'Hora' => 'required|date_format:H:i',
                'Evidencia' => 'required|file|mimes:jpg,jpeg,png,pdf|max:20480',
                'Tipo' => 'required|in:Viaticos,Gasolina',
                'Categoria' => [
                    'nullable',
                    Rule::in([
                        'alimentos',
                        'propina',
                        'hotel',
                        'peaje',
                        'recarga_tag',
                        'transporte',
                        'estacionamiento',
                        'comision_atm',
                        'lavado',
                        'otros',
                    ]),
                ],
                'Metodo_pago' => [
                    'nullable',
                    Rule::in(['tag', 'efectivo', 'tarjeta', 'otro']),
                ],
                'Descripcion' => 'nullable|required_if:Categoria,otros|string|max:255',
                'Km' => 'nullable|numeric',
                'Gasolina_antes_carga' => 'nullable|numeric',
                'Gasolina_despues_carga' => 'nullable|numeric',
                'client_operation_id' => 'nullable|string|max:100',
                'mision_id' => 'required|integer|exists:misiones,id',
            ]);

            $mision = Misiones::query()->findOrFail($validated['mision_id']);

            if (! $mision->tieneAgente((int) $user->id)) {
                throw ValidationException::withMessages([
                    'mision_id' => 'No estás asignado a esta misión.',
                ]);
            }

            if (! MissionStatus::acceptsOperationalEntries($mision->estatus)) {
                throw ValidationException::withMessages([
                    'mision_id' => 'La misión ya no admite gastos.',
                ]);
            }

            $expenseDate = Carbon::parse($validated['Fecha'])->startOfDay();
            $missionStart = Carbon::parse($mision->fecha_inicio)->startOfDay();
            $missionEnd = Carbon::parse($mision->fecha_fin ?? $mision->fecha_inicio)->endOfDay();

            if (! $expenseDate->betweenIncluded($missionStart, $missionEnd)) {
                throw ValidationException::withMessages([
                    'Fecha' => 'La fecha del gasto debe estar dentro del periodo de la misión.',
                ]);
            }

            if (! empty($validated['client_operation_id'])) {
                $existing = gastos::query()
                    ->where('user_id', $user->id)
                    ->where('client_operation_id', $validated['client_operation_id'])
                    ->first();
                if ($existing) {
                    return response()->json(['success' => true, 'data' => $existing]);
                }
            }

            $path = $request->file('Evidencia')->store('evidencias', 'public');

            $gastoData = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'Monto' => $validated['Monto'],
                'Fecha' => $validated['Fecha'],
                'Hora' => $validated['Hora'],
                'Evidencia' => $path,
                'Tipo' => $validated['Tipo'],
                'Categoria' => $validated['Tipo'] === 'Gasolina'
                    ? 'gasolina'
                    : ($validated['Categoria'] ?? null),
                'Metodo_pago' => $validated['Metodo_pago'] ?? null,
                'Descripcion' => isset($validated['Descripcion'])
                    ? trim($validated['Descripcion'])
                    : null,
                'client_operation_id' => $validated['client_operation_id'] ?? null,
                'mision_id' => $mision->id,
            ];

            // Solo agregar campos si son Gasolina
            if ($validated['Tipo'] === 'Gasolina') {
                $gastoData['Km'] = $validated['Km'];
                $gastoData['Gasolina_antes_carga'] = $validated['Gasolina_antes_carga'];
                $gastoData['Gasolina_despues_carga'] = $validated['Gasolina_despues_carga'];
            }

            $gasto = gastos::create($gastoData);

            return response()->json([
                'success' => true,
                'data' => $gasto,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al guardar gasto: '.$e->getMessage());

            return response()->json([
                'error' => 'Error al procesar la solicitud',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
