<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('council_members')) {
            return;
        }

        Schema::connection('hr')->create('council_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('council_id')->constrained('mahalla_councils')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name', 255);
            $table->enum('role', [
                'rais',           // Mahalla raisi
                'imom',           // Imom-xatib
                'yoshlar',        // Yoshlar yetakchisi
                'ayollar',        // ATEM (ayollar yetakchisi)
                'posbon',         // Posbon (PPN)
                'maktab',         // Maktab vakili
                'soliq',          // Soliq inspektori / iqtisod vakili
                'boshqa',
            ]);
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('council_id');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('council_members');
    }
};
