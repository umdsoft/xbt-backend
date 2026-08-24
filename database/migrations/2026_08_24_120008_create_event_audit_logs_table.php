<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tadbir audit jurnali — har o'zgarish (guruh/belgilash/pechat) yoziladi:
 * kim, nima amal, payload. Nazorat va tiklash uchun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('event_audit_logs')) {
            return;
        }

        Schema::connection('hr')->create('event_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->jsonb('payload_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('event_id');
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('event_audit_logs');
    }
};
