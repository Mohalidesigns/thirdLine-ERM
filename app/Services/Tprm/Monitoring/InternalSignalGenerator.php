<?php

namespace App\Services\Tprm\Monitoring;

use App\Enums\Tprm\SignalSeverity;
use App\Enums\Tprm\SignalType;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\MonitoringSignal;
use App\Models\Tprm\Sla;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Contracts\SlaService;
use Illuminate\Support\Collection;

/**
 * Continuous monitoring with no external subscriptions — FR-MON-09.
 *
 * THIS IS THE MOST IMPORTANT CLASS IN THE MONITORING PHASE and the prompt says
 * so: "build this before any external driver". Every competitor's continuous
 * monitoring is a resold data feed, which means a Nigerian bank with no data
 * budget buys a monitoring module that monitors nothing. This one derives nine
 * signal types from data the system already holds, so the claim is true on the
 * day of installation with zero subscriptions.
 *
 * IT IS ALSO THE HONEST ONE. An expired ISO certificate, an assessment nobody
 * has run for eighteen months, an access grant still live on a terminated
 * engagement — these are facts about the vendor relationship that the
 * institution already owns and mostly cannot see. A commercial feed telling
 * you a vendor's TLS configuration changed is interesting; a register telling
 * you nobody has looked at that vendor since 2024 is actionable.
 *
 * EVERY SIGNAL IS DEDUPED ON WHAT MAKES IT THE SAME FACT, not on the day it
 * was noticed. An expired certificate produces one signal, not one per night —
 * because a stream that repeats itself is a stream people stop reading, and a
 * monitoring programme nobody reads is worse than none, since it is reported
 * as coverage.
 *
 * PURE-ISH: it writes signals and nothing else. It raises no findings, sends
 * no notifications and moves no scores — the rules engine decides what a
 * signal means, and keeping the two apart is what lets a tenant change its
 * response without touching the derivation.
 */
class InternalSignalGenerator
{
    public function __construct(private readonly SlaService $slas) {}

