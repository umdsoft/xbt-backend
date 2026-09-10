<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\ExecutiveCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Микролойиҳа — ҳоким ёрдамчиси юритадиган ободонлаштириш иши.
 *
 * Har loyiha bir mahallaga bog'lanadi va o'z jarayonini yuritadi: yaratiladi,
 * bosqichma-bosqich yangilanadi (jarayon yozuvi + foiz), fayl biriktiriladi.
 * Ixtiyoriy ravishda muayyan binoga (maktab ta'miri) yoki ko'chaga (yo'l)
 * bog'lanadi.
 *
 * Holatlar: planned (rejalashtirilgan), in_progress (jarayonda),
 * done (yakunlangan), cancelled (bekor qilingan).
 */
class MicroProjectService
{
    public const STATUSES = ['planned', 'in_progress', 'done', 'cancelled'];

    private function disk(): string
    {
        return (string) config('mahalla.contracts_disk', config('mahalla.photos_disk', 'local'));
    }

    /**
     * Mahalladagi loyihalar — sahifalab.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(
        string $mahallaId,
        ?string $status = null,
        ?string $categoryId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $base = fn () => DB::connection('mahalla')->table('micro_projects as p')
            ->where('p.mahalla_id', $mahallaId)
            ->whereNull('p.deleted_at')
            ->when($status !== null, fn ($q) => $q->where('p.status', $status))
            ->when($categoryId !== null, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn ($x) => $x->where('p.title', 'ilike', $like)
                    ->orWhere('p.description', 'ilike', $like));
            });

        $total = (int) $base()->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $rows = $base()
            ->leftJoin('project_categories as c', 'c.id', '=', 'p.category_id')
            ->orderByRaw("array_position(array['in_progress','planned','done','cancelled']::text[], p.status)")
            ->orderByDesc('p.updated_at')
            ->forPage($page, $perPage)
            ->get([
                'p.id', 'p.title', 'p.status', 'p.progress_percent',
                'p.planned_start', 'p.planned_end', 'p.actual_end', 'p.updated_at',
                'p.category_id', 'c.name_cyr as category_name',
            ]);

        return [
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'status' => $r->status,
                'progress_percent' => (int) $r->progress_percent,
                'planned_start' => $r->planned_start,
                'planned_end' => $r->planned_end,
                'actual_end' => $r->actual_end,
                'category' => $r->category_id === null ? null
                    : ['id' => $r->category_id, 'name' => $r->category_name],
            ])->all(),
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
            ],
        ];
    }

    /** Holat bo'yicha loyiha soni — dashboard kartochkalari uchun. */
    public function statusCounts(string $mahallaId): array
    {
        $rows = DB::connection('mahalla')->table('micro_projects')
            ->where('mahalla_id', $mahallaId)
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->selectRaw('status, count(*) as n')
            ->pluck('n', 'status');

        $out = ['total' => 0];
        foreach (self::STATUSES as $s) {
            $out[$s] = (int) ($rows[$s] ?? 0);
            $out['total'] += $out[$s];
        }

        return $out;
    }

    /**
     * Bitta loyiha — jarayon yozuvlari va fayllari bilan.
     *
     * @return array<string, mixed>|null
     */
    public function show(string $projectId, string $mahallaId): ?array
    {
        $p = DB::connection('mahalla')->table('micro_projects as p')
            ->leftJoin('project_categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.id', $projectId)
            ->where('p.mahalla_id', $mahallaId)
            ->whereNull('p.deleted_at')
            ->first([
                'p.id', 'p.title', 'p.description', 'p.status', 'p.progress_percent',
                'p.planned_start', 'p.planned_end', 'p.actual_end',
                'p.street_id', 'p.object_building_id',
                'p.category_id', 'c.name_cyr as category_name',
            ]);

        if ($p === null) {
            return null;
        }

        $updates = DB::connection('mahalla')->table('micro_project_updates')
            ->where('project_id', $projectId)
            ->orderByDesc('occurred_at')
            ->get(['id', 'body', 'progress_percent', 'occurred_at'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'body' => $u->body,
                'progress_percent' => $u->progress_percent === null ? null : (int) $u->progress_percent,
                'occurred_at' => Carbon::parse($u->occurred_at)->toIso8601String(),
            ]);

        $files = DB::connection('mahalla')->table('micro_project_files')
            ->where('project_id', $projectId)
            ->get(['id', 'original_name', 'size_bytes', 'mime'])
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->original_name, 'size' => (int) $f->size_bytes, 'mime' => $f->mime]);

        return [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'status' => $p->status,
            'progress_percent' => (int) $p->progress_percent,
            'planned_start' => $p->planned_start,
            'planned_end' => $p->planned_end,
            'actual_end' => $p->actual_end,
            'category' => $p->category_id === null ? null
                : ['id' => $p->category_id, 'name' => $p->category_name],
            'updates' => $updates->all(),
            'files' => $files->all(),
        ];
    }

    /** Yangi loyiha. */
    public function create(string $mahallaId, string $districtId, array $data, string $userId): string
    {
        $id = (string) Str::uuid();

        DB::connection('mahalla')->table('micro_projects')->insert([
            'id' => $id,
            'mahalla_id' => $mahallaId,
            'district_id' => $districtId,
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'planned_start' => $data['planned_start'] ?? null,
            'planned_end' => $data['planned_end'] ?? null,
            'status' => $data['status'] ?? 'planned',
            'progress_percent' => (int) ($data['progress_percent'] ?? 0),
            'street_id' => $data['street_id'] ?? null,
            'object_building_id' => $data['object_building_id'] ?? null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ExecutiveCache::flush();

        return $id;
    }

    /** Loyihani tahrirlaydi. Qamrov shu yerda tekshiriladi. */
    public function update(string $projectId, string $mahallaId, array $data): bool
    {
        $exists = DB::connection('mahalla')->table('micro_projects')
            ->where('id', $projectId)->where('mahalla_id', $mahallaId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            return false;
        }

        $fields = array_intersect_key($data, array_flip([
            'category_id', 'title', 'description', 'planned_start', 'planned_end',
            'actual_end', 'status', 'progress_percent', 'street_id', 'object_building_id',
        ]));
        $fields['updated_at'] = now();

        // Yakunlangan deb belgilanganda foiz 100 va yakun sanasi qo'yiladi —
        // "done, lekin 60%" degan ziddiyat bo'lmasligi uchun.
        if (($data['status'] ?? null) === 'done') {
            $fields['progress_percent'] = 100;
            $fields['actual_end'] ??= now()->toDateString();
        }

        DB::connection('mahalla')->table('micro_projects')->where('id', $projectId)->update($fields);
        ExecutiveCache::flush();

        return true;
    }

    /** Jarayon yozuvi qo'shadi (va foizni yangilaydi). */
    public function addUpdate(string $projectId, string $mahallaId, string $body, ?int $progress, string $userId): bool
    {
        $exists = DB::connection('mahalla')->table('micro_projects')
            ->where('id', $projectId)->where('mahalla_id', $mahallaId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            return false;
        }

        DB::connection('mahalla')->transaction(function () use ($projectId, $body, $progress, $userId) {
            DB::connection('mahalla')->table('micro_project_updates')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $projectId,
                'user_id' => $userId,
                'body' => $body,
                'progress_percent' => $progress,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($progress !== null) {
                DB::connection('mahalla')->table('micro_projects')
                    ->where('id', $projectId)
                    ->update(['progress_percent' => $progress, 'updated_at' => now()]);
            }
        });

        ExecutiveCache::flush();

        return true;
    }

    /** Fayl biriktiradi. */
    public function attachFile(string $projectId, string $mahallaId, UploadedFile $file, string $userId): bool
    {
        $exists = DB::connection('mahalla')->table('micro_projects')
            ->where('id', $projectId)->where('mahalla_id', $mahallaId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            return false;
        }

        $path = $file->store("mahalla/projects/{$mahallaId}/{$projectId}", $this->disk());

        DB::connection('mahalla')->table('micro_project_files')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /** Yuklab olish uchun fayl. */
    public function fileFor(string $fileId, string $mahallaId): ?array
    {
        $file = DB::connection('mahalla')->table('micro_project_files as f')
            ->join('micro_projects as p', 'p.id', '=', 'f.project_id')
            ->where('f.id', $fileId)
            ->where('p.mahalla_id', $mahallaId)
            ->whereNull('p.deleted_at')
            ->first(['f.path', 'f.original_name']);

        return $file === null ? null
            : ['path' => $file->path, 'name' => $file->original_name, 'disk' => $this->disk()];
    }

    public function delete(string $projectId, string $mahallaId): bool
    {
        $exists = DB::connection('mahalla')->table('micro_projects')
            ->where('id', $projectId)->where('mahalla_id', $mahallaId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            return false;
        }

        DB::connection('mahalla')->table('micro_projects')
            ->where('id', $projectId)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        ExecutiveCache::flush();

        return true;
    }
}
