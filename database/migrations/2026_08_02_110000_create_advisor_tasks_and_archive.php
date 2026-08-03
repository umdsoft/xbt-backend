<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADVISOR — 2-bosqich: TOPSHIRIQLAR + ARXIV (spec §4, §5).
 *
 * `advisor` schema (poydevor migratsiyasi yaratgan). Naqsh — mahalla domeni:
 *   - Faqat PostgreSQL; boshqa drayver -> skip (SQLite testi ham yo'q shu domenda).
 *   - Forward-only, idempotent: mavjud jadval -> o'sha jadval o'tkazib yuboriladi.
 *
 * district_id -> master.districts (advisor ulanishi search_path'ida master bor,
 * shuning uchun 'districts' master.districts'ga resolve bo'ladi — advisors
 * migratsiyasidagi kabi). advisor_id/created_by/uploaded_by -> auth.users yoki
 * advisor.advisors (cross-schema FK YO'Q; mahalla naqshi — uuid + index).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('advisor')->statement('CREATE SCHEMA IF NOT EXISTS advisor');

        $schema = Schema::connection('advisor');

        // Arxiv/qidiruv kategoriyalari ("Prezident topshirig'i", "Vazirlik so'rovi"...).
        $this->create($schema, 'task_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // Topshiriq (yuqori tashkilotdan). Viloyat maslahatchisi yaratadi.
        $this->create($schema, 'tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('source')->nullable();          // yuqori tashkilot nomi
            $t->string('title');
            $t->text('description')->nullable();
            $t->text('expected_result')->nullable();
            $t->uuid('category_id')->nullable()->index();
            $t->string('priority', 12)->default('normal'); // low|normal|high
            $t->date('deadline')->nullable();
            $t->uuid('created_by')->nullable();         // auth.users.id
            $t->string('status', 16)->default('open');  // open|in_progress|closed
            $t->timestamps();

            $t->index('status');
            $t->index('created_at');
        });

        // Topshiriq nishoni: bitta tumanga tarqatilgan ijro birligi.
        $this->create($schema, 'task_targets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('task_id')->index();
            $t->uuid('district_id')->index();           // master.districts
            $t->uuid('assigned_advisor_id')->nullable(); // advisor.advisors (ixtiyoriy)
            $t->timestampTz('due_at')->nullable();
            // pending|reported|qa_checked|approved|returned|closed
            $t->string('status', 16)->default('pending');
            $t->timestamps();

            // Bir topshiriq bir tumanga bir marta.
            $t->unique(['task_id', 'district_id']);
            $t->index('status');
        });

        // Tuman maslahatchisining hisoboti (body + dalil). Bir target -> N hisobot
        // (qaytarilsa qayta yuboriladi); QA/tasdiq oxirgi hisobot ustida.
        $this->create($schema, 'task_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('task_target_id')->index();
            $t->uuid('advisor_id')->nullable()->index(); // advisor.advisors
            $t->text('body')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            // pending|qa_checked|approved|returned
            $t->string('status', 16)->default('pending');
            $t->unsignedTinyInteger('evidence_index')->nullable(); // hozir qo'lda / keyin AI
            $t->jsonb('ai_evaluation')->nullable();                 // kelajak uchun bo'sh joy
            $t->timestamps();

            $t->index('status');
        });

        // Hisobot dalili: fayl | foto | havola | GPS (moslashuvchan).
        $this->create($schema, 'report_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('report_id')->index();
            $t->string('kind', 10);                     // file|photo|link|gps
            $t->string('path')->nullable();             // file/photo
            $t->string('url')->nullable();              // link
            $t->jsonb('geo')->nullable();               // gps {lat,lng,accuracy}
            $t->string('original_name')->nullable();
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->uuid('uploaded_by')->nullable();        // auth.users.id
            $t->timestamps();
        });

        // Sifat-tekshiruv / tasdiq / qaytarish audit izi.
        $this->create($schema, 'task_reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('report_id')->index();
            $t->uuid('reviewer_id')->nullable();        // auth.users.id
            $t->string('action', 12);                   // qa_check|approve|return
            $t->text('comment')->nullable();
            $t->timestamps();
        });

        // Arxiv teglari (erkin qidiruv). Pivot: (task_id, tag).
        $this->create($schema, 'task_tags', function (Blueprint $t) {
            $t->uuid('task_id');
            $t->string('tag', 60);

            $t->primary(['task_id', 'tag']);
            $t->index('tag');
        });

        $this->seedCategories();
    }

    /**
     * Jadvalni faqat mavjud bo'lmasa yaratadi (forward-only, qayta ishga tushishga chidamli).
     *
     * @param  Builder  $schema
     */
    private function create($schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }

    /**
     * Boshlang'ich kategoriyalar (arxiv/qidiruv uchun) — idempotent.
     */
    private function seedCategories(): void
    {
        $categories = [
            'Президент топшириғи',
            'Ҳукумат топшириғи',
            'Вазирлик сўрови',
            'Вилоят ҳокими топшириғи',
            'Рейтинг',
            'Бошқа',
        ];

        $now = now();
        foreach ($categories as $i => $name) {
            $exists = DB::connection('advisor')->table('task_categories')
                ->where('name', $name)->exists();

            if ($exists) {
                continue;
            }

            DB::connection('advisor')->table('task_categories')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'sort_order' => ($i + 1) * 10,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach ([
            'task_tags', 'task_reviews', 'report_files',
            'task_reports', 'task_targets', 'tasks', 'task_categories',
        ] as $table) {
            Schema::connection('advisor')->dropIfExists($table);
        }
    }
};
