<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User; // Asegúrate de que este es el modelo de usuario correcto
use App\Models\Mision; // ¡IMPORTANTE! Importa el modelo Mision
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth; // Asegúrate de importar Auth para Auth::user() si lo usas
use Illuminate\Support\Facades\Storage;

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

            if (!$user || !Hash::check($validatedData['password'], $user->password)) {
                return response()->json(['message' => 'Credenciales inválidas'], 401);
            }

            $token = $user->createToken('api_token')->plainTextToken;

            // --- LÓGICA AGREGADA: ENCONTRAR LA MISIÓN ACTIVA DEL USUARIO ---
            // Busca la misión activa a la que el usuario está asignado.
            // Se asume que 'agentes_id' en la tabla 'misiones' es un campo JSON.
            $misionActiva = Mision::where('estatus', 'Activa')
                                  ->whereJsonContains('agentes_id', $user->id) // Verifica si el user_id está en agentes_id
                                  ->first(); // Obtiene la primera misión activa encontrada

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => [ // Añade esta sección con los datos del usuario
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'punto' => $user->punto ?? null, // Asegúrate de que 'punto' exista en tu modelo apiUser
                    'mision_id_activa' => $misionActiva ? $misionActiva->id : null, // Envía la ID de la misión activa
                ],
                'message' => 'Ingreso exitoso',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
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
                'telefono' => 'nullable|string|size:10|unique:users', // 'nullable' and 'unique' as per your migration
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
                'telefono.size' => 'El teléfono debe contener 10 dígitos si se provee.',
                'telefono.unique' => 'Este teléfono ya está registrado.',
                'rol.in' => 'El rol debe ser "interno" o "externo".',
            ]);

            // Create the new user using the validated data
            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
                'telefono' => $validatedData['telefono'] ?? null, // Assign null if not provided in request
                'rol' => $validatedData['rol'] ?? 'interno',      // Assign validated role, or 'interno' as default
                'punto' => $validatedData['punto'] ?? null,      // Assign null if not provided
                'remember_token' => Str::random(80), // Consider removing if only using Sanctum tokens
                'email_verified_at' => now() // Typically set to null and filled upon email verification
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
                    'telefono' => $user->telefono, // Include telefono in the response
                    'rol' => $user->rol,          // Include rol in the response
                    'punto' => $user->punto,      // Include punto in the response
                ]
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
        'documentacion_altas' => $user->documentacionAltas
    ]);

    // Obtener la URL de la foto si existe
    $photoUrl = null;
    if ($user->documentacionAltas && $user->documentacionAltas->arch_foto) {
        \Log::info('Foto encontrada en documentación: ', ['arch_foto' => $user->documentacionAltas->arch_foto]);

        // Convertir la ruta de la base de datos a la ruta pública
        $relativePath = str_replace(['storage/', 'storage\\'], '', $user->documentacionAltas->arch_foto);

        // Verificar si el archivo existe en storage/app/public
        $publicPath = storage_path('app/public/' . $relativePath);

        \Log::info('Ruta pública del archivo: ', ['path' => $publicPath]);

        if (file_exists($publicPath)) {
            \Log::info('Archivo existe en storage/app/public');

            // Generar URL usando asset (esto usará la ruta pública)
            $photoUrl = asset('storage/' . $relativePath);
            \Log::info('URL generada: ', ['url' => $photoUrl]);
        } else {
            \Log::info('Archivo no existe en storage/app/public');
        }
    } else {
        \Log::info('No hay foto en documentación altas o no hay relación');
    }

    $responseData = [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'telefono' => $user->telefono ?? null,
        'direccion' => $user->direccion ?? null,
        'created_at' => $user->created_at,
        'sol_docs_id' => $user->sol_docs_id,
        'photo_url' => $photoUrl,
        'punto' => $user->punto ?? null,
    ];

    \Log::info('Respondiendo con datos: ', $responseData);

    return response()->json($responseData);
}
}
