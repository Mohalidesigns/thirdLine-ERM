<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Incident;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\PortalUser;
use App\Services\Tprm\Portal\PortalEvidenceService;
use App\Services\Tprm\Portal\PortalIncidentService;
use App\Services\Tprm\Portal\SubprocessorDeclarationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * The three things a vendor MAINTAINS rather than answers — FR-PRT-05, 06
 * and 07: its evidence, its sub-processors, and the incidents it must tell us
 * about.
 *
 * ONE CONTROLLER because they are one idea from the vendor's side: the vendor
 * keeps its own record current, and the bank reads it. Splitting them into
 * three would give three near-identical ownership checks to keep in step.
 */
class VendorRecordController extends Controller
{
    public function __construct(
        private readonly PortalEvidenceService $evidence,
        private readonly SubprocessorDeclarationService $subprocessors,
        private readonly PortalIncidentService $incidents,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Evidence — FR-PRT-05 */
    /* ------------------------------------------------------------------ */

    public function documents(Request $request)
    {
        $user = $this->user($request);

        return Inertia::render('TprmPortal/Documents', [
            'documents' => $this->evidence->documentsFor($user)
                ->map(fn (Document $document): array => [
                    'id' => $document->getKey(),
                    'title' => $document->title,
                    'type' => $document->documentType?->name,
                    'valid_to' => $document->valid_to?->toDateString(),
                    'expired' => $document->valid_to?->isPast() ?? false,
                    'expiring_soon' => $document->valid_to !== null
                        && ! $document->valid_to->isPast()
                        && $document->valid_to->diffInDays(now()) <= 90,
                    'uploaded_via' => $document->uploaded_via,
                    // Never a green tick for a scan we did not run.
                    'scan_status' => $document->virus_scan_status,
                ])->values(),
            'types' => $this->evidence->uploadableTypes((int) $user->organization_id)
                ->map(fn (DocumentType $type): array => [
                    'id' => $type->getKey(),
                    'name' => $type->name,
                    'has_expiry' => (bool) $type->has_expiry,
                ])->values(),
            'limits' => [
                'max_kilobytes' => 25600,
                'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'],
            ],
        ]);
    }

    public function uploadDocument(Request $request)
    {
        $user = $this->user($request);

        $validated = $request->validate([
            /*
             * The extension allowlist and size cap are ALSO enforced inside
             * FileUploadService against the DETECTED mime, which is what
             * actually protects the disk — a client controls both its filename
             * and its Content-Type header. This rule is here so the vendor
             * gets a sentence rather than an exception.
             */
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt'],
            'title' => ['required', 'string', 'max:255'],
            'document_type_id' => ['nullable', 'integer'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after:valid_from'],
        ]);

        $type = $validated['document_type_id'] === null
            ? null
            : $this->evidence->uploadableTypes((int) $user->organization_id)
                ->firstWhere('id', $validated['document_type_id']);

        try {
            $this->evidence->upload($user, $request->file('file'), $type, [
                'title' => $validated['title'],
                'valid_from' => $validated['valid_from'] ?? null,
                'valid_to' => $validated['valid_to'] ?? null,
            ]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['valid_to' => $exception->getMessage()]);
        }

        return back()->with('success', 'Uploaded. We will remind you before it expires.');
    }

    /* ------------------------------------------------------------------ */
    /*  Sub-processors — FR-PRT-06 */
    /* ------------------------------------------------------------------ */

    public function subprocessors(Request $request)
    {
        $user = $this->user($request);

        $gate = $this->subprocessors->consentGate($user->thirdParty);

        return Inertia::render('TprmPortal/Subprocessors', [
            'subprocessors' => $this->subprocessors->declared($user)
                ->map(fn (NthPartyEdge $edge): array => [
                    'id' => $edge->getKey(),
                    'name' => $edge->displayName(),
                    'service_description' => $edge->service_description,
                    'country' => $edge->country_of_processing,
                    'criticality' => $edge->criticality,
                    'status' => $edge->confirmation_status,
                    'declared_at' => $edge->disclosed_at?->toDateString(),
                ])->values(),
            'consent' => [
                'required' => $gate['required'],
                'basis' => $gate['basis'],
            ],
        ]);
    }

    public function declareSubprocessor(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'service_description' => ['nullable', 'string', 'max:500'],
            'country_of_processing' => ['nullable', 'string', 'max:2'],
            'criticality' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $result = $this->subprocessors->declare($this->user($request), $validated['name'], $validated);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        return back()->with('success', $result['message']);
    }

    /* ------------------------------------------------------------------ */
    /*  Incidents — FR-PRT-07 */
    /* ------------------------------------------------------------------ */

    public function incidents(Request $request)
    {
        return Inertia::render('TprmPortal/Incidents', [
            'incidents' => $this->incidents->reportedBy($this->user($request))
                ->map(fn (Incident $incident): array => [
                    'uuid' => $incident->uuid,
                    'reference' => $incident->reference,
                    'title' => $incident->title,
                    'detected_at' => $incident->detected_at?->toDayDateTimeString(),
                    'reported_to_us_at' => $incident->reported_to_us_at?->toDayDateTimeString(),
                    'receipt' => $incident->receipt(),
                    'personal_data_involved' => $incident->personal_data_involved,
                    'customer_impact' => $incident->customer_impact,
                ])->values(),
        ]);
    }

    public function reportIncident(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:5000'],
            'detected_at' => ['required', 'date', 'before_or_equal:now'],
            'severity' => ['nullable', 'string', 'max:20'],
            'customer_impact' => ['boolean'],
            'customers_affected' => ['nullable', 'integer', 'min:0'],
            'personal_data_involved' => ['boolean'],
            'data_subjects_affected' => ['nullable', 'integer', 'min:0'],
        ]);

        $incident = $this->incidents->report($this->user($request), $validated);

        return back()->with('success', $incident->receipt());
    }

    private function user(Request $request): PortalUser
    {
        /** @var PortalUser $user */
        $user = $request->user('tprm-portal');

        return $user;
    }
}
