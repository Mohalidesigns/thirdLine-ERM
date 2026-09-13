@extends('reports.pdf.layout')

{{--
    The Board and Risk Committee pack — FR-RPT-05.

    IT OPENS WITH THE NARRATIVE AND SAYS WHERE THE NARRATIVE CAME FROM. A
    committee reading an assessment of its own third-party exposure is entitled
    to know whether a person wrote it, a model drafted it, or the product
    assembled it from the figures — and the answer is printed under the
    heading, not buried in an appendix.

    THE FIGURES ARE THE FROZEN ONES. This template reads `$figures` off the
    pack row rather than recomputing anything, so a pack reprinted in June
    matches what the committee read in March. That is the entire reason
    `tp_board_packs` exists.

    EVERY SECTION LEADS WITH ITS GAP. The exit-readiness block opens with the
    engagements that require a plan and have none, not with the count of plans
    that exist — a dashboard of traffic lights over what has been written
    flatters a programme that has written three and needs thirty.
--}}

@php
    $portfolio = $figures['portfolio'] ?? [];
    $findings = $figures['findings'] ?? [];
    $assessments = $figures['overdue_assessments'] ?? [];
    $evidence = $figures['expiring_evidence'] ?? [];
    $incidents = $figures['incidents'] ?? [];
    $exit = $figures['exit_readiness'] ?? [];
    $concentration = $figures['concentration'] ?? [];
    $waivers = $figures['overrides_and_waivers'] ?? [];

    $absent = fn ($value) => $value === null ? 'Not computed' : $value;
@endphp

