<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MUROJAAT domeni poydevori — `murojaat` schema + profiles/import_sessions/appeals/audit_log.
 *
 * Fuqarolar murojaatlari monitoring/tahlil tizimi (MurojAAT). Excel'dan import →
 * normalizatsiya → DB → tahlil. Advisor domeni AYNAN naqsh: schema jadvallardan
 * OLDIN yaratiladi; faqat PostgreSQL; mavjud jadval -> skip (idempotent, forward-only).
 *
 * district_id -> master.districts (cross-schema FK YO'Q; uuid + index — monitoring naqshi,
 * bulk import uchun xavfsiz). auth.users FK yo'q (auth alohida schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('murojaat')->statement('CREATE SCHEMA IF NOT EXISTS murojaat');
        $schema = Schema::connection('murojaat');

        // Profil — markaziy auth.users bilan user_id orqali; district (viloyat = null).
        $this->create($schema, 'profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();          // = auth.users.id (FK yo'q)
            $t->string('level', 20);                // viloyat | tuman
            $t->uuid('district_id')->nullable();    // master.districts (viloyat: null)
            $t->string('position')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index('district_id');
        });

        // Import sessiyasi — har Excel yuklash. is_active = joriy tahlil to'plami.
        $this->create($schema, 'import_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('district_id')->nullable()->index();
            $t->string('file_name', 500)->nullable();
            $t->integer('records_count')->default(0);
            $t->integer('sayyor_count')->default(0);
            $t->uuid('imported_by')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });

        // Murojaatlar — 44 xom maydon (Excel) + serverda hisoblangan (tahlil tez).
        $this->create($schema, 'appeals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('session_id')->index();
            $t->uuid('district_id')->nullable();

            // --- xom maydonlar (Excel kirill -> maydon) ---
            $t->string('tr', 32)->nullable();
            $t->string('murojaat_raqami')->nullable();
            $t->string('masala_raqami')->nullable();
            $t->string('qaerdan')->nullable();               // manba
            $t->string('kelgan_sana')->nullable();           // xom matn
            $t->integer('muddat_kun')->default(30);
            $t->text('nazoratchi')->nullable();
            $t->text('yuqori_tashkilot')->nullable();
            $t->text('ijrochi')->nullable();
            $t->string('murojaat_turi')->nullable();
            $t->string('jamoaviy', 16)->nullable();
            $t->string('yashash_hudud')->nullable();
            $t->string('yashash_tuman')->nullable();
            $t->string('sektor')->nullable();
            $t->string('mahalla')->nullable();
            $t->text('manzil')->nullable();
            $t->string('fuqaro_id')->nullable();
            $t->string('familiya')->nullable();
            $t->string('ism')->nullable();
            $t->string('otasi_ismi')->nullable();
            $t->string('telefon', 64)->nullable();
            $t->string('jinsi', 32)->nullable();
            $t->string('tugilgan_sana')->nullable();
            $t->string('bandlik')->nullable();
            $t->string('soha')->nullable();
            $t->text('yonalish')->nullable();
            $t->text('masala')->nullable();
            $t->string('natija_toifa')->nullable();
            $t->string('natija_holat')->nullable();          // xom
            $t->string('javob_kiritilgan')->nullable();
            $t->string('javob_yuborilgan')->nullable();
            $t->string('javob_tasdiqlangan')->nullable();
            $t->integer('korib_chiqish_kun')->default(0);
            $t->integer('kechikish_30dan')->default(0);
            $t->integer('kechikib_yopilgan')->default(0);
            $t->integer('kechikib_30dan')->default(0);
            $t->string('takroriylik')->nullable();
            $t->text('kiritgan_tashkilot')->nullable();
            $t->text('sayyor_tashkilot')->nullable();
            $t->string('sayyor_rahbar')->nullable();
            $t->string('rahbar_lavozim')->nullable();
            $t->string('ijrochi_hudud')->nullable();
            $t->string('ijrochi_tuman')->nullable();
            $t->string('pinfl', 32)->nullable();

            // --- serverda hisoblangan (normalizatsiya) ---
            $t->string('natija_holat_norm', 16)->nullable(); // hal|kechikkan|jarayon|rad|yonaltirildi
            $t->boolean('is_kechikkan')->default(false);
            $t->boolean('is_sayyor')->default(false);
            $t->string('manba_type', 8)->nullable();         // pvq|xq|boshqa
            $t->integer('kun_otgan')->default(0);
            $t->date('kelgan_sana_d')->nullable();
            $t->integer('kelgan_yil')->nullable();
            $t->smallInteger('kelgan_oy')->nullable();
            $t->string('stat_holat', 16)->nullable();        // ijobiy|huquqiy|...
            $t->timestamps();

            $t->index(['session_id', 'is_kechikkan']);
            $t->index(['session_id', 'natija_holat_norm']);
            $t->index(['session_id', 'mahalla']);
            $t->index(['session_id', 'ijrochi']);
            $t->index(['session_id', 'is_sayyor']);
            $t->index(['session_id', 'manba_type']);
            $t->index(['kelgan_yil', 'kelgan_oy']);
        });

        // Audit jurnali (import/eksport/kirish).
        $this->create($schema, 'audit_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->string('action', 64);
            $t->text('details')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function create($schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (['audit_log', 'appeals', 'import_sessions', 'profiles'] as $table) {
            Schema::connection('murojaat')->dropIfExists($table);
        }
    }
};
