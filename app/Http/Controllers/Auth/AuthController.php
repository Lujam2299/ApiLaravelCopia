<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Misiones;
use App\Models\User;
use App\Support\MissionStatus;
use Carbon\Carbon; // Asegúrate de que este es el modelo de usuario correcto
use Illuminate\Http\Request; // ¡IMPORTANTE! Importa el modelo Mision
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // Asegúrate de importar Auth para Auth::user() si lo usas
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'email' => 'required|email',
                'password' => ['required', Password::min(8)->letters()->numbers()],
            ], [
                'email.required' => 'El correo es requerido.',
                'email.email' => 'Por favor proporcione un correo válido.',
                'password.required' => 'Contraseña es requerida.',
                'password.min' => 'La contraseña debe contener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y símbolos.',
                'password.letters' => 'La contraseña debe contener al menos 1 letra.',
                'password.numbers' => 'La contraseña debe contener al menos 1 número.',
            ]);

            $user = User::where('email', $validatedData['email'])->first();

            if (! $user || ! Hash::check($validatedData['password'], $user->password)) {
                return response()->json(['message' => 'Credenciales inválidas'], 401);
            }

            $token = $user->createToken('api_token')->plainTextToken;

            Log::info('🔍 [LOGIN] Iniciando búsqueda de misión activa', [
                'user_id' => $user->id,
                'email' => $user->email,
                'fecha_hoy' => Carbon::today()->toDateString(),
            ]);

            $misionActiva = Misiones::query()
                ->whereDate('fecha_inicio', '<=', Carbon::today())
                ->where(function ($query): void {
                    $query->whereNull('fecha_fin')
                        ->orWhereDate('fecha_fin', '>=', Carbon::today());
                })
                ->orderBy('fecha_inicio')
                ->orderBy('id')
                ->get()
                ->first(fn (Misiones $mision) => $mision->tieneAgente((int) $user->id)
                    && MissionStatus::acceptsOperationalEntries($mision->estatus)
                );

            if ($misionActiva) {
                Log::info('✅ [LOGIN] Misión activa ENCONTRADA', [
                    'user_id' => $user->id,
                    'mision_id' => $misionActiva->id,
                    'nombre_clave' => $misionActiva->nombre_clave,
                    'agentes_id_en_bd' => $misionActiva->getRawOriginal('agentes_id'),
                ]);
            } else {
                Log::warning('❌ [LOGIN] NO se encontró misión activa', [
                    'user_id' => $user->id,
                    'mensaje' => 'Verifica fechas de misión y que el usuario esté en agentes_id como string en JSON.',
                ]);

                // Diagnóstico seguro: decodificar manualmente sin asumir tipo
                $misionesVigentes = Misiones::where('fecha_inicio', '<=', Carbon::today())
                    ->where('fecha_fin', '>=', Carbon::today())
                    ->get()
                    ->map(function ($m) use ($user) {
                        $raw = $m->getRawOriginal('agentes_id');
                        $decoded = is_string($raw) ? @json_decode($raw, true) : null;
                        $decoded = is_array($decoded) ? $decoded : [];

                        return [
                            'id' => $m->id,
                            'agentes_id_raw' => $raw,
                            'contiene_usuario' => in_array((string) $user->id, $decoded, true),
                        ];
                    });

                Log::debug('📋 [LOGIN] Misiones vigentes y coincidencia con usuario', [
                    'misiones' => $misionesVigentes->toArray(),
                ]);
            }

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'punto' => $user->punto ?? null,
                    'rol' => $user->rol ?? null,
                    'empresa' => $user->empresa ?? null,
                    'mision_id_activa' => $misionActiva ? $misionActiva->id : null,
                ],
                'message' => 'Ingreso exitoso',
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('🔥 [LOGIN] Error interno', [
                'email' => $request->input('email', 'desconocido'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Error interno del servidor'], 500);
        }
    }

    public function register(Request $request)
    {
        try {
            // Validate the incoming request data
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => ['required', Password::min(8)->letters()->numbers()],
                //'telefono' => 'nullable|string|size:10|unique:users', // 'nullable' and 'unique' as per your migration
                'rol' => 'nullable|in:interno,externo', // 'nullable' and restricted to 'interno' or 'externo'
                'punto' => 'nullable|string|max:255', // 'nullable' and string
            ], [
                // Custom validation messages for better user feedback
                'email.required' => 'El correo es requerido.',
                'password.letters' => 'La contraseña debe contener al menos 1 letra.',
                'password.numbers' => 'La contraseña debe contener al menos 1 número.',
                'email.unique' => 'Este correo ya está registrado.',
                'email.email' => 'Por favor proporcione un correo válido.',
                'password.required' => 'Contraseña es requerida.',
                'password.min' => 'La contraseña debe contener al menos 8 caracteres, incluyendo mayúsculas, minúsculas, números y símbolos.',
                //'telefono.size' => 'El teléfono debe contener 10 dígitos si se provee.',
                //'telefono.unique' => 'Este teléfono ya está registrado.',
                'rol.in' => 'El rol debe ser "interno" o "externo".',
            ]);

            // Create the new user using the validated data
            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
                //'telefono' => $validatedData['telefono'] ?? null, // Assign null if not provided in request
                'rol' => $validatedData['rol'] ?? 'interno',      // Assign validated role, or 'interno' as default
                'punto' => $validatedData['punto'] ?? null,      // Assign null if not provided
                'remember_token' => Str::random(80), // Consider removing if only using Sanctum tokens
                'email_verified_at' => now(), // Typically set to null and filled upon email verification
            ]);

            // Create a Sanctum authentication token for the new user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return a successful JSON response with the access token and user data
            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'message' => 'Registro exitoso',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    //'telefono' => $user->telefono, // Include telefono in the response
                    'rol' => $user->rol,          // Include rol in the response
                    'punto' => $user->punto,      // Include punto in the response
                ],
            ], 201);
        } catch (ValidationException $e) {
            // Catch and respond with validation errors
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            // Catch and respond with generic internal server errors
            // In a production environment, you would log $e->getMessage() for debugging.
            return response()->json(['message' => 'Error interno del servidor'], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Has cerrado sesión exitosamente.'], 200);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.required' => 'La contraseña actual es obligatoria.',
            'password.required' => 'La nueva contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.letters' => 'La nueva contraseña debe incluir al menos una letra.',
            'password.numbers' => 'La nueva contraseña debe incluir al menos un número.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    public function user(Request $request)
    {
        \Log::info('Entrando al método user del AuthController');
        \Log::info('Usuario autenticado: ', ['user_id' => $request->user()->id]);

        $user = $request->user();

        \Log::info('Usuario antes de cargar relación: ', ['sol_docs_id' => $user->sol_docs_id]);

        // Cargar la relación con documentación de altas
        $user->load('documentacionAltas');

        \Log::info('Usuario después de cargar relación: ', [
            'has_documentacion' => $user->documentacionAltas ? 'true' : 'false',
            'documentacion_altas' => $user->documentacionAltas,
        ]);

        $photoUrl = $user->photo_url;

        $responseData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            //'telefono' => $user->telefono ?? null,
            'direccion' => $user->direccion ?? null,
            'created_at' => $user->created_at,
            'sol_docs_id' => $user->sol_docs_id,
            'photo_url' => $photoUrl,
            'punto' => $user->punto ?? null,
            'empresa' => $user->empresa ?? null,        // Nuevo campo
            'fecha_ingreso' => $user->fecha_ingreso ?? null,  // Nuevo campo
            'rol' => $user->rol ?? null,          // Nuevo campo
        ];

        \Log::info('Respondiendo con datos: ', $responseData);

        return response()->json($responseData);
    }
}
