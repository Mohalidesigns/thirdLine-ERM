<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The evidence library — FR-EVD-01, and the expiry heat map beside it.
 *
 * `valid_to` IS THE COLUMN THIS GRID EXISTS FOR. Everything else is
 * findability; the expiry column is the one that turns a pile of PDFs into a
 * working control, because a certificate that expired in March is a control
 * that is no longer evidenced and a score that has already moved.
 *
 * SUPERSEDED DOCUMENTS ARE LISTED, NOT HIDDEN, and carry a Version column
 * saying so. Last year's SOC 2 is still cited by last year's assessment, so it
 * has to stay retrievable — and this product's grid layer has no mechanism for
 * a default filter a user can then switch off, so hiding them in `query()`
 * would make them unreachable rather than out of the way. The filter narrows
 * to current, the sort puts live evidence first, and nothing disappears.
 */
class TprmDocumentsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'tprm_documents';
    }

    public function permission(): string
    {
        return 'tprm.evidence.view';
    }

    public function query(): Builder
    {
        return Document::query()
            ->with(['documentType:id,name,code,category', 'uploader:id,name'])
            ->where('tp_documents.organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('title', 'Document')
                ->sortable()->searchable()
                ->linkTo(fn (Document $d) => route('tprm.documents.show', $d)),

            Column::make('documentType.name', 'Type')
                ->searchable('tp_document_types.name')
                ->using(fn (Document $d) => $d->documentType->name ?? 'Uncategorised'),

            Column::make('owner_type', 'Attached to')
                ->sortable()
                ->using(fn (Document $d) => $d->ownerLabel()),

            Column::make('issuer', 'Issuer')
                ->searchable()
                ->using(fn (Document $d) => $d->issuer ?: '—'),

            // Two columns rather than one, because the RAG chip needs a fixed
            // vocabulary to colour and a reader needs the actual date. The
            // heat map is this column; the date beside it is what they act on.
            Column::make('expiry_state', 'Evidence state')
                ->using(fn (Document $d) => match (true) {
                    $d->is_superseded => 'Superseded',
                    // Not a gap in itself: a penetration test report has no
                    // expiry printed on it.
                    $d->valid_to === null => 'No expiry',
                    $d->isExpired() => 'Expired',
                    (int) $d->daysUntilExpiry() <= 30 => 'Expires within 30 days',
                    (int) $d->daysUntilExpiry() <= 90 => 'Expires within 90 days',
                    default => 'Current',
                })
                ->rag([
                    'Expired' => 'red',
                    'Expires within 30 days' => 'red',
                    'Expires within 90 days' => 'amber',
                    'Current' => 'green',
                    'No expiry' => 'neutral',
                    'Superseded' => 'neutral',
                ]),

            Column::make('valid_to', 'Expires')
                ->sortable()
                ->date()
                ->using(fn (Document $d) => $d->valid_to?->format('d M Y') ?? '—'),

            Column::make('extraction_status', 'Extraction')
                ->sortable()
                ->using(fn (Document $d) => match ($d->extraction_status) {
                    'extracted' => 'Read, awaiting confirmation',
                    'confirmed' => 'Confirmed',
                    'pending' => 'Queued',
                    'failed' => 'Could not be read',
                    // "Nothing will ever be extracted from this" and
                    // "extraction has not run yet" are different states and a
                    // user waiting for a panel needs to know which they have.
                    'unavailable' => 'Manual entry',
                    default => 'Not applicable',
                }),

            Column::make('version', 'Version')
                ->hiddenByDefault()
                ->using(fn (Document $d) => 'v'.$d->version.($d->is_superseded ? ' (superseded)' : '')),

            Column::make('uploader.name', 'Uploaded by')
                ->hiddenByDefault()
                ->using(fn (Document $d) => $d->uploader->name ?? 'System'),

            Column::make('created_at', 'Uploaded')
                ->sortable()->date()->hiddenByDefault(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('type', 'All types')
                ->column('tp_documents.document_type_id')
                ->options(fn () => DocumentType::query()
                    ->availableTo(TenantContext::organizationIdOrNull())
                    ->active()->orderBy('name')->pluck('name', 'id')->all()),

            Filter::make('expiry', 'Expiry')
                ->options([
                    'expired' => 'Expired',
                    'expiring_30' => 'Expiring within 30 days',
                    'expiring_90' => 'Expiring within 90 days',
                    'no_expiry' => 'No expiry recorded',
                ])
                ->apply(function (Builder $query, string $value): void {
                    /** @var Builder<Document> $query */
                    match ($value) {
                        'expired' => $query->expired(),
                        'expiring_30' => $query->expiringWithin(30),
                        'expiring_90' => $query->expiringWithin(90),
                        default => $query->whereNull('valid_to'),
                    };
                }),

            Filter::make('owner_type', 'All attachments')
                ->column('tp_documents.owner_type')
                ->options([
                    Document::OWNER_THIRD_PARTY => 'Third party',
                    Document::OWNER_ENGAGEMENT => 'Engagement',
                    Document::OWNER_ASSESSMENT => 'Assessment',
                ]),

            Filter::make('extraction', 'Extraction')
                ->column('tp_documents.extraction_status')
                ->options([
                    'extracted' => 'Awaiting confirmation',
                    'confirmed' => 'Confirmed',
                    'failed' => 'Could not be read',
                    'unavailable' => 'Manual entry',
                ]),

            Filter::make('superseded', 'All versions')
                ->options(['current' => 'Current only', 'superseded' => 'Superseded only'])
                ->apply(function (Builder $query, string $value): void {
                    $query->where('is_superseded', $value === 'superseded');
                }),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Open', 'visibility', fn (Document $d) => route('tprm.documents.show', $d)),
        ];
    }

    /**
     * Soonest expiry first, which puts the evidence about to lapse at the top
     * — the only ordering this list has a reason to open in.
     */
    public function defaultSort(): array
    {
        return ['valid_to', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No evidence yet. Upload a SOC 2, a certificate or a policy from an engagement or a third party.';
    }
}
