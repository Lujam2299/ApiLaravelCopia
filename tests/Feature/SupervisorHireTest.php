<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesSupervisorTestSchema;
use Tests\TestCase;

class SupervisorHireTest extends TestCase
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

    public function test_supervisor_can_create_and_list_own_hire_request(): void
    {
        $this->actingAsSupervisor(['name' => 'Supervisora Norte']);

        $this->postJson('/api/supervisores/altas', [
            'tipo' => 'armado',
            'name' => 'Nueva Persona',
            'email' => 'alta@example.test',
        ])->assertCreated()
            ->assertJsonPath('data.solicitante', 'Supervisora Norte')
            ->assertJsonPath('data.status', 'En Proceso');

        $this->assertDatabaseHas('solicitud_altas', [
            'solicitante' => 'Supervisora Norte',
            'nombre' => 'Nueva Persona',
            'tipo_empleado' => 'armado',
            'status' => 'En Proceso',
        ]);

        $this->getJson('/api/supervisores/altas')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_hire_request_rejects_invalid_type_and_duplicate_email(): void
    {
        $this->actingAsSupervisor();

        $this->postJson('/api/supervisores/altas', [
            'tipo' => 'invalido',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tipo');

        DB::table('solicitud_altas')->insert([
            'solicitante' => 'Otra persona',
            'tipo_empleado' => 'oficina',
            'email' => 'duplicado@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/supervisores/altas', [
            'tipo' => 'oficina',
            'email' => 'duplicado@example.test',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_supervisor_can_only_edit_own_pending_hire_request(): void
    {
        $this->actingAsSupervisor(['name' => 'Supervisora CI']);
        $ownId = $this->createHire('Supervisora CI', 'En Proceso');
        $otherId = $this->createHire('Otra Supervisora', 'En Proceso');
        $closedId = $this->createHire('Supervisora CI', 'Aceptada');

        $this->postJson("/api/supervisores/altas/{$ownId}", [
            'tipo' => 'noarmado',
            'name' => 'Nombre actualizado',
        ])->assertOk();
        $this->assertDatabaseHas('solicitud_altas', [
            'id' => $ownId,
            'nombre' => 'Nombre actualizado',
            'tipo_empleado' => 'noarmado',
        ]);

        $this->postJson("/api/supervisores/altas/{$otherId}", [
            'tipo' => 'armado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('solicitud');

        $this->postJson("/api/supervisores/altas/{$closedId}", [
            'tipo' => 'armado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function createHire(string $requester, string $status): int
    {
        return (int) DB::table('solicitud_altas')->insertGetId([
            'solicitante' => $requester,
            'tipo_empleado' => 'oficina',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
