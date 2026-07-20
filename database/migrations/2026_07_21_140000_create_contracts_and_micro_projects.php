<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ижтимоий шартнома ва микролойиҳа.
 *
 * Ikkalasi ham obodonlashtirishdan MUSTAQIL: ish bajarilgani surat va AI
 * tahlili orqali aniqlanadi, shartnoma bor-yo'qligiga qaramasdan. Ular
 * xonadon orqali bog'lanadi, sabab-oqibat bilan emas.
 *
 * Shartnomada SUMMA ustuni yo'q — foydalanuvchi qarori. Bu yerda shartnoma
 * hujjat sifatida saqlanadi, moliyaviy hisob vositasi sifatida emas.
 *
 * Turlar va yo'nalishlar `master` dagi ma'lumotnomada: yangi tur qo'shish
 * bitta qator, deploy emas. `object_types` da shu naqsh o'zini oqladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $master = Schema::connection('master');
        $mahalla = Schema::connection('mahalla');

        $master->create('contract_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $master->create('project_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $mahalla->create('social_contracts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('house_id')->nullable()->index();
            $t->uuid('mahalla_id')->index();
            $t->uuid('district_id')->index();
            $t->uuid('contract_type_id')->nullable();
            $t->string('contract_number', 60);
            $t->date('signed_at')->nullable();
            $t->string('status', 24)->default('signed');
            $t->text('notes')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            // Raqam mahalla ichida takrorlanmasligi kerak. Tuman bo'yicha emas:
            // har mahalla o'z raqamlashini yuritadi.
            $t->unique(['mahalla_id', 'contract_number']);
        });

        $mahalla->create('contract_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('contract_id')->index();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 120);
            $t->unsignedInteger('size_bytes');
            $t->uuid('uploaded_by')->nullable();
            $t->timestamps();
        });

        $mahalla->create('micro_projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('mahalla_id')->index();
            $t->uuid('district_id')->index();
            $t->uuid('category_id')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->date('planned_start')->nullable();
            $t->date('planned_end')->nullable();
            $t->date('actual_end')->nullable();
            $t->string('status', 24)->default('planned');
            $t->unsignedTinyInteger('progress_percent')->default(0);

            // Ixtiyoriy bog'lanish: maktab/QVP ta'miri yoki ko'cha ishi.
            $t->uuid('object_building_id')->nullable()->index();
            $t->uuid('street_id')->nullable()->index();

            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $mahalla->create('micro_project_updates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->index();
            $t->uuid('user_id')->nullable();
            $t->text('body');
            $t->unsignedTinyInteger('progress_percent')->nullable();
            $t->timestampTz('occurred_at');
            $t->timestamps();
        });

        $mahalla->create('micro_project_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->index();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 120);
            $t->unsignedInteger('size_bytes');
            $t->uuid('uploaded_by')->nullable();
            $t->timestamps();
        });

        $this->seedReference('contract_types', [
            ['ish_bilan_bandlik', 'Иш билан бандлик'],
            ['tadbirkorlik', 'Тадбиркорлик'],
            ['kasb_organish', 'Касб ўрганиш'],
            ['uy_joy_sharoiti', 'Уй-жой шароити'],
            ['boshqa', 'Бошқа'],
        ]);

        $this->seedReference('project_categories', [
            ['yol', 'Йўл ва кўча'],
            ['suv', 'Ичимлик суви'],
            ['elektr', 'Электр таъминоти'],
            ['gaz', 'Газ таъминоти'],
            ['ijtimoiy_obyekt', 'Ижтимоий объект таъмири'],
            ['obodonlashtirish', 'Ободонлаштириш'],
            ['boshqa', 'Бошқа'],
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private function seedReference(string $table, array $rows): void
    {
        $now = now();
        DB::connection('master')->table($table)->insert(array_map(
            fn (array $r, int $i) => [
                'id' => (string) Str::uuid(),
                'code' => $r[0],
                'name_cyr' => $r[1],
                'sort_order' => ($i + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
            array_keys($rows),
        ));
    }

    public function down(): void
    {
        foreach (['micro_project_files', 'micro_project_updates', 'micro_projects',
            'contract_files', 'social_contracts'] as $t) {
            Schema::connection('mahalla')->dropIfExists($t);
        }
        foreach (['project_categories', 'contract_types'] as $t) {
            Schema::connection('master')->dropIfExists($t);
        }
    }
};
