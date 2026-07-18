<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('appeal_categories')) {
            return;
        }

        // O'ziga-havola qiluvchi FK — PostgreSQL uchun jadval (PK bilan) avval,
        // keyin FK alohida. SQLite ALTER ADD FK'ni qo'llamaydi — unда inline.
        $isSqlite = Schema::connection('hr')->getConnection()->getDriverName() === 'sqlite';

        Schema::connection('hr')->create('appeal_categories', function (Blueprint $table) use ($isSqlite) {
            $table->uuid('id')->primary();
            if ($isSqlite) {
                $table->foreignUuid('parent_id')->nullable()->constrained('appeal_categories')->nullOnDelete();
            } else {
                $table->uuid('parent_id')->nullable();
            }
            $table->string('code', 50)->unique();
            $table->string('name_cyr', 255);
            $table->string('name_lat', 255)->nullable();
            $table->unsignedInteger('default_sla_hours')->default(168); // 7 kun
            $table->string('default_route_type', 50)->nullable(); // council/department/dept_id
            $table->string('icon', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('parent_id');
            $table->index('is_active');
        });

        if (! $isSqlite) {
            Schema::connection('hr')->table('appeal_categories', function (Blueprint $table) {
                $table->foreign('parent_id')->references('id')->on('appeal_categories')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('appeal_categories');
    }
};
