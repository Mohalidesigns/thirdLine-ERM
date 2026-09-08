@extends('reports.pdf.layout')

{{--
    A sectioned regulatory pack — the NDPA Compliance Audit Return evidence
    pack (FR-RPT-03) and the PCI DSS 12.8 pack (FR-RPT-04) share this shape.

    THE COVERAGE SUMMARY IS THE FIRST PAGE, NOT AN APPENDIX. Both packs are
    read by somebody deciding whether they can file or be assessed, and the
    answer to that is which sections have gaps — not the contents of the
    sections, which they will read afterwards if at all.

    THE VARIABLE IS `packSections`, NOT `sections`. The layout reserves
    `$sections` for its own table of contents, and passing a differently
    shaped array under that name renders the cover page against the wrong
    structure — which fails inside dompdf, several frames from anything that
    names this file.

    WIDE SECTIONS ARE TRUNCATED AND SAY SO. CAR-2 carries twenty-six columns
    because Art. 34(2) has twenty elements; the PDF prints the first eight and
    names the rest. The workbook holds all of them, and truncating silently
    would let a reader believe they had seen the whole element-by-element
    verdict.
--}}

@php
    $printedColumns = 8;
@endphp

@section('body')

    <div class="section-block">
        <a name="section-coverage"></a>
        <h1 class="section">What this pack contains, and what it could not answer</h1>

        <table class="data">
            <thead>
                <tr>
                    <th>Section</th>
                    <th>Title</th>
                    <th>Authority</th>
                    <th class="num">Rows</th>
                    <th>Coverage</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($packSections as $section)
                    <tr>
                        <td><strong>{{ $section['code'] }}</strong></td>
                        <td>{{ $section['title'] }}</td>
                        <td>{{ $section['citation'] ?? '' }}</td>
                        <td class="num">{{ count($section['rows']) }}</td>
                        <td>
                            <span class="badge {{ $section['coverage'] === 'complete' ? 'badge-low' : 'badge-medium' }}">
                                {{ ucfirst($section['coverage']) }}
                            </span>
                        </td>
                    </tr>
                    @if (! empty($section['note']))
                        <tr>
                            <td></td>
                            <td colspan="4" class="muted" style="font-size: 7.5pt;">{{ $section['note'] }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    @foreach ($packSections as $section)
        <div class="section-block section-break">
            <a name="section-{{ Str::slug($section['code']) }}"></a>
            <h1 class="section">{{ $section['code'] }} — {{ $section['title'] }}</h1>

            <div class="note">
                {{ $section['citation'] ?? '' }}
                @if (! empty($section['note']))
                    <br>{{ $section['note'] }}
                @endif
            </div>

            @php
                $headers = array_slice($section['headers'], 0, $printedColumns);
                $omitted = count($section['headers']) - count($headers);
            @endphp

            @if ($omitted > 0)
                <p class="muted" style="font-size: 7.5pt;">
                    {{ $omitted }} further {{ Str::plural('column', $omitted) }} are held in this section and
                    printed in the workbook, not here:
                    {{ implode(', ', array_slice($section['headers'], $printedColumns)) }}.
                </p>
            @endif

            @if (count($section['rows']) === 0)
                <div class="empty">
                    No rows. Read this against the note above before treating it as a statement that there is
                    nothing to declare.
                </div>
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
                        @foreach ($section['rows'] as $row)
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
