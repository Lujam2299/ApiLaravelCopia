<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesSupervisorTestSchema;
use Tests\TestCase;

class SupervisorAttendanceTest extends TestCase
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

    public function test_supervisor_can_save_and_update_attendance_without_duplicates(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $present = $this->createTestUser(['name' => 'Elemento presente']);
        $absent = $this->createTestUser(['name' => 'Elemento ausente']);

        $payload = [
            'punto' => '001',
            'fecha' => '2026-08-11',
            'statuses' => [
                [
                    'user_id' => $present->id,
                    'estatus' => 'asistio',
                    'turnos' => ['dia'],
                    'retardo_minutos' => 12,
                    'tiempo_extra_horas' => 1.5,
                    'tiempo_extra_observaciones' => 'Cobertura',
                ],
                ['user_id' => $absent->id, 'estatus' => 'falto'],
            ],
        ];

        $this->postJson('/api/supervisores/asistencias', $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'Asistencia guardada correctamente.');

        $this->assertDatabaseHas('asistencias', [
            'user_id' => $supervisor->id,
            'fecha' => '2026-08-11',
            'punto' => '001',
        ]);
        $this->assertDatabaseHas('retardos', [
            'user_id' => $present->id,
            'minutos_retardo' => 12,
        ]);
        $this->assertDatabaseHas('tiempos_extras', [
            'user_id' => $present->id,
            'total_horas' => '01:30:00',
        ]);

        $payload['statuses'] = [
            ['user_id' => $present->id, 'estatus' => 'descanso'],
            ['user_id' => $absent->id, 'estatus' => 'asistio', 'turnos' => ['noche']],
        ];
        $this->postJson('/api/supervisores/asistencias', $payload)->assertCreated();

        $this->assertDatabaseCount('asistencias', 1);
        $this->assertDatabaseMissing('retardos', ['user_id' => $present->id]);
        $this->assertDatabaseMissing('tiempos_extras', ['user_id' => $present->id]);
    }

    public function test_attendance_rejects_people_outside_supervisor_scope(): void
    {
        $this->actingAsSupervisor();
        $outsider = $this->createTestUser(['punto' => '099']);

        $this->postJson('/api/supervisores/asistencias', [
            'punto' => '001',
            'fecha' => '2026-08-11',
            'statuses' => [
                ['user_id' => $outsider->id, 'estatus' => 'asistio', 'turnos' => ['dia']],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('statuses');

        $this->assertDatabaseCount('asistencias', 0);
    }

    public function test_attendance_rejects_an_unauthorized_point(): void
    {
        $this->actingAsSupervisor();

        $this->postJson('/api/supervisores/asistencias', [
            'punto' => '099',
            'fecha' => '2026-08-11',
            'statuses' => [
                ['user_id' => 1, 'estatus' => 'asistio'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('punto');
    }
}
