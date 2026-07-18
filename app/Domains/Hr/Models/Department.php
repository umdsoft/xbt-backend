<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'parent_id',
        'name_cyr',
        'name_lat',
        'code',
        'type',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id')->orderBy('sort_order');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class)->orderBy('sort_order');
    }

    /** Ushbu kompleksga tegishli tashkilotlar. */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'kompleks_id');
    }

    /**
     * Department uchun tenant — ildиз (top-level) hokimlik (parent_id IS NULL).
     * 3 darajali daraxt (hokimlik → kompleks → boshqarma) uchun ildizgacha ko'tariladi.
     */
    public function tenant(): self
    {
        $node = $this;
        $guard = 0;
        while ($node->parent_id !== null && $node->parent && $guard < 10) {
            $node = $node->parent;
            $guard++;
        }

        return $node;
    }

    /** Tenant (ildiz hokimlik) ID si (UUID). */
    public function rootId(): string
    {
        return $this->tenant()->id;
    }

    /**
     * Ushbu bo'lim va uning barcha avlodlari (subtree) ID lari.
     * Tenant bo'yicha foydalanuvchilarni filtrlashda ishlatiladi
     * (hokimlik → kompleks → boshqarma).
     *
     * @return array<int, string>
     */
    public function descendantAndSelfIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        $guard = 0;

        while ($frontier !== [] && $guard < 10) {
            $children = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
            $guard++;
        }

        return $ids;
    }

    public function getIsTenantAttribute(): bool
    {
        return $this->parent_id === null;
    }

    /** Faqat tenant darajadagi (top-level) hokimliklar uchun query scope. */
    public function scopeTenants($query)
    {
        return $query->whereNull('parent_id');
    }

    /** Faqat komplekslar (type='kompleks'). */
    public function scopeKomplekslar($query)
    {
        return $query->where('type', 'kompleks');
    }
}
