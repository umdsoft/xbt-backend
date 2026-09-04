<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADVISOR — UNUMDORLIK INDEKSLARI (unumdorlik auditi natijasi).
 *
 * `advisor` schema. Naqsh — advisor 2/3/4-bosqich migratsiyalari:
 *   - Faqat PostgreSQL; boshqa drayver -> skip.
 *   - Forward-only, idempotent: CREATE INDEX IF NOT EXISTS (kompozit/qisman/GIN).
 *
 * Qamrab olinadi:
 *   - feed/ro'yxat saralash kalitlari (updated_at/occurred_at/submitted_at);
 *   - overdue/yaqin-muddat/oversight so'rovlari (task_targets kompozit);
 *   - KPI matritsa/xulosa/yil filtri (period + district);
 *   - soft-delete filtri (projects qisman indeks);
 *   - arxiv/topshiriq matnli qidiruv (pg_trgm GIN — ilike '%...%' uchun).
 */
return new class extends Migration
{
    /** Oddiy/kompozit/qisman indekslar (har doim xavfsiz — pg_trgm shart emas). */
    private const INDEXES = [
        // Overdue/yaqin-muddat/oversight — tuman kesimi + holat + muddat.
        'CREATE INDEX IF NOT EXISTS task_targets_district_status_due_idx ON advisor.task_targets (district_id, status, due_at)',
        // Feed/ro'yxat saralash kalitlari.
        'CREATE INDEX IF NOT EXISTS projects_updated_at_idx ON advisor.projects (updated_at)',
        'CREATE INDEX IF NOT EXISTS project_updates_occurred_at_idx ON advisor.project_updates (occurred_at)',
        'CREATE INDEX IF NOT EXISTS task_reports_submitted_at_idx ON advisor.task_reports (submitted_at)',
        'CREATE INDEX IF NOT EXISTS kpi_entries_updated_at_idx ON advisor.kpi_entries (updated_at)',
        // Matritsa/xulosa/yil filtri (period + district).
        'CREATE INDEX IF NOT EXISTS kpi_entries_period_district_idx ON advisor.kpi_entries (period, district_id)',
        'CREATE INDEX IF NOT EXISTS kpi_targets_period_district_idx ON advisor.kpi_targets (period, district_id)',
        // Soft-delete filtri (whereNull('deleted_at')) — faqat tirik satrlar.
        'CREATE INDEX IF NOT EXISTS projects_not_deleted_idx ON advisor.projects (district_id) WHERE deleted_at IS NULL',
    ];

    /** pg_trgm GIN indekslari (ilike '%...%' matnli qidiruv). Extension talab qiladi. */
    private const TRGM_INDEXES = [
        'CREATE INDEX IF NOT EXISTS tasks_title_trgm_idx ON advisor.tasks USING gin (title gin_trgm_ops)',
        'CREATE INDEX IF NOT EXISTS tasks_description_trgm_idx ON advisor.tasks USING gin (description gin_trgm_ops)',
        'CREATE INDEX IF NOT EXISTS task_reports_body_trgm_idx ON advisor.task_reports USING gin (body gin_trgm_ops)',
    ];

    /** Barcha indeks nomlari (down uchun). */
    private const INDEX_NAMES = [
        'task_targets_district_status_due_idx',
        'projects_updated_at_idx',
        'project_updates_occurred_at_idx',
        'task_reports_submitted_at_idx',
        'kpi_entries_updated_at_idx',
        'kpi_entries_period_district_idx',
        'kpi_targets_period_district_idx',
        'projects_not_deleted_idx',
        'tasks_title_trgm_idx',
        'tasks_description_trgm_idx',
        'task_reports_body_trgm_idx',
    ];

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = DB::connection('advisor');

        foreach (self::INDEXES as $sql) {
            $conn->statement($sql);
        }

        // pg_trgm — matnli qidiruv (arxiv/topshiriq/hisobot). Extension superuser
        // talab qilishi mumkin; bu bazada app foydalanuvchisi DB egasi -> ishlaydi.
        // Muvaffaqiyatsiz bo'lsa jurnalga yozib DAVOM etamiz (migrate abort bo'lmasin).
        if ($this->ensureTrgm($conn)) {
            foreach (self::TRGM_INDEXES as $sql) {
                $conn->statement($sql);
            }
        }
    }

    /**
     * pg_trgm extension'ni ta'minlaydi (bor bo'lsa — tasdiqlaydi). Yaratib bo'lmasa
     * jurnalga yozadi va false qaytaradi (GIN indekslar o'tkazib yuboriladi).
     *
     * @param  Connection  $conn
     */
    private function ensureTrgm($conn): bool
    {
        try {
            $conn->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning(
                "advisor unumdorlik: pg_trgm extension yaratib bo'lmadi (superuser kerak?) — ".
                'trgm GIN indekslar o\'tkazib yuborildi: '.$e->getMessage()
            );
        }

        return $conn->selectOne("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'") !== null;
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = DB::connection('advisor');
        foreach (self::INDEX_NAMES as $idx) {
            $conn->statement("DROP INDEX IF EXISTS advisor.{$idx}");
        }
    }
};
