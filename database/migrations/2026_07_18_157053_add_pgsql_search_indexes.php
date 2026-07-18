<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL uchun qidiruv va FK indekslari (katta baza uchun muhim).
 *
 *  - pg_trgm GIN indekslari: ILIKE '%...%' (ism qidiruvi) katta jadvalda ham
 *    full-scan qilmasdan tez ishlaydi (NameFilter/SpecialtyFilter).
 *  - FK indekslari: MySQL FK ustunlarini avtomatik indekslaydi, PostgreSQL ЭМАС —
 *    shuning uchun qo'lda qo'shamiz (join/filter tezligi uchun).
 *
 * Faqat pgsql'da ishlaydi; SQLite (test) va boshqa engine'larda o'tkazib yuboriladi.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $trgmColumns = [
        'last_name_cyr', 'first_name_cyr', 'middle_name_cyr',
        'last_name_lat', 'first_name_lat', 'specialty_by_education',
    ];

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // FK индекслари (PG уларни автоматик яратмайди)
        DB::connection('hr')->statement('CREATE INDEX IF NOT EXISTS employees_birth_region_id_index ON employees (birth_region_id)');
        DB::connection('hr')->statement('CREATE INDEX IF NOT EXISTS employees_birth_district_id_index ON employees (birth_district_id)');

        // pg_trgm extension — GIN trigram индекслари учун
        $hasTrgm = $this->ensureTrgm();
        if (! $hasTrgm) {
            return; // рухсат бўлмаса GIN индексларини яратмаймиз (қидирув барибир ILIKE билан ишлайди)
        }

        foreach ($this->trgmColumns as $col) {
            DB::connection('hr')->statement("CREATE INDEX IF NOT EXISTS employees_{$col}_trgm ON employees USING gin ({$col} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('hr')->statement('DROP INDEX IF EXISTS employees_birth_region_id_index');
        DB::connection('hr')->statement('DROP INDEX IF EXISTS employees_birth_district_id_index');

        foreach ($this->trgmColumns as $col) {
            DB::connection('hr')->statement("DROP INDEX IF EXISTS employees_{$col}_trgm");
        }
    }

    private function ensureTrgm(): bool
    {
        $exists = DB::connection('hr')->selectOne("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'") !== null;
        if ($exists) {
            return true;
        }

        try {
            DB::connection('hr')->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            return true;
        } catch (Throwable $e) {
            // Superuser рухсати бўлмаса — DBA қўлда яратади: CREATE EXTENSION pg_trgm;
            return false;
        }
    }
};
