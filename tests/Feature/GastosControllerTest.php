<?php

namespace Tests\Feature;

use App\Http\Controllers\Gastos\GastosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GastosControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->crearEsquemaGastos();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function test_guarda_la_clasificacion_de_un_viatico(): void
    {
        $response = app(GastosController::class)->guardarGastos($this->crearRequest([
            'Tipo' => 'Viaticos',
            'Categoria' => 'peaje',
            'Metodo_pago' => 'tag',
            'Descripcion' => 'Caseta Querétaro',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('gastos', [
            'mision_id' => 1,
            'Tipo' => 'Viaticos',
            'Categoria' => 'peaje',
            'Metodo_pago' => 'tag',
            'Descripcion' => 'Caseta Querétaro',
        ]);
    }

    public function test_asigna_categoria_gasolina_y_conserva_metodo_de_pago(): void
    {
        $response = app(GastosController::class)->guardarGastos($this->crearRequest([
            'Tipo' => 'Gasolina',
            'Metodo_pago' => 'tarjeta',
            'Km' => 12500,
            'Gasolina_antes_carga' => 2,
            'Gasolina_despues_carga' => 8,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('gastos', [
            'Tipo' => 'Gasolina',
            'Categoria' => 'gasolina',
            'Metodo_pago' => 'tarjeta',
        ]);
    }

    public function test_rechaza_otros_sin_descripcion(): void
    {
        $response = app(GastosController::class)->guardarGastos($this->crearRequest([
            'Tipo' => 'Viaticos',
            'Categoria' => 'otros',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('Descripcion', $response->getData(true)['errors']);
    }

    public function test_acepta_viatico_sin_clasificacion_de_una_version_anterior(): void
    {
        $response = app(GastosController::class)->guardarGastos($this->crearRequest([
            'Tipo' => 'Viaticos',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('gastos', [
            'Tipo' => 'Viaticos',
            'Categoria' => null,
        ]);
    }

    public function test_rechaza_una_mision_ajena(): void
    {
        $this->crearMision([9], 'En Curso');

        $response = app(GastosController::class)->guardarGastos($this->crearRequest([
            'Tipo' => 'Viaticos',
        ], false));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('mision_id', $response->getData(true)['errors']);
    }

    public function test_requiere_una_mision(): void
    {
        $request = $this->crearRequest(['Tipo' => 'Viaticos']);
        $request->request->remove('mision_id');

        $response = app(GastosController::class)->guardarGastos($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('mision_id', $response->getData(true)['errors']);
    }

    public function test_rechaza_gastos_fuera_del_periodo_de_la_mision(): void
    {
        $response = app(GastosController::class)->guardarGastos($this->crearRequest([
            'Tipo' => 'Viaticos',
            'Fecha' => '2026-08-15',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('Fecha', $response->getData(true)['errors']);
    }

    public function test_rechaza_gastos_de_una_mision_finalizada(): void
    {
        $this->crearMision([5], 'Finalizada');

        $response = app(GastosController::class)->guardarGastos($this->crearRequest([
            'Tipo' => 'Viaticos',
        ], false));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('mision_id', $response->getData(true)['errors']);
    }

    private function crearRequest(array $campos, bool $crearMision = true): Request
    {
        $misionId = $crearMision ? $this->crearMision([5], 'En Curso') : 1;

        $request = Request::create('/api/guardarGastos', 'POST', array_merge([
            'Monto' => 250,
            'Fecha' => '2026-07-02',
            'Hora' => '07:30',
            'mision_id' => $misionId,
        ], $campos), [], [
            'Evidencia' => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

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

    private function crearEsquemaGastos(): void
    {
        Schema::create('misiones', function (Blueprint $table): void {
            $table->id();
            $table->json('agentes_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estatus')->nullable();
            $table->timestamps();
        });

        Schema::create('gastos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('Monto', 10, 2);
            $table->date('Fecha');
            $table->time('Hora');
            $table->string('Evidencia');
            $table->string('Tipo');
            $table->string('Categoria')->nullable();
            $table->string('Metodo_pago')->nullable();
            $table->string('Descripcion')->nullable();
            $table->string('user_name')->nullable();
            $table->decimal('Km', 10, 2)->nullable();
            $table->decimal('Gasolina_antes_carga', 10, 2)->nullable();
            $table->decimal('Gasolina_despues_carga', 10, 2)->nullable();
            $table->string('client_operation_id')->nullable();
            $table->unsignedBigInteger('mision_id')->nullable();
            $table->timestamps();
        });
    }
}
