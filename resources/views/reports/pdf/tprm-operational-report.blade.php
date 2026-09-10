@extends('reports.pdf.layout')

{{--
    A standard operational report — FR-RPT-07.

    ONE TEMPLATE FOR ALL EIGHT, because a report here is a contract — a title,
    headings and rows — rather than eight bespoke queries. A second template
    would be a second place for the provenance block to drift.

    WIDE REPORTS ARE TRUNCATED AND SAY SO. The screening log carries sixteen
    columns; A3 landscape holds about ten legibly. The printed set stops at ten
    and names the rest, because a table silently cut at the page edge reads as
    the whole report.
--}}

@php
    $printedColumns = 10;
    $printed = array_slice($headers, 0, $printedColumns);
    $omitted = array_slice($headers, $printedColumns);
@endphp

@section('body')

    <div class="section-block">
        <a name="section-report"></a>
        <h1 class="section">{{ $title }}</h1>

        <div class="note">{{ $subtitle }}</div>

        @if ($omitted !== [])
            <p class="muted" style="font-size: 7.5pt;">
                {{ count($omitted) }} further {{ Str::plural('column', count($omitted)) }} are held in this
                report and are printed in the spreadsheet, not here: {{ implode(', ', $omitted) }}.
            </p>
        @endif

        @if (count($rows) === 0)
            <div class="empty">
                No rows. Read this against the scope on the cover page before treating it as a statement that
                there is nothing outstanding.
            </div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($printed as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
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

@endsection