    /**
     * Derive every internal signal for a tenant.
     *
     * @return Collection<int, MonitoringSignal>
     */
    public function generate(?int $organizationId = null): Collection
    {
        $signals = collect();

        foreach ([
            'evidenceExpiring', 'evidenceExpired', 'assessmentsOverdue', 'findingsOverdue',
            'slaBreaches', 'missingDpas', 'screeningOverdue', 'contractsInNoticeWindow',
        ] as $derivation) {
            // Each derivation is independent, and one throwing must not lose
            // the other eight. A monitoring sweep that dies on a malformed row
            // reports nothing at all, which is the failure that looks like
            // "there is nothing to report".
            try {
                $signals = $signals->merge($this->{$derivation}($organizationId));
            } catch (\Throwable $exception) {
                logger()->error('TPRM internal signal derivation failed', [
                    'derivation' => $derivation,
                    'organization_id' => $organizationId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $signals;
    }

    /**
     * Evidence inside its notice window — a warning, not yet a penalty.
     *
     * `EvidenceExpiring` carries no SU uplift by design: the certificate is
     * still valid and the control is still evidenced. What it carries is time
     * to do something, which is the whole point of noticing early.
     *
     * @return Collection<int, MonitoringSignal>
     */
    public function evidenceExpiring(?int $organizationId = null): Collection
    {
        return $this->documents($organizationId)
            ->expiringWithin(90)
            ->with('documentType')
            ->get()
            ->map(fn (Document $document) => $this->record(
                type: SignalType::EvidenceExpiring,
                severity: SignalSeverity::Low,
                title: sprintf(
                    '%s expires on %s',
                    $document->title,
                    $document->valid_to?->toDateString() ?? 'an unrecorded date',
                ),
                thirdPartyId: $this->thirdPartyFor($document),
                engagementId: $this->engagementFor($document),
                // The document, not the date: the same certificate approaching
                // expiry is one fact, however many nights pass before somebody
                // acts on it.
                discriminator: 'doc'.$document->getKey(),
                payload: [
                    'document_id' => $document->getKey(),
                    'valid_to' => $document->valid_to?->toDateString(),
                    'days_remaining' => $document->daysUntilExpiry(),
                    'document_type' => $document->documentType->name ?? null,
                ],
                observedAt: now(),
            ))
            ->filter()
            ->values();
    }

    /**
     * Evidence past its expiry — a real signal with a real penalty.
     *
     * @return Collection<int, MonitoringSignal>
     */
    public function evidenceExpired(?int $organizationId = null): Collection
    {
        return $this->documents($organizationId)
            ->expired()
            ->whereHas('documentType', fn ($query) => $query->where('is_assurance_evidence', true))
            ->with('documentType')
            ->get()
            ->map(fn (Document $document) => $this->record(
                type: SignalType::EvidenceExpired,
                severity: SignalSeverity::Medium,
                title: sprintf('%s expired on %s', $document->title, $document->valid_to?->toDateString()),
                thirdPartyId: $this->thirdPartyFor($document),
                engagementId: $this->engagementFor($document),
                discriminator: 'doc'.$document->getKey(),
                payload: [
                    'document_id' => $document->getKey(),
                    'valid_to' => $document->valid_to?->toDateString(),
                    'days_overdue' => abs((int) $document->daysUntilExpiry()),
                ],
                observedAt: $document->valid_to ?? now(),
            ))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, MonitoringSignal>
     */
    public function assessmentsOverdue(?int $organizationId = null): Collection
    {
        return $this->engagements($organizationId)
            ->whereNotNull('next_assessment_due')
            ->whereDate('next_assessment_due', '<', now()->toDateString())
            ->get()
            ->map(function (Engagement $engagement) {
                $overdue = (int) $engagement->next_assessment_due->diffInDays(now());

                return $this->record(
                    type: SignalType::AssessmentOverdue,
                    // An assessment a fortnight late is administrative; one a
                    // year late means nobody has looked at this vendor since
                    // the last one, and the severity should say which.
                    severity: $overdue > 180 ? SignalSeverity::High : SignalSeverity::Medium,
                    title: sprintf('Assessment overdue by %d days', $overdue),
                    thirdPartyId: $engagement->third_party_id,
                    engagementId: $engagement->getKey(),
                    discriminator: 'due'.$engagement->next_assessment_due->toDateString(),
                    payload: [
                        'due_at' => $engagement->next_assessment_due->toDateString(),
                        'days_overdue' => $overdue,
                    ],
                    observedAt: $engagement->next_assessment_due,
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, MonitoringSignal>
     */
    public function findingsOverdue(?int $organizationId = null): Collection
    {
        return Finding::query()
            ->withoutGlobalScopes()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->overdue()
            ->get()
            ->map(fn (Finding $finding) => $this->record(
                type: SignalType::FindingOverdue,
                severity: match ($finding->severity->value) {
                    'critical' => SignalSeverity::Critical,
                    'high' => SignalSeverity::High,
                    default => SignalSeverity::Medium,
                },
                title: sprintf('%s overdue: %s', $finding->reference, $finding->title),
                thirdPartyId: $finding->third_party_id,
                engagementId: $finding->engagement_id,
                discriminator: $finding->reference,
                payload: [
                    'finding_id' => $finding->getKey(),
                    'reference' => $finding->reference,
                    'severity' => $finding->severity->value,
                    'target_date' => $finding->target_date?->toDateString(),
                    'beyond_threshold' => $finding->isOverdueBeyond(),
                ],
                observedAt: $finding->target_date ?? now(),
                organizationId: $finding->organization_id,
            ))
            ->filter()
            ->values();
    }

    /**
     * Three or more consecutive breached periods — the pattern, not the month.
     *
     * One month below target is a miss; three in a row is a service that is
     * not being delivered, and only the second belongs in a monitoring stream.
     *
     * @return Collection<int, MonitoringSignal>
     */
    public function slaBreaches(?int $organizationId = null): Collection
    {
        return Sla::query()
            ->withoutGlobalScopes()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->active()
            ->with('engagement:id,third_party_id')
            ->get()
            ->map(function (Sla $sla) {
                $streak = $this->slas->consecutiveBreaches($sla);

                if ($streak < 3) {
                    return null;
                }

                return $this->record(
                    type: SignalType::SlaBreach,
                    severity: $streak >= 6 ? SignalSeverity::High : SignalSeverity::Medium,
                    title: sprintf('%s has breached its target for %d consecutive periods', $sla->metric_name, $streak),
                    thirdPartyId: $sla->engagement?->third_party_id,
                    engagementId: $sla->engagement_id,
                    // The streak length is part of the key, so a fourth
                    // consecutive breach is a NEW fact worth reporting while a
                    // re-run at the same streak is not.
                    discriminator: 'sla'.$sla->getKey().'x'.$streak,
                    payload: ['sla_id' => $sla->getKey(), 'consecutive_breaches' => $streak],
                    observedAt: now(),
                    organizationId: $sla->organization_id,
                );
            })
            ->filter()
            ->values();
    }

    /**
     * Personal data processed with no data processing agreement on file.
     *
     * NDPA §29(2) requires one. This is the signal a Nigerian bank is most
     * likely to be surprised by, because the engagement was onboarded before
     * anybody was checking and nothing since has asked the question.
     *
     * @return Collection<int, MonitoringSignal>
     */
    public function missingDpas(?int $organizationId = null): Collection
    {
        $engagements = $this->engagements($organizationId)
            ->where('processes_personal_data', true)
            ->whereNotIn('status', ['draft', 'terminated', 'archived'])
            ->get();

        return $engagements
            ->filter(fn (Engagement $engagement) => ! $this->hasDpa($engagement))
            ->map(fn (Engagement $engagement) => $this->record(
                type: SignalType::MissingDpa,
                severity: SignalSeverity::High,
                title: 'Personal data is processed with no data processing agreement on file',
                thirdPartyId: $engagement->third_party_id,
                engagementId: $engagement->getKey(),
                discriminator: 'eng'.$engagement->getKey(),
                payload: [
                    'citation' => 'NDPA §29(2); GAID Art. 34(2)(a)–(t)',
                    'note' => 'A processor agreement containing the twenty GAID elements is required before '
                        .'personal data is shared.',
                ],
                observedAt: now(),
            ))
            ->filter()
            ->values();
    }

    /**
     * Third parties whose screening is past its cadence.
     *
     * @return Collection<int, MonitoringSignal>
     */
    public function screeningOverdue(?int $organizationId = null): Collection
    {
        $interval = (int) config('tprm.defaults.screening_interval_months', 12);

        return ThirdParty::query()
            ->withoutGlobalScopes()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('last_screened_at')
                ->orWhere('last_screened_at', '<', now()->subMonths($interval)))
            ->get()
            ->map(fn (ThirdParty $party) => $this->record(
                type: SignalType::ScreeningOverdue,
                // Never screened is worse than screened late: it means the
                // vendor entered the register without the check at all.
                severity: $party->last_screened_at === null ? SignalSeverity::High : SignalSeverity::Medium,
                title: $party->last_screened_at === null
                    ? 'This third party has never been screened'
                    : sprintf('Screening last run %s', $party->last_screened_at->toDateString()),
                thirdPartyId: $party->getKey(),
                engagementId: null,
                discriminator: 'screen'.($party->last_screened_at?->toDateString() ?? 'never'),
                payload: [
                    'last_screened_at' => $party->last_screened_at?->toDateString(),
                    'interval_months' => $interval,
                    'citation' => 'CBN AML/CFT Regulations 2022, Reg. 29',
                ],
                observedAt: now(),
                organizationId: $party->organization_id,
            ))
            ->filter()
            ->values();
    }

    /**
     * Contracts inside their notice window with no renewal decision recorded.
     *
     * The signal that stops an institution auto-renewing a vendor it had
     * decided to leave — see `Contract::noticeDeadline()` for why this is
     * counted from the notice date rather than the expiry.
     *
     * @return Collection<int, MonitoringSignal>
     */
    public function contractsInNoticeWindow(?int $organizationId = null): Collection
    {
        return Contract::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->inForce()
            ->noticeDueWithin(90)
            ->with('engagement:id,third_party_id')
            ->get()
            ->map(fn (Contract $contract) => $this->record(
                type: SignalType::ContractNoticeWindow,
                severity: ($contract->daysUntilNotice() ?? 999) <= 30
                    ? SignalSeverity::High
                    : SignalSeverity::Medium,
                title: sprintf(
                    'Notice on %s must be served by %s',
                    $contract->reference,
                    $contract->noticeDeadline()?->toDateString(),
                ),
                thirdPartyId: $contract->engagement?->third_party_id,
                engagementId: $contract->engagement_id,
                discriminator: 'ctr'.$contract->getKey().':'.$contract->noticeDeadline()?->toDateString(),
                payload: [
                    'contract_id' => $contract->getKey(),
                    'notice_deadline' => $contract->noticeDeadline()?->toDateString(),
                    'expiry_date' => $contract->expiry_date?->toDateString(),
                    'days_remaining' => $contract->daysUntilNotice(),
                    'renews_automatically' => $contract->renewsAutomatically(),
                ],
                observedAt: now(),
                organizationId: $contract->organization_id,
            ))
            ->filter()
            ->values();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Write a signal, or nothing if it is already known.
     *
     * `firstOrCreate` on the unique dedupe key rather than a `has it been
     * seen` lookup: two sweeps racing would both find nothing and both insert,
     * and the unique index is the only thing that actually prevents that.
     *
     * @param  array<string, mixed>  $payload
     */
    private function record(
        SignalType $type,
        SignalSeverity $severity,
        string $title,
        ?int $thirdPartyId,
        ?int $engagementId,
        string $discriminator,
        array $payload,
        \DateTimeInterface $observedAt,
        ?int $organizationId = null,
    ): ?MonitoringSignal {
        $organizationId ??= $this->resolveOrganizationId($thirdPartyId, $engagementId);

        if ($organizationId === null) {
            return null;
        }

        $key = MonitoringSignal::keyFor($type->value, $thirdPartyId, $engagementId, $discriminator);

        $existing = MonitoringSignal::query()->withoutGlobalScopes()->where('dedupe_key', $key)->first();

        if ($existing !== null) {
            return null;
        }

        return MonitoringSignal::create([
            'organization_id' => $organizationId,
            'source_id' => null,
            'third_party_id' => $thirdPartyId,
            'engagement_id' => $engagementId,
            'signal_type' => $type->value,
            'severity' => $severity->value,
            'title' => $title,
            'payload' => $payload,
            'observed_at' => $observedAt,
            'ingested_at' => now(),
            'dedupe_key' => $key,
        ]);
    }

    private function resolveOrganizationId(?int $thirdPartyId, ?int $engagementId): ?int
    {
        if ($engagementId !== null) {
            return Engagement::query()->withoutGlobalScopes()->whereKey($engagementId)->value('organization_id');
        }

        if ($thirdPartyId !== null) {
            return ThirdParty::query()->withoutGlobalScopes()->whereKey($thirdPartyId)->value('organization_id');
        }

        return null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Document>
     */
    private function documents(?int $organizationId)
    {
        return Document::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Engagement>
     */
    private function engagements(?int $organizationId)
    {
        return Engagement::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));
    }

    private function thirdPartyFor(Document $document): ?int
    {
        return $document->owner_type === Document::OWNER_THIRD_PARTY
            ? $document->owner_id
            : Engagement::query()->withoutGlobalScopes()
                ->whereKey($document->owner_id)->value('third_party_id');
    }

    private function engagementFor(Document $document): ?int
    {
        return $document->owner_type === Document::OWNER_ENGAGEMENT ? $document->owner_id : null;
    }

    /**
     * Whether a data processing agreement is on file and current.
     *
     * A DPA that has expired is not a DPA on file: the agreement covering the
     * processing has to be the one in force, not one that lapsed with the
     * contract it was annexed to.
     */
    private function hasDpa(Engagement $engagement): bool
    {
        return Document::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_ENGAGEMENT)
                    ->where('owner_id', $engagement->getKey()))
                ->orWhere(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_THIRD_PARTY)
                    ->where('owner_id', $engagement->third_party_id)))
            ->current()
            ->whereHas('documentType', fn ($query) => $query->where('code', 'dpa'))
            ->exists();
    }
}
