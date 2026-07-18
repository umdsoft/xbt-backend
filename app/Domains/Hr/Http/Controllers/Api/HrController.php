<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Support\HrAccess;
use App\Domains\Hr\Support\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * HR domeni API controllerlari uchun asosiy klass.
 *
 * Muhim: KBT authorization spatie rollari HR profilida (HrProfile) yashaydi,
 * markaziy auth foydalanuvchisida emas. Shu sabab `authorize()` HR aktyori
 * uchun bajariladi (Gate::forUser($actor)). Bu port qilingan controllerlar
 * `$this->authorize(...)` chaqiruvlarini o'zgarishsiz saqlashga imkon beradi.
 */
abstract class HrController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function hr(): HrAccess
    {
        return app(HrAccess::class);
    }

    /** Joriy HR aktyori (spatie rollari + tenant). */
    protected function actor(): HrProfile
    {
        return $this->hr()->actor();
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * KBT policy'lari HR aktyoriga nisbatan tekshiriladi.
     *
     * @param  iterable<mixed>|array<mixed>|mixed  $arguments
     */
    public function authorize($ability, $arguments = [])
    {
        return $this->authorizeForUser($this->actor(), $ability, $arguments);
    }
}
