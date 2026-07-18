<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TT бўлим 5А.3 — employee_relatives жадвали (4-блок: Яқин қариндошлар).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('employee_relatives')) {
            return;
        }

        Schema::connection('hr')->create('employee_relatives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_type', 30)->comment('Қариндошлик тури — ENUM');
            $table->string('full_name_cyr', 255)->comment('Тўлиқ Ф.И.Ш. — инициаллар таъқиқланган');
            $table->smallInteger('birth_year')->comment('Туғилган йили');
            $table->string('birth_place', 255)->comment('Туғилган жойи — қисқартиришсиз');
            $table->boolean('is_deceased')->default(false);
            $table->smallInteger('deceased_year')->nullable()->comment('Вафот этган йили');
            $table->text('workplace_and_position')->comment('Иш жойи ва лавозими');
            $table->text('residence_full')->comment('Яшаш манзили — тўлиқ');
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('employee_relatives');
    }
};
