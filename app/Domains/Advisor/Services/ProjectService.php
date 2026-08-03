<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\Project;
use App\Domains\Advisor\Models\ProjectFile;
use App\Domains\Advisor\Models\ProjectUpdate;
use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LOYIHALAR moduli (spec §6) — mahalla `micro_projects` naqshi advisor uchun.
 *
 * Qamrov (AdvisorScope) HAR joyda: viloyat/bo'linma barcha tumanni ko'radi,
 * tuman FAQAT o'z tumanini ko'radi/tahrir qiladi (IDOR himoyasi — advisor Task*
 * naqshi). Yaratish/tahrir: viloyat (istalgan tuman) yoki tuman (o'z tumani).
 *
 * Barcha yozuv `advisor` ulanishida; district nomlari master.districts'dan
 * (advisor search_path'ida master bor). Foydalanuvchi FIO auth.users'dan.
 */
class ProjectService
{
    /** Loyiha holatlari. */
    public const STATUSES = ['planned', 'in_progress', 'done', 'paused'];

    private function disk(): string
    {
        return (string) config('advisor.files_disk', 'local');
    }

    // --------------------------------------------------------------- ro'yxat

    /**
     * Loyihalar ro'yxati — qamrov + filtr bilan sahifalab.
     *
     * @param  array<string, mixed>  $filters  district_id,status,q
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listProjects(AdvisorScope $scope, array $filters, int $page = 1, int $perPage = 20): array
    {
        // Tuman FAQAT o'z tumani; viloyat/bo'linma filtr bergan tumani (yoki hammasi).
        $districtId = $scope->isTuman() ? $scope->districtId : ($filters['district_id'] ?? null);

        // Tuman tumansiz (profil to'liq emas) — hech narsa ko'rmaydi.
        if ($scope->isTuman() && $districtId === null) {
            return ['data' => [], 'meta' => $this->meta(0, 1, $perPage)];
        }

        $base = fn () => DB::connection('advisor')->table('projects as p')
            ->whereNull('p.deleted_at')
            ->when($districtId !== null, fn ($q) => $q->where('p.district_id', $districtId))
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('p.status', $filters['status']))
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.$filters['q'].'%';
                $q->where(fn ($x) => $x->where('p.title', 'ilike', $like)
                    ->orWhere('p.description', 'ilike', $like));
            });

        $total = (int) $base()->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $rows = $base()
            ->leftJoin('districts as d', 'd.id', '=', 'p.district_id')
            ->orderByRaw("array_position(array['in_progress','planned','paused','done']::text[], p.status)")
            ->orderByDesc('p.updated_at')
            ->forPage($page, $perPage)
            ->get([
                'p.id', 'p.district_id', 'p.title', 'p.status',
                'p.progress_percent', 'p.planned_end', 'p.updated_at',
                'd.name_cyr as district_name',
            ]);

        return [
            'data' => $rows->map(fn ($p) => [
                'id' => $p->id,
                'district' => ['id' => $p->district_id, 'name' => $p->district_name],
                'title' => $p->title,
                'status' => $p->status,
                'progress_percent' => (int) $p->progress_percent,
                'planned_end' => $p->planned_end,
                'updated_at' => Carbon::parse($p->updated_at)->toIso8601String(),
            ])->all(),
            'meta' => $this->meta($total, $page, $perPage),
        ];
    }

    // --------------------------------------------------------------- yaratish

    /**
     * Yangi loyiha.
     *
     * @param  array<string, mixed>  $data
     * @return string yaratilgan loyiha id
     */
    public function createProject(array $data, string $creatorUserId): string
    {
        $project = Project::create([
            'district_id' => $data['district_id'],
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'planned_start' => $data['planned_start'] ?? null,
            'planned_end' => $data['planned_end'] ?? null,
            'status' => $data['status'] ?? 'planned',
            'progress_percent' => 0,
            'created_by' => $creatorUserId,
        ]);

        return $project->id;
    }

    // ----------------------------------------------------------------- ko'rish

    /**
     * Bitta loyiha — progress tarixi (updates) + fayllar. Qamrov: tuman boshqa
     * tuman loyihasini ochsa null (404).
     *
     * @return array<string, mixed>|null
     */
    public function showProject(string $projectId, AdvisorScope $scope): ?array
    {
        $p = DB::connection('advisor')->table('projects as p')
            ->leftJoin('districts as d', 'd.id', '=', 'p.district_id')
            ->leftJoin('task_categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.id', $projectId)
            ->whereNull('p.deleted_at')
            ->first([
                'p.id', 'p.district_id', 'p.category_id', 'p.title', 'p.description',
                'p.planned_start', 'p.planned_end', 'p.actual_end', 'p.status',
                'p.progress_percent', 'p.created_at', 'p.updated_at',
                'd.name_cyr as district_name', 'c.name as category_name',
            ]);

        if ($p === null) {
            return null;
        }

        // Tuman boshqa tuman loyihasini ko'rmaydi (IDOR himoyasi).
        if ($scope->isTuman() && $p->district_id !== $scope->districtId) {
            return null;
        }

        $updates = DB::connection('advisor')->table('project_updates')
            ->where('project_id', $projectId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get(['id', 'user_id', 'body', 'progress_percent', 'occurred_at']);

        $names = $this->userNames($updates->pluck('user_id')->all());

        $files = DB::connection('advisor')->table('project_files')
            ->where('project_id', $projectId)
            ->orderBy('created_at')
            ->get(['id', 'original_name', 'mime']);

        return [
            'project' => [
                'id' => $p->id,
                'title' => $p->title,
                'description' => $p->description,
                'status' => $p->status,
                'progress_percent' => (int) $p->progress_percent,
                'planned_start' => $p->planned_start,
                'planned_end' => $p->planned_end,
                'actual_end' => $p->actual_end,
                'district' => ['id' => $p->district_id, 'name' => $p->district_name],
                'category' => $p->category_id === null ? null
                    : ['id' => $p->category_id, 'name' => $p->category_name],
                'created_at' => Carbon::parse($p->created_at)->toIso8601String(),
                'updated_at' => Carbon::parse($p->updated_at)->toIso8601String(),
            ],
            'updates' => $updates->map(fn ($u) => [
                'id' => $u->id,
                'body' => $u->body,
                'progress_percent' => $u->progress_percent === null ? null : (int) $u->progress_percent,
                'occurred_at' => $u->occurred_at === null ? null : Carbon::parse($u->occurred_at)->toIso8601String(),
                'user' => ['name' => $u->user_id === null ? null : ($names[$u->user_id] ?? null)],
            ])->all(),
            'files' => $files->map(fn ($f) => [
                'id' => $f->id,
                'original_name' => $f->original_name,
                'mime' => $f->mime,
            ])->all(),
        ];
    }

    // ---------------------------------------------------------------- tahrir

    /**
     * Loyihani tahrirlash (faqat berilgan maydonlar). status/progress/muddat.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProject(Project $project, array $data): void
    {
        $payload = [];
        foreach (['title', 'description', 'status', 'progress_percent', 'planned_end', 'actual_end'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if ($payload !== []) {
            $project->update($payload);
        }
    }

    // -------------------------------------------------------------- yangilanish

    /**
     * Progress yangilanishi qo'shish. progress_percent berilsa loyiha progressi
     * ham yangilanadi (spec §6).
     *
     * @return string yangilanish id
     */
    public function addUpdate(Project $project, string $userId, ?string $body, ?int $progress): string
    {
        return DB::connection('advisor')->transaction(function () use ($project, $userId, $body, $progress) {
            $update = ProjectUpdate::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'body' => $body,
                'progress_percent' => $progress,
                'occurred_at' => now(),
            ]);

            if ($progress !== null) {
                $project->update(['progress_percent' => $progress]);
            } else {
                $project->touch();
            }

            return $update->id;
        });
    }

    // ------------------------------------------------------------------- fayl

    /**
     * Loyihaga fayl(lar) yuklash (maxfiy disk).
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string> yuklangan fayl id'lari
     */
    public function uploadFiles(Project $project, string $userId, array $files): array
    {
        $ids = [];

        foreach ($files as $file) {
            $path = $file->store("advisor/projects/{$project->id}", $this->disk());
            $pf = ProjectFile::create([
                'project_id' => $project->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => (string) $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $userId,
            ]);
            $ids[] = $pf->id;
        }

        if ($ids !== []) {
            $project->touch();
        }

        return $ids;
    }

    // ----------------------------------------------------------------- o'chirish

    /** Loyihani o'chirish (soft delete) — faqat viloyat (kontroller tekshiradi). */
    public function deleteProject(Project $project): void
    {
        $project->delete();
    }

    // ----------------------------------------------------------------- yordamchi

    /**
     * user_id -> FIO (auth.users). Cross-schema: PHP'da yig'iladi.
     *
     * @param  array<int, ?string>  $userIds
     * @return array<string, ?string>
     */
    private function userNames(array $userIds): array
    {
        $userIds = array_values(array_filter(array_unique($userIds)));
        if ($userIds === []) {
            return [];
        }

        return DB::connection('auth')->table('users')
            ->whereIn('id', $userIds)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array{total: int, current_page: int, last_page: int, per_page: int}
     */
    private function meta(int $total, int $page, int $perPage): array
    {
        return [
            'total' => $total,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }
}
