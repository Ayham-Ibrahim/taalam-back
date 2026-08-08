<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * رؤوس أمان قياسية على كل استجابة. الـ API فقط (JSON) — لا CORP هنا عمداً
 * لأن الفرونت SPA منفصل على أصل (origin) مختلف ويحتاج يقرأ الاستجابات فعلياً؛
 * CORP=same-origin كان سيكسر ذلك على مستوى المتصفح.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // استجابات JSON بحتة — لا تحمّل أي مورد فرعي، فـ CSP صارمة بلا أثر جانبي
        if ($request->is('api/*')) {
            $response->headers->set("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'");
        }

        // HSTS فقط فوق HTTPS فعلياً — إضافتها على HTTP محلي بلا فائدة وقد تُربك التطوير
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
