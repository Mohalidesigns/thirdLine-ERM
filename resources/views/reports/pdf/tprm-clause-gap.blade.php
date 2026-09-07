@extends('reports.pdf.layout')

{{--
    The Clause Gap Report — TRD §12.3 and the artefact AC-06 produces.

    IT IS WRITTEN TO BE SENT TO THE VENDOR, and that shapes every choice on the
    page. A gap needs three things to be actionable by whoever receives it: the
    clause, the authority that requires it, and the words to put in the
    contract. A report that says "audit rights: missing" starts a conversation
    about whether we really need it; one that quotes CBN Cyber 2024 §2.3(v) and
    supplies model text starts a redline.

    BLOCKING GAPS COME FIRST AND ARE MARKED AS BLOCKING. The reader has to know
    which of these stop the engagement proceeding and which are simply
    required, because those are different conversations on different
    timescales.

    WHERE MODEL TEXT IS ABSENT THE REPORT SAYS SO. Drafting contract language
    is a lawyer's job; a plausible-looking clause pasted into a real agreement
    is a liability, not a feature. The tenant's legal function supplies its own
    through the clause library, and until it does this report asks for the
    obligation in plain words rather than inventing the wording.
--}}

@php
    $blocking = collect($clauses)->where('blocks_activation', true)->values();
    $other = collect($clauses)->where('blocks_activation', false)->values();
@endphp

@section('body')

    <div class="section-block">
        <a name="section-summary"></a>
        <h1 class="section">Contract clause gap report</h1>

        <div class="note">
            <strong>{{ $engagement->name }}</strong> ({{ $engagement->reference }}) —
            {{ $thirdParty?->legal_name ?? 'the provider' }}<br>
            @if ($contract)
                Assessed against {{ $contract->reference }}, {{ $contract->title }}@if ($contract->effective_date), effective {{ $contract->effective_date->toFormattedDateString() }}@endif.<br>
            @else
                No executed contract is recorded against this engagement, so every required clause is reported as absent.<br>
            @endif
            Produced {{ now()->toFormattedDateString() }}.
            {{ count($clauses) }} clause(s) apply to this engagement;
            {{ $blocking->count() }} of the gaps below are conditions of activation.
        </div>

        @if ($blocking->isEmpty() && $other->isEmpty())
            <div class="note">
                Every clause required of this engagement is present in the contract and has been reviewed.
                No gaps to report.
            </div>
        @endif
    </div>

    @if ($blocking->isNotEmpty())
        <div class="section-block">
            <a name="section-blocking"></a>
            <h1 class="section">Conditions of activation</h1>

            <div class="note">
                The engagement cannot be activated while these remain unaddressed. Each can be added by
                amendment, or waived individually by the risk function with a rationale and an expiry — a
                waiver is recorded on the override register and reported to the risk committee.
            </div>

            @foreach ($blocking as $row)
                @include('reports.pdf.sections.tprm-clause-row', ['row' => $row, 'blocking' => true])
            @endforeach
        </div>
    @endif

    @if ($other->isNotEmpty())
        <div class="section-block">
            <a name="section-other"></a>
            <h1 class="section">Required, but not conditions of activation</h1>

            <div class="note">
                These are required of the arrangement and should be addressed at the next amendment or renewal.
                They do not stop the engagement proceeding.
            </div>

            @foreach ($other as $row)
                @include('reports.pdf.sections.tprm-clause-row', ['row' => $row, 'blocking' => false])
            @endforeach
        </div>
    @endif

    @if (! empty($unresolvable))
        <div class="section-block">
            <a name="section-unresolvable"></a>
            <h1 class="section">Could not be assessed</h1>

            <div class="note">
                Whether these clauses apply depends on facts the engagement record does not yet hold. They are
                listed rather than omitted: a clause left silently out of a gap report is one nobody knows to
                ask about.
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 18%">Clause</th>
                        <th style="width: 42%">Requirement</th>
                        <th style="width: 40%">Missing from the engagement record</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($unresolvable as $row)
                        <tr>
                            <td>{{ $row['code'] }}</td>
                            <td>{{ $row['title'] }}</td>
                            <td>{{ implode(', ', $row['missing_facts']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
