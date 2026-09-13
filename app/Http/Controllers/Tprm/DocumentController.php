<?php

namespace App\Http\Controllers\Tprm;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\RunTprmDocumentExtraction;
use App\Models\JobRun;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentExtraction;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Presenters\GridPresenter;
use App\Services\FileUploadService;
use App\Services\Tprm\Evidence\EvidenceService;
use App\Services\Tprm\Evidence\ExpiryMonitor;
use App\Services\Tprm\Evidence\ScopeMatcher;
use App\Services\Tprm\Evidence\Soc2Cascade;
use App\Services\Tprm\Evidence\Soc2CascadeApplier;
use App\Services\Tprm\Extraction\DocumentTextExtractor;
use App\Services\Tprm\Extraction\ExtractionConfirmer;
use App\Services\Tprm\Extraction\LlmClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * The evidence library and the document workspace (TRD §11).
 *
 * The Show screen is the document viewer beside the extraction panel, and the
 * panel's job is to make the confirmation a real decision rather than a
 * formality: every field is shown with the quote it came from, the citation
 * check's verdict is on the page, and any instruction-like content found in
 * the upload is displayed prominently rather than logged quietly.
 *
 * WITH AI OFF, THIS SCREEN STILL WORKS. The panel becomes a form. That is
 * AC-16, and it is why the manual path goes through the same confirmer as the
 * machine one rather than a second implementation nobody exercises.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly EvidenceService $evidence,
        private readonly ExtractionConfirmer $confirmer,
    ) {}

    public function index(Request $request, GridPresenter $presenter, ExpiryMonitor $monitor)
    {
        return Inertia::render('Tprm/Documents/Index', [
            'summary' => fn () => $monitor->summary($request->user()->organization_id),
            'grid' => fn () => $presenter->present(
                GridRegistry::resolve('tprm_documents'),
                $request,
                $request->user()
            ),
            'documentTypes' => fn () => $this->documentTypes(),
            'capabilities' => fn () => $this->capabilities(),
            'can' => [
                'upload' => $request->user()->can('tprm.evidence.upload'),
                'confirm' => $request->user()->can('tprm.evidence.confirm'),
            ],
        ]);
    }

    public function show(Request $request, Document $document)
    {
        $document->load(['documentType', 'uploader:id,name', 'extractions.confirmer:id,name', 'supersededBy:id,uuid,title']);

        $engagement = $document->owner_type === Document::OWNER_ENGAGEMENT
            ? Engagement::query()->with('serviceType:id,name')->find($document->owner_id)
            : null;

        $soc2 = $document->soc2()->with(['exceptions', 'cuecs.owner:id,name', 'subserviceOrgs'])->first();

        return Inertia::render('Tprm/Documents/Show', [
            'document' => $this->payload($document),
            // The most recent extraction job for this document that has not
            // finished, so a screen re-visited mid-extraction (a refresh, a
            // second tab) still has something to poll with useJobProgress
            // instead of only the response that triggered the original
            // dispatch.
            'extractionJobRunId' => $this->outstandingExtractionJobRunId($document),
            'extractions' => $document->extractions
                ->sortByDesc('id')
                ->map(fn (DocumentExtraction $extraction) => [
                    'id' => $extraction->getKey(),
                    'extractor' => $extraction->extractor?->value,
                    'extractor_label' => $extraction->extractor?->label(),
                    // Null model and prompt version are what say a person
                    // typed this rather than a machine reading it.
                    'model' => $extraction->model,
                    'prompt_version' => $extraction->prompt_version,
                    'entered_manually' => $extraction->model === null,
                    'confidence' => $extraction->confidence === null ? null : (float) $extraction->confidence,
                    'status' => $extraction->status,
                    'confirmed_by' => $extraction->confirmer?->name,
                    'confirmed_at' => $extraction->confirmed_at?->toDayDateTimeString(),
                    'extracted' => $extraction->extracted,
                    'citations' => $extraction->citations,
                    'corrections' => $extraction->corrections,
                ])->values(),
            'soc2' => $soc2 === null ? null : [
                'id' => $soc2->getKey(),
                'report_type' => $soc2->report_type,
                'period_start' => $soc2->period_start?->toDateString(),
                'period_end' => $soc2->period_end?->toDateString(),
                'gap_days' => $soc2->gapDays(),
                'service_auditor' => $soc2->service_auditor,
                'tsc_categories' => $soc2->tsc_categories,
                'covered_criteria' => $soc2->coveredCriteria(),
                'opinion_type' => $soc2->opinion_type,
                'clean_opinion' => $soc2->hasCleanOpinion(),
                'exceptions' => $soc2->exceptions,
                'cuecs' => $soc2->cuecs->map(fn ($cuec) => [
                    'id' => $cuec->getKey(),
                    'reference' => $cuec->cuec_reference,
                    'description' => $cuec->description,
                    'owner' => $cuec->owner?->name,
                    'status' => $cuec->attestation_status,
                    'next_due_at' => $cuec->next_due_at?->toDateString(),
                ])->values(),
                'subservice_orgs' => $soc2->subserviceOrgs,
            ],
            // Generated fresh rather than stored: proposals are a view of the
            // current record, and one cached from before a correction would
            // propose against a report nobody holds any more.
            'proposals' => $soc2 === null ? null : app(Soc2Cascade::class)->propose($soc2)->toArray(),
            'scopeCheck' => $engagement === null ? null : app(ScopeMatcher::class)->check($document, $engagement),
            'capabilities' => $this->capabilities(),
            'can' => [
                'upload' => $request->user()->can('tprm.evidence.upload'),
                'confirm' => $request->user()->can('tprm.evidence.confirm'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $uploads = app(FileUploadService::class);

        $validated = $request->validate([
            'file' => $uploads->rules(FileUploadService::PROFILE_TPRM_EVIDENCE),
            'owner_type' => 'required|in:'.implode(',', array_keys(Document::ownerModels())),
            'owner_id' => 'required|integer',
            'document_type_id' => 'nullable|integer',
            'title' => 'nullable|string|max:255',
            'issuer' => 'nullable|string|max:200',
            'scope_text' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date',
            'confidentiality' => 'nullable|string|max:30',
        ]);

        $type = $validated['document_type_id'] === null
            ? null
            : DocumentType::query()->availableTo()->find($validated['document_type_id']);

        $document = $this->evidence->store(
            $request->file('file'),
            $validated['owner_type'],
            (int) $validated['owner_id'],
            $type,
            $validated,
            $request->user()->id,
        );

        return redirect()
            ->route('tprm.documents.show', $document)
            ->with('success', 'The document was stored. Nothing has been applied to the register yet.');
    }

    /**
     * A five-minute signed download, logged.
     *
     * `signed` middleware on the route is what enforces the expiry; this
     * method's job is the access log, which is written BEFORE the file is
     * streamed so a download that fails halfway still records that somebody
     * asked for it.
     */
    public function download(Request $request, Document $document)
    {
        abort_unless(Storage::disk(FileUploadService::DISK)->exists($document->file_path), 404);

        $this->evidence->logAccess($document, $request->user()?->id);

        return Storage::disk(FileUploadService::DISK)->download(
            $document->file_path,
            $document->title.'.'.pathinfo($document->file_path, PATHINFO_EXTENSION),
        );
    }

    /**
     * Queue extraction. Writes a pending row; applies nothing.
     *
     * ADR 0015 §6a: this used to call `ExtractionDispatcher::dispatch()`
     * inline and hold the request open for the model's own 26-69 second
     * response time. It now only dispatches `RunTprmDocumentExtraction` and
     * returns immediately; the upload screen polls the job's progress with
     * `hooks/useJobProgress` against the job run this creates.
     *
     * ALREADY-QUEUED GUARD (Gate 2 advisory). A double-click — or a second
     * tab, or a slow connection retried — used to queue a second model call
     * minutes apart from the first, on the same document, with nobody
     * having asked for two. `show()` already computes the same outstanding
     * run for the poller; this reuses it rather than checking twice.
     */
    public function extract(Request $request, Document $document)
    {
        $outstanding = $this->outstandingExtractionJobRunId($document);

        if ($outstanding !== null) {
            return back()->with([
                'info' => 'Already reading this document — nothing new was queued.',
                'job_run_id' => $outstanding,
            ]);
        }

        $jobRun = RunTprmDocumentExtraction::track(
            label: 'Reading '.$document->title,
            subject: $document,
            organizationId: $document->organization_id,
            creator: $request->user(),
        );

        RunTprmDocumentExtraction::dispatch($document->getKey(), $jobRun->id);

        return back()->with([
            'info' => 'Reading the document now. This can take a minute or two on a large report.',
            'job_run_id' => $jobRun->id,
        ]);
    }

    /**
     * The most recent extraction job for this document that has not
     * finished, or null.
     *
     * NO withoutGlobalScopes() (Gate 2, blocking defect 2). `JobRun` uses
     * `BelongsToOrganization`; the row is created with
     * `organizationId: $document->organization_id`, so the ordinary
     * tenant-scoped query matches it anyway. Stripping the scope and relying
     * on `$document` arriving tenant-scoped through route-model binding is
     * the same "safe only because of a second, independent constraint"
     * argument Gate 1 refused for `WidgetQueryEngine::engagementsUnderNodes()`
     * — see that class's own docblock, rewritten in this phase for exactly
     * this reason.
     */
    private function outstandingExtractionJobRunId(Document $document): ?int
    {
        return JobRun::query()
            ->where('job_class', RunTprmDocumentExtraction::class)
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->whereIn('status', [JobRun::STATUS_QUEUED, JobRun::STATUS_RUNNING])
            ->latest('id')
            ->value('id');
    }

    /**
     * The FIRST confirmation — the extractor read the document correctly.
     */
    public function confirm(Request $request, Document $document, DocumentExtraction $extraction)
    {
        abort_unless($extraction->document_id === $document->getKey(), 404);

        $validated = $request->validate(['corrections' => 'array']);

        $this->confirmer->confirm(
            $extraction,
            $request->user()->id,
            $validated['corrections'] ?? [],
        );

        return back()->with('success', 'The extraction was confirmed. Its proposals still need a second confirmation before anything reaches the register.');
    }

    public function reject(Request $request, Document $document, DocumentExtraction $extraction)
    {
        abort_unless($extraction->document_id === $document->getKey(), 404);

        $this->confirmer->reject($extraction, $request->user()->id);

        return back()->with('success', 'The extraction was rejected and nothing was applied.');
    }

    /**
     * Record the fields by hand — the AC-16 path, and the same code the
     * machine path ends in.
     */
    public function enterManually(Request $request, Document $document)
    {
        $validated = $request->validate([
            'extractor' => 'required|string|max:30',
            'fields' => 'required|array',
        ]);

        $this->confirmer->manual(
            $document,
            $validated['extractor'],
            $validated['fields'],
            $request->user()->id,
        );

        return back()->with('success', 'The details were recorded.');
    }

    /**
     * The SECOND confirmation — apply the selected proposals to the register.
     */
    public function applyCascade(Request $request, Document $document)
    {
        $soc2 = $document->soc2()->firstOrFail();

        $validated = $request->validate([
            'answers' => 'array',
            'answers.*' => 'integer',
            'cuecs' => 'array',
            'findings' => 'boolean',
        ]);

        $applied = app(Soc2CascadeApplier::class)->apply(
            $soc2,
            [
                'answers' => $validated['answers'] ?? [],
                'cuecs' => $validated['cuecs'] ?? [],
                'findings' => (bool) ($validated['findings'] ?? false),
            ],
            $request->user()->id,
        );

        return back()->with('success', sprintf(
            '%d answer(s) pre-answered, %d complementary control(s) assigned, %d obligation(s) added as duties '
            .'owed by us and %d finding(s) raised. %d sub-processor edge(s) remain proposals until the '
            .'nth-party register arrives.',
            $applied['answers_applied'],
            $applied['cuecs_assigned'],
            $applied['obligations_created'],
            $applied['findings_raised'],
            $applied['edges_pending'],
        ));
    }

    /** Upload a newer version, superseding this one. */
    public function replace(Request $request, Document $document)
    {
        $uploads = app(FileUploadService::class);

        $validated = $request->validate([
            'file' => $uploads->rules(FileUploadService::PROFILE_TPRM_EVIDENCE),
            'title' => 'nullable|string|max:255',
            'issue_date' => 'nullable|date',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date',
        ]);

        $replacement = $this->evidence->replace(
            $document,
            $request->file('file'),
            $validated,
            $request->user()->id,
        );

        return redirect()
            ->route('tprm.documents.show', $replacement)
            ->with('success', 'The new version was stored. The previous one is retained and marked superseded, because assessments still cite it.');
    }

    /** @return array<string, mixed> */
    private function payload(Document $document): array
    {
        return [
            'id' => $document->getKey(),
            'uuid' => $document->uuid,
            'title' => $document->title,
            'type' => $document->documentType?->name,
            'type_code' => $document->documentType?->code,
            'extractor' => $document->documentType?->extractor?->value,
            'owner_type' => $document->owner_type,
            'owner_label' => $document->ownerLabel(),
            'owner_id' => $document->owner_id,
            'issuer' => $document->issuer,
            'scope_text' => $document->scope_text,
            'issue_date' => $document->issue_date?->toDateString(),
            'valid_from' => $document->valid_from?->toDateString(),
            'valid_to' => $document->valid_to?->toDateString(),
            'days_until_expiry' => $document->daysUntilExpiry(),
            'is_expired' => $document->isExpired(),
            'is_current' => $document->isCurrent(),
            'is_superseded' => $document->is_superseded,
            'superseded_by' => $document->supersededBy === null ? null : [
                'uuid' => $document->supersededBy->uuid,
                'title' => $document->supersededBy->title,
            ],
            'version' => $document->version,
            'size' => $document->size,
            'mime' => $document->mime,
            'hash' => $document->hash,
            'virus_scan_status' => $document->virus_scan_status,
            'extraction_status' => $document->extraction_status,
            'uploaded_by' => $document->uploader?->name,
            'uploaded_at' => $document->created_at?->toDayDateTimeString(),
            'download_url' => $this->evidence->downloadUrl($document),
        ];
    }

    /**
     * What this installation can actually do, so the screen states it rather
     * than leaving a user waiting for a panel that will never populate.
     *
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        $llm = app(LlmClient::class);

        return [
            'ai_enabled' => $llm->enabled(),
            'pdf_readable' => app(DocumentTextExtractor::class)->canReadPdf(),
            'manual_entry_always_available' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function documentTypes(): array
    {
        return DocumentType::query()
            ->availableTo()
            ->active()
            ->orderBy('category')->orderBy('name')
            ->get()
            ->map(fn (DocumentType $type) => [
                'id' => $type->getKey(),
                'code' => $type->code,
                'name' => $type->name,
                'category' => $type->category,
                'has_expiry' => $type->has_expiry,
                'default_validity_months' => $type->default_validity_months,
                'extractor' => $type->extractor?->value,
                'assurance_ceiling' => $type->assuranceCeiling()?->value,
                'assurance_ceiling_label' => $type->assuranceCeiling()?->label(),
            ])->values()->all();
    }
}
