<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('departments')) {
            return;
        }

        // O'ziga-havola qiluvchi FK'ni PostgreSQL to'g'ri qabul qilishi uchun jadval
        // (PK bilan) avval to'liq yaratiladi, keyin FK alohida qo'shiladi. SQLite esa
        // ALTER ADD FOREIGN KEY'ni qo'llab-quvvatlamaydi — unда inline yaratamiz.
        $isSqlite = Schema::connection('hr')->getConnection()->getDriverName() === 'sqlite';

        Schema::connection('hr')->create('departments', function (Blueprint $table) use ($isSqlite) {
            $table->uuid('id')->primary();
            if ($isSqlite) {
                $table->foreignUuid('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            } else {
                $table->uuid('parent_id')->nullable();
            }
            $table->string('name_cyr', 255)->comment('Бўлим номи (Кирилл)');
            $table->string('name_lat', 255)->comment('Bo\'lim nomi (Lotin)');
            $table->string('code', 20)->nullable()->unique()->comment('Ички код');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('name_cyr');
            $table->index('parent_id');
        });

        // restrictOnDelete — o'rta daraja (kompleks) o'chirilsa bolalari NULL bo'lib
        // "yangi tenant"ga aylanmasligi uchun (tenant izolyatsiyasi).
        if (! $isSqlite) {
            Schema::connection('hr')->table('departments', function (Blueprint $table) {
                $table->foreign('parent_id')->references('id')->on('departments')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('departments');
    }
};
