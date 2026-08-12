<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesSupervisorTestSchema;
use Tests\TestCase;

class SupervisorVacationTest extends TestCase
{
    use CreatesSupervisorTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSupervisorTestSchema();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        parent::tearDown();
    }

    public function test_supervisor_can_create_vacation_and_read_summary(): void
    {
        $this->actingAsSupervisor();
        $person = $this->createTestUser([
            'name' => 'Persona vacaciones',
            'fecha_ingreso' => now()->subYears(2)->toDateString(),
        ]);
        $start = now()->addDays(10)->toDateString();
        $end = now()->addDays(12)->toDateString();

        $this->postJson("/api/supervisores/vacaciones/usuario/{$person->id}", [
            'tipo' => 'Disfrutadas',
            'periodo' => 2,
            'fecha_inicio' => $start,
            'fecha_fin' => $end,
            'dias_solicitados' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.estatus', 'En Proceso')
            ->assertJsonPath('data.dias_por_derecho', 14);

        $this->getJson("/api/supervisores/vacaciones/usuario/{$person->id}/resumen?periodo=2")
            ->assertOk()
            ->assertJsonPath('data.dias_utilizados', 3)
            ->assertJsonPath('data.dias_disponibles', 11);
    }

    public function test_vacation_rejects_excess_days_and_people_outside_scope(): void
    {
        $this->actingAsSupervisor();
        $person = $this->createTestUser();
        $outsider = $this->createTestUser(['punto' => '099']);
        $payload = [
            'tipo' => 'Pagadas',
            'periodo' => 1,
            'fecha_inicio' => now()->addDays(10)->toDateString(),
            'fecha_fin' => now()->addDays(25)->toDateString(),
            'dias_solicitados' => 13,
        ];

        $this->postJson("/api/supervisores/vacaciones/usuario/{$person->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dias_solicitados');

        $payload['dias_solicitados'] = 2;
        $this->postJson("/api/supervisores/vacaciones/usuario/{$outsider->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_supervisor_can_upload_file_and_read_accepted_vacation_kardex(): void
    {
        $this->actingAsSupervisor();
        $person = $this->createTestUser();
        $vacationId = $this->createVacation($person->id, 'En Proceso');

        $this->post("/api/supervisores/vacaciones/{$vacationId}/archivo", [
            'archivo' => UploadedFile::fake()->create('vacaciones.pdf', 20, 'application/pdf'),
        ])->assertOk()
            ->assertJsonPath('data.estatus', 'Aceptada');

        Storage::disk('public')->assertExists("solicitudesVacaciones/{$vacationId}/arch_vacaciones.pdf");

        $this->getJson("/api/supervisores/vacaciones/usuario/{$person->id}/kardex")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'summary');
    }

    public function test_only_pending_vacation_can_be_cancelled(): void
    {
        $this->actingAsSupervisor();
        $person = $this->createTestUser();
        $pendingId = $this->createVacation($person->id, 'En Proceso');
        $acceptedId = $this->createVacation($person->id, 'Aceptada');

        $this->postJson("/api/supervisores/vacaciones/{$pendingId}/cancelar")
            ->assertOk()
            ->assertJsonPath('data.estatus', 'Cancelada');

        $this->postJson("/api/supervisores/vacaciones/{$acceptedId}/cancelar")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estatus');
    }

    private function createVacation(int $userId, string $status): int
    {
        return (int) DB::table('solicitud_vacaciones')->insertGetId([
            'user_id' => $userId,
            'periodo' => 1,
            'dias_por_derecho' => 12,
            'dias_disponibles' => 12,
            'dias_solicitados' => 2,
            'fecha_inicio' => now()->addDays(10)->toDateString(),
            'fecha_fin' => now()->addDays(11)->toDateString(),
            'tipo' => 'Disfrutadas',
            'estatus' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
