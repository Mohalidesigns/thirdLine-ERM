<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\Document;
use App\Models\Tprm\Finding;
use App\Models\Tprm\PortalUser;
use App\Services\Tprm\Portal\PortalAssessmentService;
use App\Services\Tprm\Portal\PortalMessageService;
use App\Services\Tprm\Portal\TrustProfileService;
use App\Services\Tprm\Portal\VendorIdentityService;
use App\Services\Tprm\Portal\VendorTrustScore;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The vendor's landing page — FR-PRT-02.
 *
 * IT OPENS WITH WHAT IS OWED, not with a welcome. A vendor signs in because
 * somebody asked them for something; the assessments due, the documents about
 * to expire and the findings still open are the reason they are here, and
 * anything above them on the page is in the way.
 *
 * The trust score and the profile prompt sit BELOW that, because they are
 * what we want the vendor to do rather than what the vendor came to do. Put
 * them first and the page becomes an advert.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly PortalAssessmentService $assessments,
        private readonly PortalMessageService $messages,
        private readonly TrustProfileService $profiles,
        private readonly VendorIdentityService $identities,
        private readonly VendorTrustScore $trustScore,
    ) {}

    public function index(Request $request)
    {
        $user = $this->user($request);
        $profile = $this->identities->profileFor($user);

        return Inertia::render('TprmPortal/Dashboard', [
            'vendor' => $user->thirdParty?->legal_name,

            'openRequests' => $this->assessments->visibleTo($user)
                ->with('template:id,name')
                ->get()
                ->filter(fn (Assessment $a): bool => $this->assessments->isOpenForVendor($a))
                ->map(fn (Assessment $a): array => [
                    'uuid' => $a->uuid,
                    'name' => $a->template?->name,
                    'due_at' => $a->due_at?->toDateString(),
                    'overdue' => $a->due_at?->isPast() ?? false,
                    'progress' => $this->assessments->progress($a),
                ])->values(),

            'expiringDocuments' => Document::query()
                ->where('owner_type', Document::OWNER_THIRD_PARTY)
                ->where('owner_id', $user->third_party_id)
                ->whereNotNull('valid_to')
                ->whereDate('valid_to', '<=', now()->addDays(90)->toDateString())
                ->orderBy('valid_to')
                ->get()
                ->map(fn (Document $d): array => [
                    'title' => $d->title,
                    'valid_to' => $d->valid_to?->toDateString(),
                    'expired' => $d->valid_to?->isPast() ?? false,
                ])->values(),

            'openFindings' => Finding::query()
                ->where('third_party_id', $user->third_party_id)
                ->whereIn('status', ['open', 'assigned', 'in_remediation', 'evidence_submitted', 'under_verification'])
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                ->limit(5)
                ->get()
                ->map(fn (Finding $f): array => [
                    'uuid' => $f->uuid,
                    'reference' => $f->reference,
                    'title' => $f->title,
                    'severity' => $f->severity->value,
                    'severity_label' => $f->severity->label(),
                    'target_date' => $f->target_date?->toDateString(),
                ])->values(),

            'unreadMessages' => $this->messages->unreadForVendor($user),

            'trustProfile' => [
                'completeness' => $profile->completeness()['pct'],
                'published_version' => $profile->published_version,
                'next_best' => $this->profiles->nextBestActions($profile, 2),
                'tenant_count' => $profile->vendorIdentity?->tenantCount() ?? 1,
            ],

            'trustScore' => $this->trustScore->for($user),
        ]);
    }

    private function user(Request $request): PortalUser
    {
        /** @var PortalUser $user */
        $user = $request->user('tprm-portal');

        return $user;
    }
}
