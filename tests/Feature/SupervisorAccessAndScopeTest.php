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
