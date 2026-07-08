<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mision_cierres_operativos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mision_id')->constrained('misiones')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('fecha');
            $table->text('resumen');
            $table->text('novedades')->nullable();
            $table->text('incidencias')->nullable();
            $table->text('pendientes')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('client_operation_id', 100)->nullable()->unique();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();

            $table->unique(['mision_id', 'user_id', 'fecha'], 'mision_cierre_usuario_fecha_unique');
            $table->index(['mision_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mision_cierres_operativos');
    }
};
