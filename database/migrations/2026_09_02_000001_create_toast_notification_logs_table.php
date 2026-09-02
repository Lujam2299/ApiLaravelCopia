<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('toast_notification_logs')) {
            return;
        }

        Schema::create('toast_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('generic')->index();
            $table->string('icon')->nullable();
            $table->string('title');
            $table->text('text')->nullable();
            $table->string('url')->nullable();
            $table->string('key')->nullable()->index();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience')->default('private')->index();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->index(['recipient_user_id', 'read_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toast_notification_logs');
    }
};
