<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Javob (response) uchun asosiy xavfsizlik header'lari.
 *
 * - X-Frame-Options: DENY            — clickjacking oldini olish (iframe taqiq).
 * - X-Content-Type-Options: nosniff  — MIME-sniffing oldini olish.
 * - Referrer-Policy: same-origin     — referer'ni faqat o'z domenida yuborish.
 * - Strict-Transport-Security        — FAQAT HTTPS (secure) so'rovda (TrustProxies bilan).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        // HSTS — faqat HTTPS orqali kelgan so'rovda (nginx X-Forwarded-Proto orqali aniqlanadi).
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
