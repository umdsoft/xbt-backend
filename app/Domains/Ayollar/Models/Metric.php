<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Metrikalar lug'ati qatori. MFY/tuman/viloyat shakllari SHUNDAN generatsiya
 * qilinadi — shakl hech qayerda hardcode qilinmaydi (promt §14).
 */
class Metric extends Model
{
    public const CATEGORY_GREEN = 'green';

    public const CATEGORY_YELLOW = 'yellow';

    public const CATEGORY_RED = 'red';

    public const CATEGORY_AGE = 'age';

    public const CATEGORY_META = 'meta';

    use HasUuids;

    protected $connection = 'ayollar';

    protected $table = 'metric_registry';

    protected $fillable = [
        'code', 'name_lat', 'name_cyr', 'category', 'sort_order',
        'in_mahalla_form', 'in_district_form', 'in_region_form', 'owner_org_code',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'in_mahalla_form' => 'boolean',
            'in_district_form' => 'boolean',
            'in_region_form' => 'boolean',
        ];
    }

    /**
     * Shakl qatorlari.
     *
     * @param  Builder<self>  $q
     * @param  string  $level  mahalla | district | region
     * @return Builder<self>
     */
    public function scopeForForm(Builder $q, string $level): Builder
    {
        return $q->where("in_{$level}_form", true)->orderBy('sort_order');
    }
}
