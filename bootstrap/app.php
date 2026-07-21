<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // nginx (reverse proxy) orqasida X-Forwarded-* header'lariga ishonish —
        // HTTPS to'g'ri aniqlanadi (absolut rasm URL'lari + secure cookie uchun).
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Sanctum SPA: frontend (Vue) origin'lari sessiya-asosli (stateful) auth ishlatadi
        $middleware->statefulApi();

        // Xavfsizlik header'lari — barcha web va api javoblariga.
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
        $middleware->api(append: [\App\Http\Middleware\SecurityHeaders::class]);

        $middleware->alias([
            'system.access' => \App\Http\Middleware\EnsureSystemAccess::class,
            'mahalla.admin' => \App\Domains\Mahalla\Http\Middleware\EnsureMahallaAdmin::class,
            'mahalla.viewer' => \App\Domains\Mahalla\Http\Middleware\EnsureMahallaViewer::class,
            'mahalla.rais' => \App\Domains\Mahalla\Http\Middleware\EnsureMahallaRais::class,
            'mahalla.hokim' => \App\Domains\Mahalla\Http\Middleware\EnsureMahallaHokim::class,
            'hr.context' => \App\Domains\Hr\Http\Middleware\EnsureHrContext::class,
            'hr.can' => \App\Domains\Hr\Http\Middleware\HrPermission::class,
        ]);

        // HR tenant konteksti route-model binding'dan OLDIN o'rnatilishi shart —
        // aks holda {employee}/{controlPlan}/... bind qilinayotganda tenant scope
        // qo'llanmay IDOR yuzaga kelardi (xbt HIGH-1 security fix).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: \App\Domains\Hr\Http\Middleware\EnsureHrContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Autentifikatsiyasiz API so'rovi — Accept header'dan qat'i nazar toza JSON 401.
        // (Aks holda default handler `login` nomli route'ga redirect qilib, u
        //  aniqlanmagani uchun 500 "Route [login] not defined" qaytaradi.)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();
