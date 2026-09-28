<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A decision maker at a company. At most 2 active contacts per company.
 *
 * Active = not do_not_contact, not unsubscribed and email not invalid.
 * email_status: verified | catch_all | invalid | unknown.
 */
class Contact extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    public const MAX_ACTIVE_PER_COMPANY = 2;

    protected $connection = 'advisor';

    protected $table = 'outreach_contacts';

    protected $fillable = [
        'company_id', 'full_name', 'title', 'role_type', 'email', 'email_status',
        'verified_at', 'linkedin_url', 'language', 'unsubscribed_at', 'do_not_contact', 'created_by',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'do_not_contact' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'contact_id');
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('do_not_contact', false)
            ->whereNull('unsubscribed_at')
            ->where('email_status', '!=', 'invalid');
    }

    public function isActive(): bool
    {
        return ! $this->do_not_contact
            && $this->unsubscribed_at === null
            && $this->email_status !== 'invalid';
    }
}
