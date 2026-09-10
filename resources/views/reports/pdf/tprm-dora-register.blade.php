@extends('reports.pdf.layout')

{{--
    The Register of Information as a document — FR-RPT-02.

    THE XLSX IS THE SUBMISSION AND THIS IS THE READ-THROUGH. Fourteen tables on
    paper is a reference copy for a committee or a file, not a form to fill in,
    so this leads with the coverage summary — which tables are complete, which
    are partial and why — because that is the part a reader has to act on.

    A PARTIAL TABLE IS MARKED ON ITS OWN PAGE AS WELL AS IN THE SUMMARY. A
    reader who opens at RT.01.02 must not have to leaf back to the front to
    learn that its LEI column is empty by design rather than by omission.

    WIDE TABLES SCROLL OFF THE PAGE AND THAT IS STATED. RT.02.02 has nineteen
    columns; the PDF prints the first eight and says how many were not printed,
    which is honest, where silently truncating is not. The workbook has all of
    them.
--}}

@php
    $printedColumns = 8;
@endphp

@section('body')

    <div class="section-block">
        <a name="section-coverage"></a>
        <h1 class="section">Coverage</h1>

        <div class="note">
            <strong>Verify before an EU submission.</strong> Field cardinality in this model has not been
            reconciled against Commission Implementing Regulation (EU) 2024/2956 Annexes I/II; the ESAs issued
            field changes in JC 2024 79. For a Nigerian deployment this register is the internal structure and
            exceeds CBN Cyber Framework Appendix II §1.4 — it is not a submission to the CBN.
        </div>

        <table class="data">
            <thead>
                <tr>
                    <th>Template</th>
                    <th>Title</th>
                    <th class="num">Rows</th>
                    <th>Coverage</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tables as $table)
                    <tr>
                        <td><strong>{{ $table['code'] }}</strong></td>
                        <td>{{ $table['title'] }}</td>
                        <td class="num">{{ count($table['rows']) }}</td>
                        <td>
                            <span class="badge {{ $table['coverage'] === 'complete' ? 'badge-low' : 'badge-medium' }}">
                                {{ ucfirst($table['coverage']) }}
                            </span>
                        </td>
                        <td>{{ $table['note'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @foreach ($tables as $table)
        <div class="section-block section-break">
            <a name="section-{{ Str::slug($table['code']) }}"></a>
            <h1 class="section">{{ $table['code'] }} — {{ $table['title'] }}</h1>

            @if ($table['coverage'] !== 'complete')
                <div class="note"><strong>Partial coverage.</strong> {{ $table['note'] }}</div>
            @endif

            @php
                $headers = array_slice($table['headers'], 0, $printedColumns);
                $omitted = count($table['headers']) - count($headers);
            @endphp

            @if ($omitted > 0)
                <p class="muted" style="font-size: 7.5pt;">
                    {{ $omitted }} further {{ Str::plural('column', $omitted) }} are held in this table and are
                    printed in the workbook, not here:
                    {{ implode(', ', array_slice($table['headers'], $printedColumns)) }}.
                </p>
            @endif

            @if (count($table['rows']) === 0)
                <div class="empty">No rows. Read this against the coverage note above before treating it as
                    a statement that there is nothing to declare.</div>
            @else
                <table class="data">
                    <thead>
                        <tr>
                            @foreach ($headers as $header)
                                <th>{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table['rows'] as $row)
                            <tr>
                                @foreach (array_slice(array_values($row), 0, $printedColumns) as $value)
                                    <td class="{{ is_numeric($value) ? 'num' : '' }}">
                                        {{ $value === null || $value === '' ? '—' : $value }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

@endsection
