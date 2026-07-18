<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TT бўлим 5А.1 — employees жадвали.
 * 1-БЛОК (Сарлавҳа) + 2-БЛОК (Шахсий маълумотлар) майдонлари.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('employees')) {
            return;
        }

        Schema::connection('hr')->create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            // ===== 1-БЛОК: Сарлавҳа =====
            $table->string('last_name_cyr', 50)->comment('Фамилияси (Кирилл)');
            $table->string('first_name_cyr', 50)->comment('Исми (Кирилл)');
            $table->string('middle_name_cyr', 50)->comment('Отасининг исми (Кирилл)');
            $table->string('last_name_lat', 50)->nullable()->comment('Фамилияси (Лотин) — автотранслит');
            $table->string('first_name_lat', 50)->nullable()->comment('Исми (Лотин)');
            $table->string('middle_name_lat', 50)->nullable()->comment('Отасининг исми (Лотин)');
            $table->text('current_position')->comment('Ҳозирги лавозими — тўлиқ');
            $table->date('position_start_date')->nullable()->comment('Лавозимга тайинланган сана');
            $table->string('photo_path', 255)->nullable()->comment('3×4 расм (JPG/PNG, max 2 MB)');

            // ===== 2-БЛОК: Шахсий маълумотлар =====
            $table->date('birth_date')->comment('Туғилган санаси (мин. 16 ёш)');
            $table->string('birth_place', 255)->nullable()->comment('Туғилган жойи — тўлиқ, қисқартиришсиз');
            $table->foreignUuid('birth_region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignUuid('birth_district_id')->constrained('districts')->restrictOnDelete();
            $table->string('nationality', 50)->comment('Миллати — каталогдан');
            $table->string('party_affiliation', 100)->default('йўқ')->comment('Партиявийлиги');
            $table->enum('education_level', [
                'олий', 'тугалланмаган олий', 'ўрта махсус', 'ўрта',
            ])->comment('Маълумоти даражаси');
            $table->text('education_completion')->comment('Қаерни тамомлаган (йил, ОТМ, шакли)');
            $table->string('specialty_by_education', 255)->comment('Маълумоти бўйича мутахассислиги');
            $table->string('academic_degree', 100)->default('йўқ')->comment('Илмий даражаси');
            $table->string('academic_title', 100)->default('йўқ')->comment('Илмий унвони');
            $table->string('foreign_languages', 500)->default('йўқ')->comment('Чет тиллари (мукаммал)');
            $table->string('state_awards', 500)->default('тақдирланмаган')->comment('Давлат мукофотлари');
            $table->string('elected_body_member', 500)->default('йўқ')->comment('Сайланадиган органлар аъзолиги');

            // ===== Махфий (шифрланган) =====
            // ДИҚҚАТ: шифрланган қиймат ~200-228 белги бўлгани учун TEXT керак.
            // varchar(14/2/7) STRICT режимда "Data too long" хатоси берарди.
            $table->text('jshshir')->nullable()->comment('ЖШШИР — шифрланган');
            // Дубликатни аниқлаш учун деттерминистик HMAC хеши (шифрматн ноаниқ бўлгани сабабли).
            $table->string('jshshir_hash', 64)->nullable()->unique()->comment('ЖШШИР HMAC-SHA256 хеши — уникаллик учун');
            $table->text('passport_series')->nullable()->comment('Паспорт серияси — шифрланган');
            $table->text('passport_number')->nullable()->comment('Паспорт рақами — шифрланган');

            // ===== Хизмат майдонлари =====
            $table->foreignUuid('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained()->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ===== Индекслар =====
            $table->index('last_name_cyr');
            $table->index('birth_date');
            $table->index('department_id');
            $table->index('position_id');
            // FULLTEXT фақат MySQL да ишлайди (SQLite test да ўтказиб юборилади)
            if (Schema::connection('hr')->getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['last_name_cyr', 'first_name_cyr', 'middle_name_cyr']);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('employees');
    }
};
