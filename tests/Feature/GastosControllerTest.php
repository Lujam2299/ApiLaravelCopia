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

    private function crearRequest(array $campos): Request
    {
        $request = Request::create('/api/guardarGastos', 'POST', array_merge([
            'Monto' => 250,
            'Fecha' => '2026-07-02',
            'Hora' => '07:30',
        ], $campos), [], [
            'Evidencia' => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

        $usuario = (new User)->forceFill(['id' => 5, 'name' => 'Agente Prueba']);
        $request->setUserResolver(fn () => $usuario);

        return $request;
    }

    private function crearEsquemaGastos(): void
    {
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
            $table->timestamps();
        });
    }
}
