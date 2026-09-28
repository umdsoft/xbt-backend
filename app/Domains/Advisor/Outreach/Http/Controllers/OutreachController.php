<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Base for outreach REST controllers: every request acts as a UI actor.
 * Business rules live in the services; controllers validate shape, call one
 * service method and present the result.
 */
abstract class OutreachController extends Controller
{
    public function __construct(
        protected readonly OutreachGate $gate,
        protected readonly OutreachPresenter $present,
    ) {}

    protected function actor(Request $request): Actor
    {
        return Actor::ui($request->user(), $request->ip());
    }

    protected function authorizeTo(Request $request, string $permission): Actor
    {
        $actor = $this->actor($request);
        $this->gate->require($actor, $permission);

        return $actor;
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 25)));
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array<string, int>
     */
    protected function meta($page): array
    {
        return [
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
        ];
    }
}
