<?php

namespace App\Services\Reporting;

use App\Models\Organization;
use ThirdLine\Reporting\Contracts\ResolvesDocumentBranding;

/**
 * How THIS product names the owner of a document.
 *
 * Was `DocumentRenderer::branding()` until migration Phase 7.1c, and the
 * reason it did not move into thirdline/reporting with the rest of that class:
 * it reads five columns off `App\Models\Organization`, one of which is
 * `cbn_institution_code` — a Central Bank of Nigeria registration number. A
 * shared PDF renderer must not carry a Nigerian banking concept, and an audit
 * product in another jurisdiction must not acquire one by depending on it.
 *
 * Reads organizations.settings->org_profile, written by the admin settings
 * screen. Falls back to the organisation's own name — never to a placeholder
 * logo or an invented institution code. A board pack carrying the wrong
 * institution's mark is worse than one carrying none.
 */
class OrganizationBranding implements ResolvesDocumentBranding
{
    /**
     * @return array<string, mixed>
     */
    public function for(mixed $subject): array
    {
        if (! $subject instanceof Organization) {
            return [];
        }

        $profile = (array) ($subject->settings['org_profile'] ?? []);

        return [
            'organization_name' => $subject->name ?? config('app.name'),
            'short_name' => $subject->short_name,
            'institution_type' => $subject->institution_type,
            'cbn_institution_code' => $subject->cbn_institution_code,
            'rc_number' => $subject->rc_number,
            'address' => $profile['address'] ?? null,
            'logo' => $this->inlineLogo($profile['logo_path'] ?? null),
            // Brand navy and gold, overridable per tenant.
            'primary_colour' => $profile['primary_colour'] ?? '#1A365D',
            'accent_colour' => $profile['accent_colour'] ?? '#D4AF37',
        ];
    }

    /**
     * Inline the logo as a data URI.
     *
     * dompdf runs with isRemoteEnabled off and a chroot, so a stored path is
     * the only thing that reliably resolves — and inlining means a moved file
     * cannot break an already generated document.
     */
    private function inlineLogo(?string $logoPath): ?string
    {
        if (! $logoPath) {
            return null;
        }

        $absolute = storage_path('app/public/'.ltrim($logoPath, '/'));

        if (! is_readable($absolute) || filesize($absolute) >= 2_000_000) {
            return null;
        }

        $mime = mime_content_type($absolute) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($absolute));
    }
}
