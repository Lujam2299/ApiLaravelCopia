<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table): void {
            $table->string('Categoria', 50)->nullable()->after('Tipo');
            $table->string('Metodo_pago', 30)->nullable()->after('Categoria');
            $table->string('Descripcion')->nullable()->after('Metodo_pago');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table): void {
            $table->dropColumn(['Categoria', 'Metodo_pago', 'Descripcion']);
        });
    }
};
