<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesSupervisorTestSchema;
use Tests\TestCase;

class SupervisorAccessAndScopeTest extends TestCase
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

    public function test_supervisor_routes_require_authentication(): void
    {
        $this->getJson('/api/supervisores/dashboard')->assertUnauthorized();
    }

    public function test_non_supervisor_cannot_access_module(): void
    {
        Sanctum::actingAs($this->createTestUser(['rol' => 'GUARDIA']));

        $this->getJson('/api/supervisores/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'Modulo exclusivo para supervisores.');
    }

    public function test_dashboard_and_people_respect_company_and_zone_scope(): void
    {
        $supervisor = $this->actingAsSupervisor(['punto' => '001']);
        $puntoId = DB::table('puntos')->insertGetId([
            'nombre' => 'Zona Centro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $baseId = $this->createSubpoint($puntoId, 'Punto Uno', 1, 'CENTRO');
        $this->createSubpoint($puntoId, 'Punto Dos', 2, 'CENTRO');
        DB::table('supervisorpuntos')->insert([
            'supervisor_id' => $supervisor->id,
            'subpunto_id' => $baseId,
        ]);

        $includedOne = $this->createTestUser(['name' => 'Elemento Uno', 'punto' => '001']);
        $includedTwo = $this->createTestUser(['name' => 'Elemento Dos', 'punto' => '002']);
        $this->createTestUser(['name' => 'Otra empresa', 'punto' => '001', 'empresa' => 'OTRA']);
        $this->createTestUser(['name' => 'Fuera de zona', 'punto' => '099']);
        $this->createTestUser(['name' => 'Otro supervisor', 'punto' => '001', 'rol' => 'SUPERVISOR']);

        $this->getJson('/api/supervisores/dashboard')
            ->assertOk()
            ->assertJsonPath('access', true)
            ->assertJsonPath('counters.active_people', 2)
            ->assertJsonCount(2, 'scope.points');

        $response = $this->getJson('/api/supervisores/personal')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$includedOne->id, $includedTwo->id], $ids);
    }

    public function test_supervisor_people_accept_role_and_psc_company_variants(): void
    {
        $this->actingAsSupervisor(['empresa' => 'PSC', 'punto' => '001']);

        $guard = $this->createTestUser(['name' => 'Guardia operativo', 'rol' => 'Guardia Operativo', 'empresa' => 'P.S.C.', 'punto' => '001']);
        $escort = $this->createTestUser(['name' => 'Escolta', 'rol' => 'Escolta', 'empresa' => 'PSC Seguridad', 'punto' => '001']);
        $this->createTestUser(['name' => 'Supervisor operativo', 'rol' => 'Supervisor Operativo', 'empresa' => 'PSC', 'punto' => '001']);
        $this->createTestUser(['name' => 'Otra empresa', 'rol' => 'Guardia Operativo', 'empresa' => 'OTRA', 'punto' => '001']);

        $peopleResponse = $this->getJson('/api/supervisores/personal')->assertOk();
        $peopleIds = collect($peopleResponse->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$guard->id, $escort->id], $peopleIds);

        $attendanceResponse = $this->getJson('/api/supervisores/asistencias/actual?fecha=2026-08-11&punto=001')->assertOk();
        $attendanceIds = collect($attendanceResponse->json('people'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$guard->id, $escort->id], $attendanceIds);
    }

    public function test_supervisor_scope_prefers_hire_request_supervisor_zone(): void
    {
        $puntoId = DB::table('puntos')->insertGetId([
            'nombre' => 'Zona Norte',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createSubpoint($puntoId, 'Punto Uno', 1, 'NORTE');
        $this->createSubpoint($puntoId, 'Punto Dos', 2, 'NORTE');
        $this->createSubpoint($puntoId, 'Punto Sur', 3, 'SUR');

        $solicitudId = DB::table('solicitud_altas')->insertGetId([
            'solicitante' => 'RH',
            'tipo_empleado' => 'operativo',
            'zona_supervisor' => 'NORTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsSupervisor([
            'sol_alta_id' => $solicitudId,
            'punto' => '099',
        ]);

        $includedOne = $this->createTestUser(['name' => 'Elemento Zona Norte Uno', 'punto' => '001']);
        $includedTwo = $this->createTestUser(['name' => 'Elemento Zona Norte Dos', 'punto' => '002']);
        $this->createTestUser(['name' => 'Elemento Fuera de Zona', 'punto' => '003']);

        $response = $this->getJson('/api/supervisores/personal')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$includedOne->id, $includedTwo->id], $ids);
        $this->assertSame('NORTE', $response->json('scope.0.zona'));
        $this->assertCount(2, $response->json('scope'));
    }

    public function test_monitoristas_are_excluded_from_people_and_operations(): void
    {
        $this->actingAsSupervisor(['punto' => '001']);
        $guard = $this->createTestUser(['punto' => '001']);
        foreach (['MONITORISTA', ' monitorista ', 'Monitorista'] as $role) {
            $monitor = $this->createTestUser(['rol' => $role, 'punto' => '001']);
            $this->postJson('/api/supervisores/asistencias', [
                'punto' => '001', 'fecha' => '2026-09-02',
                'statuses' => [['user_id' => $monitor->id, 'estatus' => 'asistio']],
            ])->assertUnprocessable()->assertJsonValidationErrors('statuses');
            $this->getJson('/api/supervisores/vacaciones/usuario/'.$monitor->id.'/resumen')
                ->assertUnprocessable()->assertJsonValidationErrors('user_id');
            $this->postJson('/api/supervisores/asistencias', [
                'punto' => '001', 'fecha' => '2026-09-02',
                'statuses' => [['user_id' => $guard->id, 'estatus' => 'asistio']],
                'coberturas' => [['user_id' => $monitor->id, 'subpunto_id' => 1]],
            ])->assertUnprocessable()->assertJsonValidationErrors('coberturas');
        }
        $this->getJson('/api/supervisores/personal')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $guard->id);
        $this->getJson('/api/supervisores/dashboard')->assertOk()
            ->assertJsonPath('counters.active_people', 1);
        $this->getJson('/api/supervisores/asistencias/actual?punto=001')->assertOk()
            ->assertJsonCount(1, 'people')->assertJsonPath('people.0.id', $guard->id);
        $this->assertDatabaseCount('asistencias', 0);
    }

    public function test_saved_attendance_hides_monitoristas_without_changing_history(): void
    {
        $supervisor = $this->actingAsSupervisor(['punto' => '001']);
        $guard = $this->createTestUser(['punto' => '001', 'estatus' => 'Inactivo']);
        $monitor = $this->createTestUser(['rol' => ' monitorista ', 'punto' => '001']);
        $monitor->delete();
        $record = [
            'user_id' => $supervisor->id, 'fecha' => '2026-09-02', 'punto' => '001',
            'elementos_enlistados' => json_encode([$guard->id, $monitor->id]),
            'faltas' => json_encode([$monitor->id]),
            'descansos' => json_encode([$monitor->id]),
            'turnos' => json_encode([$guard->id => ['dia'], $monitor->id => ['noche']]),
            'fotos_asistentes' => json_encode([$monitor->id => 'private/photo.jpg']),
            'coberturas' => json_encode([['id' => $monitor->id, 'subpunto_id' => 1]]),
        ];
        $id = DB::table('asistencias')->insertGetId($record);
        DB::table('retardos')->insert([
            'user_id' => $monitor->id, 'asistencia_id' => $id, 'fecha' => '2026-09-02',
            'minutos_retardo' => 5, 'registrado_por' => $supervisor->id,
        ]);
        DB::table('tiempos_extras')->insert([
            'user_id' => $monitor->id, 'asistencia_id' => $id, 'fecha' => '2026-09-02',
            'total_horas' => '01:00:00',
        ]);
        $before = (array) DB::table('asistencias')->find($id);
        $response = $this->getJson('/api/supervisores/asistencias/actual?fecha=2026-09-02&punto=001')
            ->assertOk()->assertJsonCount(1, 'people')->assertJsonPath('people.0.id', $guard->id);
        $history = $this->getJson('/api/supervisores/asistencias?fecha=2026-09-02')->assertOk();
        foreach ([$response->json('attendance'), $history->json('data.0')] as $data) {
            $this->assertSame([$guard->id], $data['asistentes']);
            foreach (['faltas', 'descansos', 'coberturas', 'fotos_asistentes', 'retardos', 'tiempos_extra'] as $field) {
                $this->assertEmpty($data[$field]);
            }
            $this->assertArrayNotHasKey($monitor->id, $data['turnos']);
        }
        $this->assertSame($before, (array) DB::table('asistencias')->find($id));
        $this->assertDatabaseCount('retardos', 1);
        $this->assertDatabaseCount('tiempos_extras', 1);
    }

    private function createSubpoint(int $puntoId, string $name, int $code, string $zone): int
    {
        return (int) DB::table('subpuntos')->insertGetId([
            'punto_id' => $puntoId,
            'nombre' => $name,
            'codigo' => $code,
            'zona' => $zone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
