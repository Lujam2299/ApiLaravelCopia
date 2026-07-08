<?php

namespace Tests\Feature;

use App\Http\Controllers\MisionCierreOperativoController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MisionCierreOperativoControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->crearEsquema();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function test_registra_un_cierre_operativo(): void
    {
        $misionId = $this->crearMision([5], 'En Curso');

        $response = app(MisionCierreOperativoController::class)->store(
            $this->crearRequest(),
            $misionId
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('mision_cierres_operativos', [
            'mision_id' => $misionId,
            'user_id' => 5,
            'fecha' => '2026-07-02',
            'resumen' => 'Servicio sin contratiempos.',
        ]);
    }

    public function test_actualiza_el_cierre_del_mismo_dia_sin_duplicar(): void
    {
        $misionId = $this->crearMision([5], 'En Curso');

        app(MisionCierreOperativoController::class)->store($this->crearRequest(), $misionId);
        $response = app(MisionCierreOperativoController::class)->store($this->crearRequest([
            'resumen' => 'Servicio actualizado.',
            'novedades' => 'Cambio de ruta.',
        ]), $misionId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseCount('mision_cierres_operativos', 1);
        $this->assertDatabaseHas('mision_cierres_operativos', [
            'resumen' => 'Servicio actualizado.',
            'novedades' => 'Cambio de ruta.',
        ]);
    }

    public function test_rechaza_usuario_no_asignado(): void
    {
        $misionId = $this->crearMision([9], 'En Curso');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(MisionCierreOperativoController::class)->store($this->crearRequest(), $misionId);
    }

    public function test_rechaza_mision_finalizada(): void
    {
        $misionId = $this->crearMision([5], 'Finalizada');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(MisionCierreOperativoController::class)->store($this->crearRequest(), $misionId);
    }

    public function test_rechaza_fecha_fuera_del_periodo(): void
    {
        $misionId = $this->crearMision([5], 'En Curso');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(MisionCierreOperativoController::class)->store($this->crearRequest([
            'fecha' => '2026-08-01',
        ]), $misionId);
    }

    private function crearRequest(array $campos = []): Request
    {
        $request = Request::create('/api/misiones/1/cierres-operativos', 'POST', array_merge([
            'fecha' => '2026-07-02',
            'resumen' => 'Servicio sin contratiempos.',
            'novedades' => null,
            'incidencias' => null,
            'pendientes' => null,
            'observaciones' => null,
            'client_operation_id' => 'closure-test-' . uniqid(),
            'client_created_at' => '2026-07-02T18:00:00Z',
        ], $campos));

        $usuario = (new User)->forceFill(['id' => 5, 'name' => 'Agente Prueba']);
        $request->setUserResolver(fn () => $usuario);

        return $request;
    }

    private function crearMision(array $agentes, string $estatus): int
    {
        return (int) \DB::table('misiones')->insertGetId([
            'agentes_id' => json_encode($agentes),
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-05',
            'estatus' => $estatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crearEsquema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('misiones', function (Blueprint $table): void {
            $table->id();
            $table->json('agentes_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estatus')->nullable();
            $table->timestamps();
        });

        Schema::create('mision_cierres_operativos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('mision_id');
            $table->unsignedBigInteger('user_id');
            $table->date('fecha');
            $table->text('resumen');
            $table->text('novedades')->nullable();
            $table->text('incidencias')->nullable();
            $table->text('pendientes')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('client_operation_id', 100)->nullable()->unique();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();
        });
    }
}
