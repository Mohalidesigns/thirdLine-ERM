<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\ControlTestEvidence;
use App\Models\IssueAttachment;
use App\Models\LossEventAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Unified read-only repository for every file uploaded anywhere in the
 * platform — loss-event attachments, control-test evidence, issue
 * attachments. Source tables remain authoritative; this controller only
 * queries and presents them together.
 */
class DocumentRepositoryController extends Controller
{
    /**
     * Build the source registry. Closures can't live in property defaults,
     * so the map is constructed on demand.
     */
    protected function sources(): array
    {
        return [
            'loss_event' => [
                'label' => 'Loss Event Attachment',
                'icon' => 'report_problem',
                'model' => LossEventAttachment::class,
                'parent_relation' => 'lossEvent',
                'parent_label' => fn ($row) => $row->lossEvent?->event_reference ?? ('#'.$row->loss_event_id),
                'parent_link' => fn ($row) => $row->lossEvent ? route('risk.loss-events.show', $row->lossEvent) : null,
                'download_link' => fn ($row) => $row->lossEvent
                    ? route('risk.loss-events.download-attachment', ['lossEvent' => $row->lossEvent, 'attachment' => $row->id])
                    : null,
                'has_document_type' => true,
                'has_regulatory' => true,
            ],
            'control_test' => [
                'label' => 'Control Test Evidence',
                'icon' => 'verified',
                'model' => ControlTestEvidence::class,
                'parent_relation' => 'controlTest',
                'parent_label' => fn ($row) => $row->controlTest?->test_code ?? ('#'.$row->control_test_id),
                'parent_link' => fn ($row) => $row->controlTest ? route('risk.control-tests.show', $row->controlTest) : null,
                'download_link' => fn ($row) => $row->controlTest
                    ? route('risk.control-tests.download-evidence', ['controlTest' => $row->controlTest, 'evidence' => $row->id])
                    : null,
                'has_document_type' => false,
                'has_regulatory' => false,
            ],
            'issue' => [
                'label' => 'Issue Attachment',
                'icon' => 'bug_report',
                'model' => IssueAttachment::class,
                'parent_relation' => 'issue',
                'parent_label' => fn ($row) => $row->issue?->issue_reference ?? ('#'.$row->issue_id),
                'parent_link' => fn ($row) => $row->issue ? route('risk.issues.show', $row->issue) : null,
                'download_link' => fn ($row) => $row->issue
                    ? route('risk.issues.download-attachment', ['issue' => $row->issue, 'attachment' => $row->id])
                    : null,
                'has_document_type' => true,
                'has_regulatory' => true,
            ],
        ];
    }

    public function index(Request $request)
    {
        $orgId = TenantContext::organizationId();
        $filters = [
            'source' => $request->string('source')->toString() ?: null,
            'document_type' => $request->string('document_type')->toString() ?: null,
            'regulatory' => $request->has('regulatory') ? (bool) $request->boolean('regulatory') : null,
            'uploader' => $request->integer('uploader') ?: null,
            'q' => $request->string('q')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        // Pull rows from each source, normalize to a single array shape.
        $rows = collect();
        foreach ($this->sources() as $key => $cfg) {
            if ($filters['source'] && $filters['source'] !== $key) {
                continue;
            }
            $rows = $rows->merge($this->loadSource($key, $cfg, $orgId, $filters));
        }

        $rows = $rows->sortByDesc('uploaded_at')->values();

        // Summary KPIs
        $summary = [
            'total' => $rows->count(),
            'by_source' => $rows->groupBy('source')->map->count(),
            'regulatory' => $rows->where('is_regulatory', true)->count(),
            'total_size' => $rows->sum('size_bytes'),
            'by_doc_type' => $rows->where('document_type', '!=', null)
                ->groupBy('document_type')->map->count()->sortDesc(),
        ];

        // Distinct values for filter selects
        $docTypes = $rows->pluck('document_type')->filter()->unique()->sort()->values();

        // Folder-grouped view: one folder per source, in the registry's declared order.
        $rowsBySource = $rows->groupBy('source');
        $folders = collect($this->sources())->map(function ($cfg, $key) use ($rowsBySource) {
            $files = $rowsBySource->get($key, collect());

            return (object) [
                'key' => $key,
                'label' => $cfg['label'],
                'icon' => $cfg['icon'],
                'files' => $files,
                'count' => $files->count(),
                'total_size' => $files->sum('size_bytes'),
            ];
        })->values();

        $sources = collect($this->sources())->map(fn ($cfg, $key) => [
            'key' => $key, 'label' => $cfg['label'], 'icon' => $cfg['icon'],
        ])->values();

        return Inertia::render('Documents/Index', [
            'folders' => $folders,
            'summary' => $summary,
            'docTypes' => $docTypes,
            'sources' => $sources,
            'filters' => $filters,
        ]);
    }

    /**
     * Load + normalize rows from one source table.
     */
    protected function loadSource(string $key, array $cfg, int $orgId, array $filters): \Illuminate\Support\Collection
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = $cfg['model'];

        $query = $modelClass::query()->with([$cfg['parent_relation'] => fn ($q) => $q->where('organization_id', $orgId)]);

        // Scope by parent's organization — not all attachment tables have the column.
        $query->whereHas($cfg['parent_relation'], fn ($q) => $q->where('organization_id', $orgId));

        if ($filters['uploader']) {
            $query->where('uploaded_by', $filters['uploader']);
        }
        if ($filters['q']) {
            $query->where('file_name', 'like', '%'.$filters['q'].'%');
        }
        if ($filters['from']) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if ($filters['to']) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }
        if ($filters['document_type'] && $cfg['has_document_type']) {
            $query->where('document_type', $filters['document_type']);
        } elseif ($filters['document_type'] && ! $cfg['has_document_type']) {
            // Source doesn't have document_type → exclude it entirely when the filter is active.
            return collect();
        }
        if ($filters['regulatory'] !== null && $cfg['has_regulatory']) {
            $query->where('is_regulatory', $filters['regulatory']);
        } elseif ($filters['regulatory'] === true && ! $cfg['has_regulatory']) {
            return collect();
        }

        // getAttribute() rather than ->property throughout, because $row is
        // genuinely heterogeneous: this method reads four different document
        // tables through one shape, and no single model declares these columns.
        // Saying "dynamic attribute" out loud is more honest than a property
        // access that only looks static.
        return $query->with('uploadedBy')->orderByDesc('created_at')->get()->map(function (Model $row) use ($key, $cfg) {
            return (object) [
                'source' => $key,
                'source_label' => $cfg['label'],
                'source_icon' => $cfg['icon'],
                'id' => $row->getAttribute('id'),
                'file_name' => $row->getAttribute('file_name'),
                'file_type' => $row->getAttribute('file_type'),
                'size_bytes' => (int) ($row->file_size_bytes ?? $row->file_size ?? 0),
                'document_type' => $cfg['has_document_type'] ? ($row->document_type ?? null) : null,
                'is_regulatory' => $cfg['has_regulatory'] ? (bool) ($row->is_regulatory ?? false) : false,
                'description' => $row->description ?? null,
                'uploaded_by' => $row->uploadedBy?->name ?? '—',
                'uploaded_by_id' => $row->getAttribute('uploaded_by'),
                'uploaded_at' => $row->getAttribute('created_at'),
                'parent_label' => ($cfg['parent_label'])($row),
                'parent_link' => ($cfg['parent_link'])($row),
                'download_link' => ($cfg['download_link'])($row),
            ];
        });
    }
}
