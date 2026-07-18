<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HONADON — mas'ul xodim ↔ ko'cha biriktiruvi. Ko'cha (master) operatsion tarzda
 * xodimga bog'lanadi. Xodim faqat o'z ko'chasi honadonlarini yuklaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('mahalla')->hasTable('street_assignments')) {
            return;
        }

        Schema::connection('mahalla')->create('street_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('street_id')->constrained('streets')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['street_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('mahalla')->dropIfExists('street_assignments');
    }
};
