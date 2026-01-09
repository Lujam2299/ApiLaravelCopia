<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Misiones;
use Illuminate\Support\Facades\Storage;

class MisionController extends Controller
{
    /**
     * Obtener misiones asignadas al usuario (activas y pendientes)
     */
    public function misionesUsuario(Request $request)
    {
        try {
            $user = $request->user();

            $misiones = Misiones::whereJsonContains('agentes_id', $user->id)
                ->whereIn('estatus', ['Activa', 'Pendiente'])
                ->select([
                    'id',
                    'nombre_clave',
                    'estatus',
                    'arch_mision',
                    'fecha_inicio',
                    'fecha_fin',
                    'updated_at'
                ])
                ->orderBy('estatus', 'desc') // Activas primero
                ->get();

            return response()->json([
                'success' => true,
                'misiones' => $misiones
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener misiones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener archivo principal de una misión
     */
  public function archivoMision(Request $request, $misionId)
{
    try {
        $user = $request->user();
        $mision = Misiones::findOrFail($misionId);

        // Verificar asignación
        if (!in_array($user->id, $mision->agentes_id ?? [])) {
            return response()->json([
                'success' => false,
                'message' => 'No estás asignado a esta misión'
            ], 403);
        }

        // Cambiamos la respuesta para incluir la misión aunque no tenga archivo
        $response = [
            'success' => true,
            'mision' => [
                'id' => $mision->id,
                'nombre_clave' => $mision->nombre_clave,
                'estatus' => $mision->estatus,
                'fecha_inicio' => $mision->fecha_inicio,
                'fecha_fin' => $mision->fecha_fin
            ],
            'estatus_actual' => $mision->estatus
        ];

        // Solo agregar archivo si existe
        if (!empty($mision->arch_mision)) {
            $response['archivo'] = [
                'nombre' => basename($mision->arch_mision),
                'ruta' => $mision->arch_mision,
                'tipo' => 'principal',
                'fecha_actualizacion' => $mision->updated_at->format('Y-m-d H:i:s')
            ];
        }

        return response()->json($response);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener información de la misión: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Descargar archivo de misión
     */
    public function descargarArchivo(Request $request, $misionId)
    {
        try {
            $user = $request->user();
            $mision = Misiones::findOrFail($misionId);

            // Verificar asignación
            if (!in_array($user->id, $mision->agentes_id ?? [])) {
                abort(403, 'No estás asignado a esta misión');
            }

            $rutaArchivo = $request->header('X-Archivo-Ruta') ?? $mision->arch_mision;
            $rutaCompleta = storage_path('app/' . $rutaArchivo);

            if (!file_exists($rutaCompleta)) {
                abort(404, 'El archivo no existe');
            }

            return response()->download($rutaCompleta);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar: ' . $e->getMessage()
            ], 500);
        }
    }
}
