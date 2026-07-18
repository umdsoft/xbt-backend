<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * YAGONA IDENTIFIKATSIYA — markaziy `auth.users`.
 * Platform butun ekotizim uchun auth avtoriteti. Tizimga xos ma'lumot (HR, geo)
 * tegishli domen jadvallarida user_id (auth.users.id) bilan bog'lanadi.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $connection = 'auth';

    protected $table = 'users';

    protected $fillable = ['login', 'name', 'password', 'phone', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Foydalanuvchi kira oladigan tizimlar (+ o'sha tizimdagi roli).
     *
     * @return array<int, array{code: string, name: string, url: string|null, role: string|null}>
     */
    public function accessibleSystems(): array
    {
        return DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('usa.user_id', $this->id)
            ->where('usa.is_active', true)
            ->where('s.is_active', true)
            ->orderBy('s.sort_order')
            ->get(['s.code', 's.name', 's.url', 'usa.role'])
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'url' => $r->url,
                'role' => $r->role,
            ])->all();
    }

    /**
     * Shu tizimga (code) ruxsati bormi?
     */
    public function canAccessSystem(string $code): bool
    {
        return DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('usa.user_id', $this->id)
            ->where('usa.is_active', true)
            ->where('s.code', $code)
            ->where('s.is_active', true)
            ->exists();
    }
}
