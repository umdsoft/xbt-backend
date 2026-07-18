<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $uuid
 * @property string $last_name_cyr
 * @property string $first_name_cyr
 * @property string $middle_name_cyr
 * @property string|null $last_name_lat
 * @property string|null $first_name_lat
 * @property string|null $middle_name_lat
 * @property string $current_position
 * @property Carbon $position_start_date
 * @property string|null $photo_path
 * @property Carbon $birth_date
 * @property string $birth_place
 * @property int $birth_region_id
 * @property int $birth_district_id
 * @property string $nationality
 * @property string $party_affiliation
 * @property string $education_level
 * @property string $education_completion
 * @property string $specialty_by_education
 * @property string $academic_degree
 * @property string $academic_title
 * @property string $foreign_languages
 * @property string $state_awards
 * @property string $elected_body_member
 * @property string $jshshir
 * @property string|null $jshshir_hash
 * @property string $passport_series
 * @property string $passport_number
 * @property int $department_id
 * @property int $position_id
 * @property string $full_name
 * @property-read Collection<int, WorkHistory> $workHistory
 * @property-read Collection<int, Relative> $relatives
 */
class Employee extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        // 1-блок: Сарлавҳа
        'uuid',
        'last_name_cyr',
        'first_name_cyr',
        'middle_name_cyr',
        'last_name_lat',
        'first_name_lat',
        'middle_name_lat',
        'current_position',
        'position_start_date',
        'photo_path',
        // 2-блок: Шахсий маълумотлар
        'birth_date',
        'birth_place',
        'birth_region_id',
        'birth_district_id',
        'nationality',
        'party_affiliation',
        'education_level',
        'education_completion',
        'specialty_by_education',
        'academic_degree',
        'academic_title',
        'foreign_languages',
        'state_awards',
        'elected_body_member',
        // Махфий
        'jshshir',
        'passport_series',
        'passport_number',
        // Хизмат
        'department_id',
        'position_id',
        'hokimlik_id',
    ];

    /**
     * Махфий устунлар — сериализацияда (Inertia payload) ЯШИРИН.
     * `encrypted` cast очиқ матнни қайтаргани учун, $hidden бўлмаса рўйхат
     * саҳифасида ҳам ЖШШИР/паспорт браузерга кетарди. Битта ёзувни кўрсатиш
     * керак бўлса (edit форма) — контроллерда makeVisible() ишлатилади.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'jshshir',
        'passport_series',
        'passport_number',
        'jshshir_hash',
    ];

    /**
     * Ҳисобланадиган (accessor) майдонлар — сериализацияга қўшилади.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'photo_url',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'position_start_date' => 'date',
            'jshshir' => 'encrypted',
            'passport_series' => 'encrypted',
            'passport_number' => 'encrypted',
        ];
    }

    /** id va uuid — иккаласи ҳам автоматик UUID. */
    public function uniqueIds(): array
    {
        return ['id', 'uuid'];
    }

    protected static function booted(): void
    {
        // ЖШШИР шифрланган бўлгани учун (ноаниқ шифрматн) уникалликни DB unique index
        // таъминлай олмайди. Шунинг учун деттерминистик HMAC хешини сақлаймиз.
        static::saving(function (Employee $employee): void {
            $plain = $employee->jshshir; // encrypted cast — очиқ матнни қайтаради
            $employee->attributes['jshshir_hash'] = ($plain !== null && $plain !== '')
                ? self::hashJshshir((string) $plain)
                : null;
        });
    }

    /**
     * ЖШШИР учун деттерминистик HMAC-SHA256 хеши (уникаллик текшируви учун).
     */
    public static function hashJshshir(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    // ===== Муносабатлар =====

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function birthRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'birth_region_id');
    }

    public function birthDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'birth_district_id');
    }

    public function workHistory(): HasMany
    {
        return $this->hasMany(WorkHistory::class)->orderBy('sort_order');
    }

    public function relatives(): HasMany
    {
        return $this->hasMany(Relative::class);
    }

    // ===== Accessor лар =====

    /**
     * Тўлиқ Ф.И.Ш. (Кирилл).
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->last_name_cyr} {$this->first_name_cyr} {$this->middle_name_cyr}");
    }

    /**
     * Расм URL и — авторизацияланган маршрут орқали (public диск эмас).
     * Файлнинг ўзи фақат `employees.photo` маршрути орқали, view рухсати
     * ва tenant текшируви билан берилади.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return ($this->photo_path !== null && $this->photo_path !== '')
            ? route('api.hr.employees.photo', $this->id)
            : null;
    }

    // ===== Audit log =====

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'last_name_cyr', 'first_name_cyr', 'middle_name_cyr',
                'current_position', 'position_start_date',
                'department_id', 'position_id',
                'education_level', 'state_awards',
            ])
            ->logOnlyDirty();
    }
}
