<?php

namespace ThirdLine\Platform\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Baseline security response headers on every web response: clickjacking,
 * MIME-sniffing, referrer and feature-policy controls, HSTS over TLS, and a
 * Content-Security-Policy.
 *
 * From ThirdLine (VAPT-022), with one difference: the CSP here is ENFORCED,
 * not report-only. ThirdLine could not enforce because its shell loads fonts
 * from bunny.net; this product serves every asset from its own origin
 * (AssetResidencyTest), so there is nothing a strict policy would break.
 *
 * SCRIPT-SRC IS 'self' ALONE, since migration Phase 6.8 — the Phase 0 TODO,
 * now closed. 'unsafe-inline' and 'unsafe-eval' were there only for the
 * Blade + Livewire screens: 47 Blade views carried inline <script> blocks, and
 * Alpine evaluated its x-data expressions with `new Function`. The Inertia
 * shell needs neither.
 *
 * It is UNCONDITIONAL rather than keyed on whether livewire/livewire happens to
 * be installed, which is what it was between Phase 0 and 6.8. A conditional was
 * right while the removal was pending — it let the CSP tighten itself the
 * moment the package left — but keeping it would mean anything that pulled
 * Livewire back in, a transitive dependency included, silently reopened
 * script-src on a live deployment. A control that switches itself off when the
 * conditions change is not a control.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only decorate full HTTP responses (skip streamed downloads, which
        // set their own headers and may be sensitive to added ones).
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()', false);
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none', false);

        // HSTS only over TLS — never send it on plain HTTP.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    public function policy(): string
    {
        $self = ["'self'"];
        $connect = ["'self'"];

        // The Vite dev server serves the bundles from its own origin during
        // `npm run dev` and keeps a websocket open for HMR. Allowing it is a
        // development-only concession; a production build has no hot file.
        if (($hot = $this->viteDevOrigin()) !== null) {
            $self[] = $hot;
            $connect[] = $hot;
            $connect[] = preg_replace('#^http#', 'ws', $hot);
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            'style-src '.implode(' ', array_merge($self, ["'unsafe-inline'"])),
            'script-src '.implode(' ', $self),
            'connect-src '.implode(' ', $connect),
            "form-action 'self'",
        ]);
    }

    private function viteDevOrigin(): ?string
    {
        if (app()->isProduction()) {
            return null;
        }

        try {
            $vite = app(Vite::class);

            if (! $vite->isRunningHot()) {
                return null;
            }

            $url = trim((string) file_get_contents($vite->hotFile()));

            return $url === '' ? null : rtrim($url, '/');
        } catch (\Throwable) {
            return null;
        }
    }
}
