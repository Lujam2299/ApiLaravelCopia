<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

trait CreatesSupervisorTestSchema
{
    protected function createSupervisorTestSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('rol')->nullable();
            $table->string('punto')->nullable();
            $table->string('empresa')->nullable();
            $table->string('estatus')->nullable();
            $table->string('telefono')->nullable();
            $table->string('num_empleado')->nullable();
            $table->unsignedBigInteger('sol_alta_id')->nullable();
            $table->unsignedBigInteger('sol_docs_id')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('puntos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('zona')->nullable();
            $table->timestamps();
        });

        Schema::create('subpuntos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('punto_id')->nullable();
            $table->string('nombre');
            $table->integer('codigo')->nullable();
            $table->string('zona')->nullable();
            $table->string('siglas')->nullable();
            $table->text('roles')->nullable();
            $table->timestamps();
        });

        Schema::create('supervisorpuntos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supervisor_id');
            $table->unsignedBigInteger('subpunto_id');
        });

        Schema::create('asistencias', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('fecha');
            $table->time('hora_asistencia')->nullable();
            $table->text('elementos_enlistados')->nullable();
            $table->text('faltas')->nullable();
            $table->text('descansos')->nullable();
            $table->text('turnos')->nullable();
            $table->text('fotos_asistentes')->nullable();
            $table->string('observaciones')->nullable();
            $table->text('coberturas')->nullable();
            $table->string('punto');
            $table->string('empresa')->nullable();
            $table->timestamps();
        });

        Schema::create('retardos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('asistencia_id');
            $table->date('fecha');
            $table->integer('minutos_retardo');
            $table->unsignedBigInteger('registrado_por');
            $table->timestamps();
        });

        Schema::create('tiempos_extras', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asistencia_id');
            $table->unsignedBigInteger('user_id');
            $table->date('fecha');
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->time('total_horas');
            $table->string('autorizado_por')->nullable();
            $table->string('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('solicitud_vacaciones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('estatus')->nullable();
            $table->timestamps();
        });

        Schema::create('solicitud_altas', function (Blueprint $table): void {
            $table->id();
            $table->string('solicitante');
            $table->string('nombre')->nullable();
            $table->string('apellido_paterno')->nullable();
            $table->string('apellido_materno')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->string('tipo_empleado');
            $table->string('curp')->nullable();
            $table->string('nss')->nullable();
            $table->string('estado_civil')->nullable();
            $table->string('rfc')->nullable();
            $table->string('telefono')->nullable();
            $table->string('domicilio_calle')->nullable();
            $table->string('domicilio_numero')->nullable();
            $table->string('domicilio_colonia')->nullable();
            $table->string('domicilio_ciudad')->nullable();
            $table->string('domicilio_estado')->nullable();
            $table->string('cp_fiscal')->nullable();
            $table->string('peso')->nullable();
            $table->string('estatura')->nullable();
            $table->string('liga_rfc')->nullable();
            $table->string('infonavit')->nullable();
            $table->string('fonacot')->nullable();
            $table->string('domicilio_comprobante')->nullable();
            $table->string('departamento')->nullable();
            $table->string('rol')->nullable();
            $table->string('punto')->nullable();
            $table->string('reingreso')->nullable();
            $table->string('empresa')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('sueldo_mensual')->nullable();
            $table->string('tipo_periodo')->nullable();
            $table->string('banco')->nullable();
            $table->string('cuenta_bancaria')->nullable();
            $table->string('status')->nullable();
            $table->string('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('documentacion_altas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('solicitud_id')->nullable();
            $table->string('arch_foto')->nullable();
            $table->timestamps();
        });
    }

    protected function createTestUser(array $attributes = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Usuario de prueba',
            'email' => uniqid('user-', true).'@example.test',
            'rol' => 'GUARDIA',
            'punto' => '001',
            'empresa' => 'PSC',
            'estatus' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return User::query()->findOrFail($id);
    }

    protected function actingAsSupervisor(array $attributes = []): User
    {
        $supervisor = $this->createTestUser(array_merge([
            'name' => 'Supervisora CI',
            'rol' => 'SUPERVISOR',
        ], $attributes));

        Sanctum::actingAs($supervisor);

        return $supervisor;
    }
}