@section('body')

    {{-- ------------------------------------------------------- narrative --}}
    <div class="section-block">
        <a name="section-narrative"></a>
        <h1 class="section">Assessment</h1>

        <div class="note">{{ $pack->narrativeProvenance() }}</div>

        @if (blank($narrative))
            <div class="empty">No narrative has been drafted for this pack.</div>
        @else
            @foreach (preg_split('/\n\s*\n/', trim($narrative)) as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        @endif
    </div>

    {{-- ------------------------------------------------------- portfolio --}}
    <div class="section-block section-break">
        <a name="section-portfolio"></a>
        <h1 class="section">Portfolio composition</h1>

        <table class="kpi-grid">
            <tr>
                <td class="kpi">
                    <div class="label">Live engagements</div>
                    <div class="value">{{ $portfolio['total'] ?? 0 }}</div>
                    <div class="note">{{ $portfolio['supports_critical_function'] ?? 0 }} support a critical function</div>
                </td>
                <td class="kpi">
                    <div class="label">Mean residual</div>
                    <div class="value">{{ $absent($portfolio['mean_residual'] ?? null) }}</div>
                    {{-- The denominator is stated. An average over the scored
                         population is a different number from an average over
                         the register, and only one of them is honest. --}}
                    <div class="note">across {{ $portfolio['scored'] ?? 0 }} scored engagements</div>
                </td>
                <td class="kpi">
                    <div class="label">Unscored</div>
                    <div class="value">{{ $portfolio['unscored'] ?? 0 }}</div>
                    <div class="note">excluded from the average above</div>
                </td>
                <td class="kpi">
                    <div class="label">Not tiered</div>
                    <div class="value">{{ $portfolio['untiered'] ?? 0 }}</div>
                    <div class="note">not found to be low risk — not looked at</div>
                </td>
            </tr>
        </table>

        <h2>By tier</h2>
        <table class="data">
            <thead><tr><th>Tier</th><th class="num">Engagements</th></tr></thead>
            <tbody>
                @foreach ($portfolio['by_tier'] ?? [] as $tier)
                    <tr><td>{{ $tier['label'] }}</td><td class="num">{{ $tier['count'] }}</td></tr>
                @endforeach
                <tr><td class="muted">Not tiered</td><td class="num">{{ $portfolio['untiered'] ?? 0 }}</td></tr>
            </tbody>
        </table>

        <h2>By category</h2>
        <table class="data">
            <thead><tr><th>Category</th><th class="num">Engagements</th><th class="num">Critical or High</th></tr></thead>
            <tbody>
                @forelse ($portfolio['by_category'] ?? [] as $category)
                    <tr>
                        <td>{{ $category['category'] }}</td>
                        <td class="num">{{ $category['count'] }}</td>
                        <td class="num">{{ $category['critical_or_high'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No engagements on the register.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- -------------------------------------------------- top exposures --}}
    <div class="section-block section-break">
        <a name="section-exposures"></a>
        <h1 class="section">Top exposures</h1>

        <table class="data">
            <thead>
                <tr>
                    <th>Engagement</th><th>Provider</th><th>Service</th><th>Tier</th>
                    <th class="num">Residual</th><th>Band</th><th>Critical function</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($figures['top_exposures'] ?? [] as $row)
                    <tr>
                        <td>{{ $row['reference'] }}</td>
                        <td>{{ $row['provider'] }}</td>
                        <td>{{ $row['service'] }}</td>
                        <td>{{ $row['tier'] }}</td>
                        <td class="num">{{ $row['residual_score'] }}</td>
                        <td>
                            <span class="badge badge-{{ strtolower($row['residual_band']) === 'critical' ? 'critical' : (strtolower($row['residual_band']) === 'high' ? 'high' : 'medium') }}">
                                {{ $row['residual_band'] }}
                            </span>
                        </td>
                        <td>{{ $row['supports_critical_function'] ? 'Yes' : 'No' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No engagement carries a residual score.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ------------------------------------- critical function dependency --}}
    <div class="section-block section-break">
        <a name="section-dependency"></a>
        <h1 class="section">Critical function dependency</h1>

        <table class="data">
            <thead>
                <tr><th>Function</th><th>Criticality</th><th class="num">RTO (hrs)</th><th class="num">Providers</th><th>Depends on</th></tr>
            </thead>
            <tbody>
                @forelse ($figures['critical_functions'] ?? [] as $function)
                    <tr>
                        <td>
                            <strong>{{ $function['function_code'] }}</strong> {{ $function['function'] }}
                            @if ($function['single_provider'])
                                <span class="badge badge-critical">Single provider</span>
                            @endif
                        </td>
                        <td>{{ $function['criticality'] }}</td>
                        <td class="num">{{ $function['rto_hours'] ?? '—' }}</td>
                        <td class="num">{{ $function['provider_count'] }}</td>
                        <td>{{ collect($function['providers'])->pluck('provider')->implode(', ') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No critical or important function is linked to an engagement.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ----------------------------------------------------- concentration --}}
    <div class="section-block section-break">
        <a name="section-concentration"></a>
        <h1 class="section">Concentration</h1>

        <div class="note">
            Herfindahl-Hirschman index: <strong>{{ $absent($concentration['hhi'] ?? null) }}</strong> —
            {{ $concentration['band'] ?? 'unclassified' }}.
        </div>

        <table class="data">
            <thead><tr><th>Provider group</th><th class="num">Share</th><th class="num">Engagements</th><th class="num">Critical functions</th></tr></thead>
            <tbody>
                @forelse ($concentration['clusters'] ?? [] as $cluster)
                    <tr>
                        <td>{{ $cluster['name'] }}</td>
                        <td class="num">{{ $cluster['share'] === null ? '—' : round($cluster['share'] * 100, 1).'%' }}</td>
                        <td class="num">{{ $cluster['engagements'] }}</td>
                        <td class="num">{{ $cluster['critical_functions'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No concentration analysis has been run.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ---------------------------------------------------------- findings --}}
    <div class="section-block section-break">
        <a name="section-findings"></a>
        <h1 class="section">Findings, assessments and evidence</h1>

        <table class="kpi-grid">
            <tr>
                <td class="kpi">
                    <div class="label">Open findings</div>
                    <div class="value">{{ $findings['open'] ?? 0 }}</div>
                    <div class="note">{{ $findings['overdue'] ?? 0 }} past their remediation date</div>
                </td>
                <td class="kpi">
                    <div class="label">Mean finding age</div>
                    <div class="value">{{ $absent($findings['mean_age_days'] ?? null) }}</div>
                    <div class="note">days, from identification</div>
                </td>
                <td class="kpi">
                    <div class="label">Overdue assessments</div>
                    <div class="value">{{ $assessments['overdue'] ?? 0 }}</div>
                    <div class="note">{{ $assessments['critical_or_high_overdue'] ?? 0 }} Critical or High</div>
                </td>
                <td class="kpi">
                    <div class="label">Evidence already expired</div>
                    <div class="value">{{ $evidence['already_expired'] ?? 0 }}</div>
                    {{-- The horizon is stated only when the snapshot carries
                         it. A pack whose figures predate the field must not
                         print "90 days" and assert a window nobody set. --}}
                    <div class="note">
                        {{ $evidence['expiring'] ?? 0 }} expiring
                        @isset($evidence['horizon_days'])
                            within {{ $evidence['horizon_days'] }} days
                        @else
                            within the reporting horizon
                        @endisset
                    </div>
                </td>
            </tr>
        </table>

        @if (($assessments['no_cadence_set'] ?? 0) > 0)
            <div class="note">
                {{ $assessments['no_cadence_set'] }} engagements have no assessment cadence set at all. They are
                not overdue, because nothing is due — which is a different problem and not a smaller one.
            </div>
        @endif

        <h2>Open findings by severity</h2>
        <table class="data">
            <thead><tr><th>Severity</th><th class="num">Open</th></tr></thead>
            <tbody>
                @foreach ($findings['by_severity'] ?? [] as $row)
                    <tr><td>{{ $row['severity'] }}</td><td class="num">{{ $row['count'] }}</td></tr>
                @endforeach
            </tbody>
        </table>

        <h2>Open findings by age</h2>
        <table class="data">
            <thead><tr><th>Days open</th><th class="num">Findings</th></tr></thead>
            <tbody>
                @foreach ($findings['by_age'] ?? [] as $bucket => $count)
                    <tr><td>{{ $bucket === 'over_180' ? 'Over 180' : $bucket }}</td><td class="num">{{ $count }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ------------------------------------------- incidents and exit ------}}
    <div class="section-block section-break">
        <a name="section-incidents"></a>
        <h1 class="section">Incidents, exit readiness and exceptions</h1>

        <table class="kpi-grid">
            <tr>
                <td class="kpi">
                    <div class="label">Incidents (12 months)</div>
                    <div class="value">{{ $incidents['count'] ?? 0 }}</div>
                    <div class="note">{{ $incidents['personal_data'] ?? 0 }} involved personal data</div>
                </td>
                <td class="kpi">
                    <div class="label">Estimated loss</div>
                    <div class="value">{{ $absent($incidents['estimated_loss_major'] ?? null) }}</div>
                    <div class="note">{{ $incidents['loss_not_quantified'] ?? 0 }} incidents carry no quantified loss</div>
                </td>
                <td class="kpi">
                    <div class="label">Require an exit plan and have none</div>
                    <div class="value">{{ $exit['no_plan'] ?? 0 }}</div>
                    <div class="note">of {{ $exit['require_a_plan'] ?? 0 }} that require one</div>
                </td>
                <td class="kpi">
                    <div class="label">Plans never exercised</div>
                    <div class="value">{{ $exit['never_tested'] ?? 0 }}</div>
                    <div class="note">a document, not a capability</div>
                </td>
            </tr>
        </table>

        <h2>Overrides and waivers</h2>
        <table class="data">
            <thead><tr><th>Type</th><th class="num">Approved and in force</th></tr></thead>
            <tbody>
                @forelse ($waivers['by_type'] ?? [] as $row)
                    <tr><td>{{ $row['type'] }}</td><td class="num">{{ $row['count'] }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">No waiver is approved.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if (($waivers['lapsed_but_not_withdrawn'] ?? 0) > 0 || ($waivers['no_expiry_set'] ?? 0) > 0)
            <div class="note">
                {{ $waivers['lapsed_but_not_withdrawn'] ?? 0 }} approved waivers have passed their expiry without
                being withdrawn, and {{ $waivers['no_expiry_set'] ?? 0 }} carry no expiry date at all. Both are
                exceptions still in force with no live authority behind them.
            </div>
        @endif
    </div>

@endsection
