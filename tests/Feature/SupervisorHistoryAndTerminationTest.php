<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesSupervisorTestSchema;
use Tests\TestCase;

class SupervisorHistoryAndTerminationTest extends TestCase
{
    use CreatesSupervisorTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSupervisorTestSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        parent::tearDown();
    }

    public function test_histories_only_return_records_within_supervisor_scope(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $included = $this->createTestUser(['name' => 'Elemento incluido']);
        $outsider = $this->createTestUser(['name' => 'Elemento externo', 'punto' => '099']);
        $attendanceId = DB::table('asistencias')->insertGetId($this->attendanceRow($supervisor->id, '001'));
        DB::table('asistencias')->insert($this->attendanceRow($supervisor->id, '099'));
        DB::table('tiempos_extras')->insert([
            ...$this->overtimeRow($attendanceId, $included->id),
            'observaciones' => 'Incluido',
        ]);
        DB::table('tiempos_extras')->insert([
            ...$this->overtimeRow($attendanceId, $outsider->id),
            'observaciones' => 'Externo',
        ]);

        $this->getJson('/api/supervisores/asistencias')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.punto', '001');

        $this->getJson('/api/supervisores/tiempos-extra')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $included->id);
    }

    public function test_supervisor_can_create_termination_for_person_in_scope(): void
    {
        $this->actingAsSupervisor();
        $person = $this->createTestUser(['name' => 'Persona de baja']);

        $this->postJson("/api/supervisores/bajas/{$person->id}", [
            'fecha_baja' => '2026-08-11',
            'por' => 'Renuncia',
            'motivo' => 'Decisión personal',
        ])->assertCreated()
            ->assertJsonPath('data.estatus', 'En Proceso');

        $this->assertDatabaseHas('solicitud_bajas', [
            'user_id' => $person->id,
            'por' => 'Renuncia',
            'estatus' => 'En Proceso',
        ]);

        $this->getJson('/api/supervisores/bajas')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_supervisor_cannot_terminate_person_outside_scope(): void
    {
        $this->actingAsSupervisor();
        $outsider = $this->createTestUser(['punto' => '099']);

        $this->postJson("/api/supervisores/bajas/{$outsider->id}", [
            'fecha_baja' => '2026-08-11',
            'por' => 'Renuncia',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('solicitud_bajas', 0);
    }

    private function attendanceRow(int $supervisorId, string $point): array
    {
        return [
            'user_id' => $supervisorId,
            'fecha' => '2026-08-11',
            'hora_asistencia' => '08:00:00',
            'elementos_enlistados' => '[]',
            'faltas' => '[]',
            'descansos' => '[]',
            'turnos' => '[]',
            'fotos_asistentes' => '[]',
            'coberturas' => '[]',
            'punto' => $point,
            'empresa' => 'PSC',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function overtimeRow(int $attendanceId, int $userId): array
    {
        return [
            'asistencia_id' => $attendanceId,
            'user_id' => $userId,
            'fecha' => '2026-08-11',
            'total_horas' => '01:00:00',
            'autorizado_por' => 'Supervisora CI',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
