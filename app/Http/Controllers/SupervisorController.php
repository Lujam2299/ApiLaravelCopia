<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\DocumentacionAltas;
use App\Models\Retardo;
use App\Models\SolicitudAlta;
use App\Models\SolicitudBajas;
use App\Models\SolicitudVacaciones;
use App\Models\Subpunto;
use App\Models\TiemposExtra;
use App\Models\ToastNotificationLog;
use App\Models\User;
use App\Services\RealtimeToast;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupervisorController extends Controller
{
    private const ROLES_ASISTENCIA = [
        'SUPERVISOR',
        'APOYO SUPERVISOR',
        'K9',
        'CORTADOR',
        'GUARDIA',
        'RECEPCIONISTA',
    ];

    private const RH_NOTIFICATION_ROLES = [
        'ADMIN',
        'ADMINISTRADOR',
        'AUXILIAR RECURSOS HUMANOS',
        'AUXILIAR RH',
        'AUX RH',
        'RECURSOS HUMANOS',
        'JEFA RECURSOS HUMANOS',
    ];

    public function dashboard(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);

        return response()->json([
            'access' => true,
            'user' => $this->formatUser($supervisor),
            'scope' => [
                'base_point' => $alcance['base_point'],
                'zone' => $alcance['zone'],
                'message' => $alcance['message'],
                'points' => $alcance['points'],
            ],
            'counters' => [
                'attendance_missing_today' => Asistencia::query()
                    ->where('user_id', $supervisor->id)
                    ->whereDate('fecha', now('America/Mexico_City')->toDateString())
                    ->exists() ? 0 : 1,
                'pending_vacations' => $this->vacationsQuery($supervisor, $alcance)
                    ->where('estatus', 'En Proceso')
                    ->count(),
                'active_people' => $this->peopleQuery($supervisor, $alcance)->count(),
            ],
        ]);
    }

    public function people(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);

        $people = $this->peopleQuery($supervisor, $alcance)
            ->with('solicitudAlta.documentacion')
            ->orderBy('punto')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->formatUser($user));

        return response()->json([
            'data' => $people,
            'scope' => $alcance['points'],
        ]);
    }

    public function attendanceIndex(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);

        $records = Asistencia::query()
            ->where('user_id', $supervisor->id)
            ->whereIn('punto', $alcance['aliases'])
            ->when($request->query('fecha'), fn ($query, $fecha) => $query->whereDate('fecha', $fecha))
            ->latest('fecha')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Asistencia $asistencia) => $this->formatAttendance($asistencia));

        return response()->json(['data' => $records]);
    }

    public function attendanceCurrent(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $fecha = $request->query('fecha', now('America/Mexico_City')->toDateString());
        $puntoValor = (string) $request->query('punto', '');
        $configuracion = $this->findAllowedPoint($alcance, $puntoValor) ?? ($alcance['points'][0] ?? null);

        if (! $configuracion) {
            return response()->json([
                'scope' => $alcance['points'],
                'people' => [],
                'attendance' => null,
            ]);
        }

        $attendance = Asistencia::query()
            ->where('user_id', $supervisor->id)
            ->whereDate('fecha', $fecha)
            ->whereIn('punto', $configuracion['alias'])
            ->latest('id')
            ->first();

        $idsGuardados = $attendance ? collect([
            ...$this->decodeArray($attendance->elementos_enlistados),
            ...$this->decodeArray($attendance->faltas),
            ...$this->decodeArray($attendance->descansos),
        ])->map(fn ($id) => (int) $id)->unique()->all() : [];

        $usuariosActuales = User::query()
            ->where('estatus', 'Activo')
            ->where('empresa', $supervisor->empresa)
            ->whereIn(DB::raw('UPPER(TRIM(rol))'), $this->normalizedAttendanceRoles())
            ->whereIn('punto', $configuracion['alias'])
            ->pluck('id')
            ->all();

        $ids = array_values(array_unique([...$usuariosActuales, ...$idsGuardados]));
        $people = User::withTrashed()
            ->with('solicitudAlta.documentacion')
            ->whereIn('id', $ids ?: [0])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->formatUser($user));

        return response()->json([
            'scope' => $alcance['points'],
            'selected_point' => $configuracion,
            'people' => $people,
            'attendance' => $attendance ? $this->formatAttendance($attendance) : null,
        ]);
    }

    public function saveAttendance(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);

        $validated = $request->validate([
            'punto' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'statuses' => ['required'],
            'coberturas' => ['nullable'],
            'observaciones' => ['nullable', 'string', 'max:255'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:5120'],
        ]);

        $configuracion = $this->findAllowedPoint($alcance, $validated['punto']);
        if (! $configuracion) {
            throw ValidationException::withMessages([
                'punto' => 'No tienes permiso para registrar asistencia en este punto.',
            ]);
        }

        $statuses = $this->decodePayload($request->input('statuses'));
        $coberturas = $this->decodePayload($request->input('coberturas', '[]'));
        if (! is_array($statuses) || empty($statuses)) {
            throw ValidationException::withMessages(['statuses' => 'Debes enviar el estatus del personal.']);
        }

        $peopleIds = collect($statuses)->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $allowedPeopleIds = User::query()
            ->where('estatus', 'Activo')
            ->where('empresa', $supervisor->empresa)
            ->whereIn(DB::raw('UPPER(TRIM(rol))'), $this->normalizedAttendanceRoles())
            ->whereIn('punto', $configuracion['alias'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_diff($peopleIds, $allowedPeopleIds)) {
            throw ValidationException::withMessages([
                'statuses' => 'La asistencia contiene personal fuera de tu alcance.',
            ]);
        }

        $allowedSubpointIds = collect($alcance['points'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $coverageSubpoints = collect($coberturas)->pluck('subpunto_id')->filter()->map(fn ($id) => (int) $id)->all();
        if (array_diff($coverageSubpoints, $allowedSubpointIds)) {
            throw ValidationException::withMessages([
                'coberturas' => 'Las coberturas deben pertenecer a tus puntos permitidos.',
            ]);
        }

        $asistentes = [];
        $faltas = [];
        $descansos = [];
        $turnos = [];
        $retardos = [];
        $horasExtra = [];

        foreach ($statuses as $status) {
            $userId = (int) ($status['user_id'] ?? 0);
            $estatus = $status['estatus'] ?? null;
            if (! in_array($estatus, ['asistio', 'falto', 'descanso'], true)) {
                throw ValidationException::withMessages(['statuses' => 'Cada elemento debe tener un estatus valido.']);
            }

            if ($estatus === 'asistio') {
                $asistentes[] = $userId;
                $turnos[$userId] = array_values(array_intersect((array) ($status['turnos'] ?? []), ['dia', 'tarde', 'noche']));
                if (! empty($status['retardo_minutos'])) {
                    $retardos[$userId] = max(1, min(599, (int) $status['retardo_minutos']));
                }
                if (! empty($status['tiempo_extra_horas'])) {
                    $horasExtra[$userId] = [
                        'horas' => min(24, max(0.01, (float) $status['tiempo_extra_horas'])),
                        'observaciones' => trim((string) ($status['tiempo_extra_observaciones'] ?? '')) ?: 'Ninguna',
                    ];
                }
            } elseif ($estatus === 'falto') {
                $faltas[] = $userId;
            } else {
                $descansos[] = $userId;
            }
        }

        $newPaths = [];
        DB::beginTransaction();
        try {
            $record = Asistencia::query()
                ->where('user_id', $supervisor->id)
                ->whereDate('fecha', $validated['fecha'])
                ->whereIn('punto', $configuracion['alias'])
                ->lockForUpdate()
                ->first();

            $photos = $record ? $this->decodeArray($record->fotos_asistentes) : [];
            foreach ($request->file('photos', []) as $userId => $photo) {
                if (! $photo || ! $photo->isValid()) {
                    continue;
                }
                $path = $photo->store('asistencias/'.Str::slug($supervisor->name).'/'.$validated['fecha'], 'public');
                $photos[(string) $userId] = $path;
                $newPaths[] = $path;
            }

            $data = [
                'user_id' => $supervisor->id,
                'fecha' => $validated['fecha'],
                'hora_asistencia' => $record?->hora_asistencia ?? now('America/Mexico_City')->toTimeString(),
                'elementos_enlistados' => json_encode(array_values($asistentes)),
                'faltas' => json_encode(array_values($faltas)),
                'descansos' => json_encode(array_values($descansos)),
                'turnos' => json_encode($this->onlyAttendees($turnos, $asistentes)),
                'fotos_asistentes' => json_encode($photos),
                'observaciones' => trim((string) ($validated['observaciones'] ?? '')) ?: 'Ninguna',
                'coberturas' => json_encode(collect($coberturas)->map(fn ($item) => [
                    'id' => (int) ($item['user_id'] ?? $item['id'] ?? 0),
                    'subpunto_id' => (int) ($item['subpunto_id'] ?? 0),
                ])->filter(fn ($item) => $item['id'] && $item['subpunto_id'])->values()->all()),
                'punto' => $configuracion['nombre'],
                'empresa' => $supervisor->empresa ?? 'PSC',
            ];

            $record ? $record->update($data) : $record = Asistencia::create($data);
            $this->syncRetardos($record, $retardos, $asistentes, $supervisor->id);
            $this->syncOvertime($record, $horasExtra, $asistentes, $supervisor);

            DB::commit();

            return response()->json([
                'message' => 'Asistencia guardada correctamente.',
                'data' => $this->formatAttendance($record->fresh()),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            foreach ($newPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
    }

    public function overtimeIndex(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);

        $records = TiemposExtra::query()
            ->whereHas('user', fn ($query) => $query
                ->where('empresa', $supervisor->empresa)
                ->whereIn('punto', $alcance['aliases']))
            ->with('user')
            ->latest('fecha')
            ->limit(80)
            ->get()
            ->map(fn (TiemposExtra $extra) => [
                'id' => $extra->id,
                'user_id' => $extra->user_id,
                'user_name' => $extra->user?->name,
                'fecha' => $extra->fecha,
                'total_horas' => $extra->total_horas,
                'observaciones' => $extra->observaciones,
                'autorizado_por' => $extra->autorizado_por,
            ]);

        return response()->json(['data' => $records]);
    }

    public function vacationIndex(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);

        $records = $this->vacationsQuery($supervisor, $alcance)
            ->with('user')
            ->latest('created_at')
            ->limit(80)
            ->get()
            ->map(fn (SolicitudVacaciones $solicitud) => [
                'id' => $solicitud->id,
                'user_id' => $solicitud->user_id,
                'user_name' => $solicitud->user?->name,
                'fecha_inicio' => $solicitud->fecha_inicio,
                'fecha_fin' => $solicitud->fecha_fin,
                'dias_solicitados' => $solicitud->dias_solicitados,
                'estatus' => $solicitud->estatus,
                'observaciones' => $solicitud->observaciones,
                'tipo' => $solicitud->tipo,
            ]);

        return response()->json(['data' => $records]);
    }

    public function storeVacation(Request $request, User $user)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $this->assertUserInScope($user->id, $supervisor, $alcance);

        $validated = $request->validate([
            'tipo' => ['required', Rule::in(['Disfrutadas', 'Pagadas'])],
            'periodo' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'fecha_inicio' => ['required', 'date', 'after_or_equal:today'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'dias_solicitados' => ['required', 'integer', 'min:1', 'max:30'],
            'turno_doble' => ['nullable', 'boolean'],
        ]);

        $fechaIngreso = $user->fecha_ingreso ? Carbon::parse($user->fecha_ingreso) : now('America/Mexico_City');
        $antiguedad = (int) floor($fechaIngreso->floatDiffInYears(now('America/Mexico_City')));
        $periodo = array_key_exists('periodo', $validated) && $validated['periodo'] !== null
            ? (int) $validated['periodo']
            : $antiguedad;
        $diasPorDerecho = $this->vacationDaysByPeriod($periodo);

        $diasUtilizados = SolicitudVacaciones::query()
            ->where('user_id', $user->id)
            ->where('periodo', $periodo)
            ->whereIn('estatus', ['Aceptada', 'En Proceso'])
            ->sum('dias_solicitados');

        $diasDisponibles = max(0, $diasPorDerecho - (int) $diasUtilizados);
        if ((int) $validated['dias_solicitados'] > $diasDisponibles) {
            throw ValidationException::withMessages([
                'dias_solicitados' => 'Los dias solicitados exceden los dias disponibles.',
            ]);
        }

        $adminIds = User::query()
            ->whereIn(DB::raw('UPPER(TRIM(rol))'), ['ADMIN', 'ADMINISTRADOR'])
            ->pluck('id')
            ->all();

        $solicitud = SolicitudVacaciones::create([
            'user_id' => $user->id,
            'tipo' => $validated['tipo'],
            'periodo' => $periodo,
            'fecha_inicio' => $validated['fecha_inicio'],
            'fecha_fin' => $validated['fecha_fin'],
            'dias_solicitados' => (int) $validated['dias_solicitados'],
            'dias_ya_utilizados' => (int) $diasUtilizados,
            'dias_disponibles' => $diasDisponibles,
            'dias_por_derecho' => $diasPorDerecho,
            'monto' => 0.0,
            'turno_doble' => ! empty($validated['turno_doble']) ? 'true' : 'false',
            'supervisores_ids' => json_encode($adminIds),
            'estatus' => 'En Proceso',
            'observaciones' => 'Solicitud aceptada, falta subir archivo de solicitud.',
            'autorizado_por' => $supervisor->name,
        ]);

        return response()->json(['message' => 'Solicitud de vacaciones creada.', 'data' => $solicitud], 201);
    }

    public function vacationSummary(Request $request, User $user)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $this->assertUserInScope($user->id, $supervisor, $alcance);

        $validated = $request->validate([
            'periodo' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $fechaIngreso = $user->fecha_ingreso ? Carbon::parse($user->fecha_ingreso) : now('America/Mexico_City');
        $antiguedad = (int) floor($fechaIngreso->floatDiffInYears(now('America/Mexico_City')));
        $periodo = array_key_exists('periodo', $validated) && $validated['periodo'] !== null
            ? (int) $validated['periodo']
            : $antiguedad;
        $diasPorDerecho = $this->vacationDaysByPeriod($periodo);
        $diasUtilizados = (int) SolicitudVacaciones::query()
            ->where('user_id', $user->id)
            ->where('periodo', $periodo)
            ->whereIn('estatus', ['Aceptada', 'En Proceso'])
            ->sum('dias_solicitados');
        $diasDisponibles = max(0, $diasPorDerecho - $diasUtilizados);

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'fecha_ingreso' => $user->fecha_ingreso,
                'antiguedad' => $antiguedad,
                'periodo' => $periodo,
                'dias_por_derecho' => $diasPorDerecho,
                'dias_utilizados' => $diasUtilizados,
                'dias_disponibles' => $diasDisponibles,
            ],
        ]);
    }

    public function vacationKardex(Request $request, User $user)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $this->assertUserInScope($user->id, $supervisor, $alcance);

        $records = SolicitudVacaciones::query()
            ->where('user_id', $user->id)
            ->where('estatus', 'Aceptada')
            ->orderBy('periodo')
            ->orderBy('fecha_inicio')
            ->get()
            ->map(fn (SolicitudVacaciones $vacacion) => [
                'id' => $vacacion->id,
                'periodo' => $vacacion->periodo,
                'tipo' => $vacacion->tipo,
                'fecha_inicio' => $vacacion->fecha_inicio,
                'fecha_fin' => $vacacion->fecha_fin,
                'dias_por_derecho' => (int) $vacacion->dias_por_derecho,
                'dias_disponibles' => (int) $vacacion->dias_disponibles,
                'dias_solicitados' => (int) $vacacion->dias_solicitados,
                'dias_restantes' => (int) $vacacion->dias_disponibles - (int) $vacacion->dias_solicitados,
                'estatus' => $vacacion->estatus,
            ]);

        $summary = $records
            ->groupBy('periodo')
            ->map(fn ($items, $periodo) => [
                'periodo' => $periodo,
                'dias_por_derecho' => (int) ($items->first()['dias_por_derecho'] ?? 0),
                'dias_disponibles' => (int) ($items->first()['dias_disponibles'] ?? 0),
                'dias_solicitados' => (int) $items->sum('dias_solicitados'),
                'dias_restantes' => (int) ($items->first()['dias_disponibles'] ?? 0) - (int) $items->sum('dias_solicitados'),
                'aceptadas' => $items->count(),
            ])
            ->values();

        return response()->json([
            'user' => $this->formatUser($user),
            'data' => $records->values(),
            'summary' => $summary,
        ]);
    }

    public function resolveVacation(Request $request, SolicitudVacaciones $solicitud)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $this->assertUserInScope($solicitud->user_id, $supervisor, $alcance);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['aceptar', 'rechazar'])],
        ]);

        if ($validated['decision'] === 'aceptar') {
            $solicitud->estatus = 'En Proceso';
            $solicitud->observaciones = 'Solicitud aceptada, falta subir archivo de solicitud.';
            $solicitud->autorizado_por = $supervisor->name;
        } else {
            $solicitud->estatus = 'Rechazada';
            $solicitud->observaciones = 'Solicitud de vacaciones rechazada';
        }
        $solicitud->save();

        return response()->json(['message' => 'Solicitud actualizada.', 'data' => $solicitud]);
    }

    public function uploadVacationFile(Request $request, SolicitudVacaciones $solicitud)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $this->assertUserInScope($solicitud->user_id, $supervisor, $alcance);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($solicitud->archivo_solicitud) {
            Storage::disk('public')->delete($solicitud->archivo_solicitud);
        }

        $archivo = $request->file('archivo');
        $ruta = $archivo->storeAs(
            'solicitudesVacaciones/'.$solicitud->id,
            'arch_vacaciones.'.$archivo->getClientOriginalExtension(),
            'public'
        );

        $solicitud->archivo_solicitud = $ruta;
        $solicitud->estatus = 'Aceptada';
        $solicitud->observaciones = 'Solicitud de vacaciones aceptada';
        $solicitud->save();

        return response()->json(['message' => 'Archivo de vacaciones subido.', 'data' => $solicitud]);
    }

    public function cancelVacation(Request $request, SolicitudVacaciones $solicitud)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $this->assertUserInScope($solicitud->user_id, $supervisor, $alcance);

        if ($solicitud->estatus !== 'En Proceso') {
            throw ValidationException::withMessages(['estatus' => 'Solo se pueden cancelar solicitudes en proceso.']);
        }

        $solicitud->estatus = 'Cancelada';
        $solicitud->observaciones = 'Solicitud de vacaciones Cancelada';
        $solicitud->save();

        return response()->json(['message' => 'Solicitud de vacaciones cancelada.', 'data' => $solicitud]);
    }

    public function hiresIndex(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);

        $records = SolicitudAlta::query()
            ->where('solicitante', $supervisor->name)
            ->with('documentacion')
            ->latest('created_at')
            ->limit(80)
            ->get();

        return response()->json(['data' => $records]);
    }

    public function storeHire(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);

        $validated = $request->validate([
            'tipo' => ['required', Rule::in(['oficina', 'armado', 'noarmado'])],
            'name' => ['nullable', 'string', 'max:255'],
            'apellido_paterno' => ['nullable', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'curp' => ['nullable', 'string', 'max:255'],
            'nss' => ['nullable', 'string', 'max:255'],
            'edo_civil' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'calle' => ['nullable', 'string', 'max:255'],
            'num_ext' => ['nullable', 'string', 'max:255'],
            'colonia' => ['nullable', 'string', 'max:255'],
            'cp_fiscal' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'string', 'max:255'],
            'peso' => ['nullable', 'string', 'max:255'],
            'estatura' => ['nullable', 'string', 'max:255'],
            'liga_rfc' => ['nullable', 'string', 'max:255'],
            'infonavit' => ['nullable', 'string', 'max:255'],
            'fonacot' => ['nullable', 'string', 'max:255'],
            'domicilio_comprobante' => ['nullable', 'string', 'max:255'],
            'departamento' => ['nullable', 'string', 'max:255'],
            'rol' => ['nullable', 'string', 'max:255'],
            'punto' => ['nullable', 'string', 'max:255'],
            'reingreso' => ['nullable', 'string', 'max:255'],
            'empresa' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:solicitud_altas,email'],
            'sueldo_mensual' => ['nullable', 'string', 'max:255'],
            'fecha_ingreso' => ['nullable', 'date'],
            'tipo_periodo' => ['nullable', 'string', 'max:255'],
            'banco' => ['nullable', 'string', 'max:255'],
            'cuenta_bancaria' => ['nullable', 'string', 'max:255'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:5120'],
        ]);

        $solicitud = SolicitudAlta::create([
            'solicitante' => $supervisor->name,
            'nombre' => $validated['name'] ?? null,
            'apellido_paterno' => $validated['apellido_paterno'] ?? null,
            'apellido_materno' => $validated['apellido_materno'] ?? null,
            'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? null,
            'tipo_empleado' => $validated['tipo'],
            'curp' => $validated['curp'] ?? null,
            'nss' => $validated['nss'] ?? null,
            'estado_civil' => $validated['edo_civil'] ?? null,
            'rfc' => $validated['rfc'] ?? null,
            'telefono' => $validated['telefono'] ?? null,
            'domicilio_calle' => $validated['calle'] ?? null,
            'domicilio_numero' => $validated['num_ext'] ?? null,
            'domicilio_colonia' => $validated['colonia'] ?? null,
            'cp_fiscal' => $validated['cp_fiscal'] ?? null,
            'domicilio_ciudad' => $validated['ciudad'] ?? null,
            'domicilio_estado' => $validated['estado'] ?? null,
            'peso' => $validated['peso'] ?? null,
            'estatura' => $validated['estatura'] ?? null,
            'liga_rfc' => $validated['liga_rfc'] ?? null,
            'infonavit' => $validated['infonavit'] ?? null,
            'fonacot' => $validated['fonacot'] ?? null,
            'domicilio_comprobante' => $validated['domicilio_comprobante'] ?? null,
            'departamento' => $validated['departamento'] ?? null,
            'rol' => $validated['rol'] ?? null,
            'punto' => $validated['punto'] ?? $supervisor->punto,
            'reingreso' => $validated['reingreso'] ?? null,
            'empresa' => $this->normalizeCompany($validated['empresa'] ?? $supervisor->empresa),
            'email' => $validated['email'] ?? null,
            'sueldo_mensual' => $validated['sueldo_mensual'] ?? null,
            'fecha_ingreso' => $validated['fecha_ingreso'] ?? null,
            'tipo_periodo' => $validated['tipo_periodo'] ?? null,
            'banco' => $validated['banco'] ?? null,
            'cuenta_bancaria' => $validated['cuenta_bancaria'] ?? null,
            'status' => 'En Proceso',
            'observaciones' => 'Solicitud en revision',
        ]);

        $this->storeHireDocuments($request, $solicitud);

        $this->notifyRhNewHireRequest($solicitud, $supervisor);

        return response()->json(['message' => 'Solicitud de alta creada.', 'data' => $solicitud], 201);
    }

    public function updateHire(Request $request, SolicitudAlta $solicitud)
    {
        $supervisor = $this->authorizeSupervisor($request);

        if ($solicitud->solicitante !== $supervisor->name) {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud no pertenece a tu historial.']);
        }

        if (strtoupper(trim((string) $solicitud->status)) !== 'EN PROCESO') {
            throw ValidationException::withMessages(['status' => 'Solo se pueden editar solicitudes en proceso.']);
        }

        $validated = $request->validate([
            'tipo' => ['required', Rule::in(['oficina', 'armado', 'noarmado'])],
            'name' => ['nullable', 'string', 'max:255'],
            'apellido_paterno' => ['nullable', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'curp' => ['nullable', 'string', 'max:255'],
            'nss' => ['nullable', 'string', 'max:255'],
            'edo_civil' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'calle' => ['nullable', 'string', 'max:255'],
            'num_ext' => ['nullable', 'string', 'max:255'],
            'colonia' => ['nullable', 'string', 'max:255'],
            'cp_fiscal' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'string', 'max:255'],
            'peso' => ['nullable', 'string', 'max:255'],
            'estatura' => ['nullable', 'string', 'max:255'],
            'liga_rfc' => ['nullable', 'string', 'max:255'],
            'infonavit' => ['nullable', 'string', 'max:255'],
            'fonacot' => ['nullable', 'string', 'max:255'],
            'domicilio_comprobante' => ['nullable', 'string', 'max:255'],
            'departamento' => ['nullable', 'string', 'max:255'],
            'rol' => ['nullable', 'string', 'max:255'],
            'punto' => ['nullable', 'string', 'max:255'],
            'reingreso' => ['nullable', 'string', 'max:255'],
            'empresa' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('solicitud_altas', 'email')->ignore($solicitud->id)],
            'sueldo_mensual' => ['nullable', 'string', 'max:255'],
            'fecha_ingreso' => ['nullable', 'date'],
            'tipo_periodo' => ['nullable', 'string', 'max:255'],
            'banco' => ['nullable', 'string', 'max:255'],
            'cuenta_bancaria' => ['nullable', 'string', 'max:255'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:5120'],
        ]);

        $solicitud->update([
            'nombre' => $validated['name'] ?? null,
            'apellido_paterno' => $validated['apellido_paterno'] ?? null,
            'apellido_materno' => $validated['apellido_materno'] ?? null,
            'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? null,
            'tipo_empleado' => $validated['tipo'],
            'curp' => $validated['curp'] ?? null,
            'nss' => $validated['nss'] ?? null,
            'estado_civil' => $validated['edo_civil'] ?? null,
            'rfc' => $validated['rfc'] ?? null,
            'telefono' => $validated['telefono'] ?? null,
            'domicilio_calle' => $validated['calle'] ?? null,
            'domicilio_numero' => $validated['num_ext'] ?? null,
            'domicilio_colonia' => $validated['colonia'] ?? null,
            'cp_fiscal' => $validated['cp_fiscal'] ?? null,
            'domicilio_ciudad' => $validated['ciudad'] ?? null,
            'domicilio_estado' => $validated['estado'] ?? null,
            'peso' => $validated['peso'] ?? null,
            'estatura' => $validated['estatura'] ?? null,
            'liga_rfc' => $validated['liga_rfc'] ?? null,
            'infonavit' => $validated['infonavit'] ?? null,
            'fonacot' => $validated['fonacot'] ?? null,
            'domicilio_comprobante' => $validated['domicilio_comprobante'] ?? null,
            'departamento' => $validated['departamento'] ?? null,
            'rol' => $validated['rol'] ?? null,
            'punto' => $validated['punto'] ?? $supervisor->punto,
            'reingreso' => $validated['reingreso'] ?? null,
            'empresa' => $this->normalizeCompany($validated['empresa'] ?? $supervisor->empresa),
            'email' => $validated['email'] ?? null,
            'sueldo_mensual' => $validated['sueldo_mensual'] ?? null,
            'fecha_ingreso' => $validated['fecha_ingreso'] ?? null,
            'tipo_periodo' => $validated['tipo_periodo'] ?? null,
            'banco' => $validated['banco'] ?? null,
            'cuenta_bancaria' => $validated['cuenta_bancaria'] ?? null,
        ]);

        $this->storeHireDocuments($request, $solicitud, true);

        return response()->json([
            'message' => 'Solicitud de alta actualizada.',
            'data' => $solicitud->fresh('documentacion'),
        ]);
    }

    public function terminationsIndex(Request $request)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);

        $records = SolicitudBajas::query()
            ->whereHas('user', fn ($query) => $query
                ->where('empresa', $supervisor->empresa)
                ->whereIn('punto', $alcance['aliases']))
            ->with('user')
            ->latest('created_at')
            ->limit(80)
            ->get();

        return response()->json(['data' => $records]);
    }

    public function storeTermination(Request $request, User $user)
    {
        $supervisor = $this->authorizeSupervisor($request);
        $alcance = $this->resolverAlcance($supervisor);
        $this->assertUserInScope($user->id, $supervisor, $alcance);

        $validated = $request->validate([
            'fecha_baja' => ['required', 'date'],
            'fecha_hoy' => ['nullable', 'date'],
            'incapacidad' => ['nullable', 'string', 'max:255'],
            'por' => ['required', Rule::in(['Ausentismo', 'Separacion Voluntaria', 'Separación Voluntaria', 'Renuncia', 'Otro'])],
            'ultima_asistencia' => ['nullable', 'date'],
            'motivo' => ['nullable', 'string'],
            'adelanto_nomina' => ['nullable', 'string'],
            'descuento' => ['nullable', 'string'],
            'archivo_baja' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'arch_equipo_entregado' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'arch_renuncia' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $solicitud = SolicitudBajas::create([
            'user_id' => $user->id,
            'fecha_solicitud' => $validated['fecha_baja'],
            'fecha_baja' => $validated['fecha_baja'],
            'motivo' => $validated['motivo'] ?? null,
            'adelanto_nomina' => $validated['adelanto_nomina'] ?? null,
            'incapacidad' => $validated['incapacidad'] ?? null,
            'por' => $validated['por'],
            'ultima_asistencia' => $validated['ultima_asistencia'] ?? null,
            'archivo_baja' => ' ',
            'arch_equipo_entregado' => ' ',
            'arch_renuncia' => ' ',
            'descuento' => $validated['descuento'] ?? null,
            'estatus' => 'En Proceso',
            'observaciones' => 'Solicitud en revision',
        ]);

        foreach (['archivo_baja', 'arch_equipo_entregado', 'arch_renuncia'] as $field) {
            if ($request->hasFile($field)) {
                $solicitud->{$field} = $request->file($field)->storeAs(
                    'solicitudesBajas/'.$solicitud->id,
                    $field.'_'.time().'.'.$request->file($field)->getClientOriginalExtension(),
                    'public'
                );
            }
        }
        $solicitud->save();

        $this->notifyRhNewTerminationRequest($solicitud, $user, $supervisor);

        return response()->json(['message' => 'Solicitud de baja creada.', 'data' => $solicitud], 201);
    }

    private function authorizeSupervisor(Request $request): User
    {
        $user = $request->user();
        if (! $user || ! $this->isSupervisorRole($user->rol)) {
            throw new HttpResponseException(response()->json(['message' => 'Modulo exclusivo para supervisores.'], 403));
        }

        return $user;
    }

    private function isSupervisorRole(?string $role): bool
    {
        return str_contains(strtoupper(trim((string) $role)), 'SUPERVISOR');
    }

    private function normalizeCompany(?string $company): ?string
    {
        $value = trim((string) $company);
        if ($value === '') {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $value));
        if (str_contains($normalized, 'PSC')) {
            return 'PSC';
        }

        return $value;
    }

    private function notifyRhNewHireRequest(SolicitudAlta $solicitud, User $supervisor): void
    {
        $candidateName = $this->formatSolicitudAltaName($solicitud) ?: 'Nuevo ingreso';

        RealtimeToast::toRoles(self::RH_NOTIFICATION_ROLES, [
            'icon' => 'success',
            'title' => 'Nueva solicitud de alta',
            'text' => $candidateName.' · enviada por '.($supervisor->name ?? 'supervisor'),
            'url' => '/solicitudes_altas/'.$solicitud->id,
            'key' => 'rh-hire-request:'.$solicitud->id.':created',
            'type' => ToastNotificationLog::TYPE_RH_HIRE_REQUEST,
            'audience' => 'rh',
            'actor_user_id' => $supervisor->id,
        ], (int) $supervisor->id);
    }

    private function notifyRhNewTerminationRequest(SolicitudBajas $solicitud, User $employee, User $supervisor): void
    {
        RealtimeToast::toRoles(self::RH_NOTIFICATION_ROLES, [
            'icon' => 'warning',
            'title' => 'Nueva solicitud de baja',
            'text' => ($employee->name ?? 'Colaborador').' · enviada por '.($supervisor->name ?? 'supervisor'),
            'url' => '/solicitudes_bajas',
            'key' => 'rh-termination-request:'.$solicitud->id.':created',
            'type' => ToastNotificationLog::TYPE_RH_TERMINATION_REQUEST,
            'audience' => 'rh',
            'actor_user_id' => $supervisor->id,
        ], (int) $supervisor->id);
    }

    private function formatSolicitudAltaName(SolicitudAlta $solicitud): string
    {
        return collect([
            $solicitud->nombre,
            $solicitud->apellido_paterno,
            $solicitud->apellido_materno,
        ])->filter()->implode(' ');
    }

    private function vacationDaysByPeriod(int $period): int
    {
        if ($period < 2) {
            return 12;
        }
        if ($period === 2) {
            return 14;
        }
        if ($period === 3) {
            return 16;
        }
        if ($period === 4) {
            return 18;
        }
        if ($period === 5) {
            return 20;
        }
        if ($period <= 10) {
            return 22;
        }
        if ($period <= 15) {
            return 24;
        }
        if ($period <= 20) {
            return 26;
        }
        if ($period <= 25) {
            return 28;
        }
        if ($period <= 30) {
            return 30;
        }

        return 32;
    }

    private function resolverAlcance(User $supervisor): array
    {
        $supervisor->loadMissing('subpuntosSupervisados.punto');
        $base = $supervisor->subpuntosSupervisados->first() ?: $this->findSubpointByValue($supervisor->punto);

        if (! $base) {
            $value = trim((string) $supervisor->punto);
            $points = $value === '' ? [] : [[
                'id' => null,
                'nombre' => $value,
                'codigo' => null,
                'zona' => null,
                'valor' => $value,
                'grupo' => 'Punto asignado',
                'alias' => $this->aliasesForValue($value),
            ]];

            return [
                'base_point' => $value ?: null,
                'zone' => null,
                'message' => $value ? 'Se usara tu punto actual como unico alcance.' : 'No tienes un punto configurado.',
                'points' => $points,
                'aliases' => collect($points)->flatMap(fn ($point) => $point['alias'])->unique()->values()->all(),
            ];
        }

        $subpoints = $base->zona
            ? Subpunto::with('punto')->where('zona', $base->zona)->orderBy('nombre')->get()
            : collect([$base]);

        $points = $subpoints->map(fn (Subpunto $subpunto) => $this->formatSubpoint($subpunto))->values()->all();

        return [
            'base_point' => $base->nombre,
            'zone' => $base->zona,
            'message' => $base->zona
                ? 'Puedes operar puntos de la zona '.$base->zona.'.'
                : 'Solo puedes operar tu punto asignado.',
            'points' => $points,
            'aliases' => collect($points)->flatMap(fn ($point) => $point['alias'])->unique()->values()->all(),
        ];
    }

    private function peopleQuery(User $supervisor, array $alcance)
    {
        return User::query()
            ->where('empresa', $supervisor->empresa)
            ->where('estatus', 'Activo')
            ->whereRaw('UPPER(TRIM(rol)) NOT LIKE ?', ['%SUPERVISOR%'])
            ->whereIn('punto', $alcance['aliases'] ?: ['']);
    }

    private function vacationsQuery(User $supervisor, array $alcance)
    {
        return SolicitudVacaciones::query()
            ->whereHas('user', fn ($query) => $query
                ->where('empresa', $supervisor->empresa)
                ->whereIn('punto', $alcance['aliases'] ?: ['']));
    }

    private function assertUserInScope(int $userId, User $supervisor, array $alcance): void
    {
        $exists = $this->peopleQuery($supervisor, $alcance)->where('id', $userId)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['user_id' => 'El usuario no pertenece a tu alcance.']);
        }
    }

    private function findAllowedPoint(array $alcance, string $value): ?array
    {
        $value = trim(urldecode($value));

        return collect($alcance['points'])->first(
            fn (array $point) => $value === $point['valor'] || in_array($value, $point['alias'], true)
        );
    }

    private function findSubpointByValue(?string $value): ?Subpunto
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return Subpunto::with('punto')
            ->where('nombre', $value)
            ->when(is_numeric($value), fn ($query) => $query->orWhere('codigo', (int) $value))
            ->first();
    }

    private function formatSubpoint(Subpunto $subpunto): array
    {
        $value = $subpunto->codigo !== null
            ? str_pad((string) $subpunto->codigo, 3, '0', STR_PAD_LEFT)
            : $subpunto->nombre;

        return [
            'id' => $subpunto->id,
            'nombre' => $subpunto->nombre,
            'codigo' => $subpunto->codigo,
            'zona' => $subpunto->zona,
            'valor' => $value,
            'grupo' => $subpunto->punto?->nombre ?? 'Puntos',
            'alias' => array_values(array_unique(array_filter([
                $subpunto->nombre,
                $value,
                $subpunto->codigo !== null ? (string) $subpunto->codigo : null,
            ]))),
        ];
    }

    private function aliasesForValue(string $value): array
    {
        return array_values(array_unique(array_filter([
            $value,
            is_numeric($value) ? (string) (int) $value : null,
            is_numeric($value) ? str_pad((string) (int) $value, 3, '0', STR_PAD_LEFT) : null,
        ])));
    }

    private function formatUser(User $user): array
    {
        $user->loadMissing('documentacionAltas');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'rol' => $user->rol,
            'punto' => $user->punto,
            'empresa' => $user->empresa,
            'estatus' => $user->estatus,
            'num_empleado' => $user->num_empleado,
            'fecha_ingreso' => $user->fecha_ingreso,
            'photo_url' => $user->photo_url,
        ];
    }

    private function formatAttendance(Asistencia $attendance): array
    {
        $attendance->loadMissing('retardos', 'tiemposExtra');

        return [
            'id' => $attendance->id,
            'fecha' => $attendance->fecha,
            'hora_asistencia' => $attendance->hora_asistencia,
            'punto' => $attendance->punto,
            'empresa' => $attendance->empresa,
            'asistentes' => $this->decodeArray($attendance->elementos_enlistados),
            'faltas' => $this->decodeArray($attendance->faltas),
            'descansos' => $this->decodeArray($attendance->descansos),
            'turnos' => $this->decodeArray($attendance->turnos),
            'coberturas' => $this->decodeArray($attendance->coberturas),
            'fotos_asistentes' => $this->decodeArray($attendance->fotos_asistentes),
            'observaciones' => $attendance->observaciones,
            'retardos' => $attendance->retardos->mapWithKeys(fn (Retardo $retardo) => [
                $retardo->user_id => $retardo->minutos_retardo,
            ]),
            'tiempos_extra' => $attendance->tiemposExtra->mapWithKeys(fn (TiemposExtra $extra) => [
                $extra->user_id => [
                    'total_horas' => $extra->total_horas,
                    'observaciones' => $extra->observaciones,
                ],
            ]),
        ];
    }

    private function syncRetardos(Asistencia $record, array $retardos, array $asistentes, int $supervisorId): void
    {
        $values = $this->onlyAttendees($retardos, $asistentes);
        Retardo::where('asistencia_id', $record->id)
            ->whereNotIn('user_id', array_map('intval', array_keys($values)) ?: [0])
            ->delete();

        foreach ($values as $userId => $minutes) {
            Retardo::updateOrCreate(
                ['asistencia_id' => $record->id, 'user_id' => (int) $userId],
                ['fecha' => $record->fecha, 'minutos_retardo' => $minutes, 'registrado_por' => $supervisorId]
            );
        }
    }

    private function syncOvertime(Asistencia $record, array $hours, array $asistentes, User $supervisor): void
    {
        $values = $this->onlyAttendees($hours, $asistentes);
        TiemposExtra::where('asistencia_id', $record->id)
            ->whereNotIn('user_id', array_map('intval', array_keys($values)) ?: [0])
            ->delete();

        foreach ($values as $userId => $data) {
            TiemposExtra::updateOrCreate(
                ['asistencia_id' => $record->id, 'user_id' => (int) $userId],
                [
                    'fecha' => $record->fecha,
                    'total_horas' => $this->decimalToTime((float) $data['horas']),
                    'observaciones' => $data['observaciones'] ?? 'Ninguna',
                    'autorizado_por' => $supervisor->name,
                ]
            );
        }
    }

    private function storeHireDocuments(Request $request, SolicitudAlta $solicitud, bool $replaceExisting = false): void
    {
        $documents = $request->file('documents', []);
        if (! is_array($documents) || empty($documents)) {
            return;
        }

        $doc = DocumentacionAltas::firstOrNew(['solicitud_id' => $solicitud->id]);
        foreach ($documents as $field => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            if ($replaceExisting && $doc->{$field}) {
                Storage::disk('public')->delete(preg_replace(
                    '#^/?(?:public/)?storage/#',
                    '',
                    str_replace('\\', '/', $doc->{$field})
                ));
            }

            $doc->{$field} = 'storage/'.$file->storeAs(
                'solicitudesAltas/'.$solicitud->id,
                $field.'.'.$file->getClientOriginalExtension(),
                'public'
            );
        }
        $doc->solicitud_id = $solicitud->id;
        $doc->save();
    }

    private function decodePayload($payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        return json_decode((string) $payload, true) ?: [];
    }

    private function decodeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode($value ?: '[]', true) ?: [];
    }

    private function normalizedAttendanceRoles(): array
    {
        return array_values(array_unique(array_map(fn ($role) => strtoupper(trim($role)), self::ROLES_ASISTENCIA)));
    }

    private function onlyAttendees(array $values, array $asistentes): array
    {
        return collect($values)
            ->filter(fn ($value, $userId) => in_array((int) $userId, array_map('intval', $asistentes), true))
            ->all();
    }

    private function decimalToTime(float $hours): string
    {
        $seconds = (int) round($hours * 3600);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
