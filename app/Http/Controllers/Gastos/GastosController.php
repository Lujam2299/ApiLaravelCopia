<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\gastos;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;



class GastosController extends Controller
{
 public function guardarGastos(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['error' => 'Usuario no autenticado'], 401);
            }

            $validated = $request->validate([
                'Monto' => 'required|numeric',
                'Fecha' => 'required|date',
                'Hora' => 'required|date_format:H:i',
                'Evidencia' => 'required|file|mimes:jpg,png,pdf|max:20480',
                'Tipo' => 'required|in:Viaticos,Gasolina',
                'Km' => 'nullable|numeric',
                'Gasolina_antes_carga' => 'nullable|numeric',
                'Gasolina_despues_carga' => 'nullable|numeric'
                ,'client_operation_id' => 'nullable|string|max:100'
            ]);

            if (!empty($validated['client_operation_id'])) {
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
                'client_operation_id' => $validated['client_operation_id'] ?? null,
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
                'data' => $gasto
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al guardar gasto: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al procesar la solicitud',
                'details' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}
