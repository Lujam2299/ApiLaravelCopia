<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_positions', function (Blueprint $table) {
            $table->string('client_operation_id', 100)
                ->nullable()
                ->unique()
                ->after('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('realtime_positions', function (Blueprint $table) {
            $table->dropUnique(['client_operation_id']);
            $table->dropColumn('client_operation_id');
        });
    }
};
