{{--
    Master layout for every PDF this application produces.

    Structure: cover page → contents → body, with a running header and a footer
    carrying "Page X of Y" on every page after the cover.

    Page numbering uses CSS counters (`counter(page)` / `counter(pages)`) inside
    a fixed-position footer. dompdf supports these natively, which avoids
    turning on isPhpEnabled — running PHP inside the PDF renderer to print a
    page number is not a trade worth making in an application that renders
    tenant data.

    Fonts: DejaVu Sans throughout. It is the only bundled dompdf font that
    carries ₦ (U+20A6); the default Helvetica renders it as a hollow box, which
    on a Nigerian bank's board pack is not a cosmetic problem.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Report' }}</title>
    <style>
        @page {
            margin: 28mm 16mm 22mm 16mm;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            line-height: 1.45;
            color: #1f2933;
            margin: 0;
        }

        /* ---------- Running header and footer ---------- */

        #page-header {
            position: fixed;
            top: -18mm;
            left: 0;
            right: 0;
            height: 12mm;
            border-bottom: 0.6pt solid #d9dee5;
            font-size: 7.5pt;
            color: #5b6773;
        }

        #page-header .left { float: left; }
        #page-header .right { float: right; text-align: right; }

        #page-footer {
            position: fixed;
            bottom: -14mm;
            left: 0;
            right: 0;
            height: 10mm;
            border-top: 0.6pt solid #d9dee5;
            padding-top: 2mm;
            font-size: 7.5pt;
            color: #5b6773;
        }

        #page-footer .left { float: left; }
        #page-footer .right { float: right; text-align: right; }

        /* dompdf resolves these against the generated page count. */
        .page-number:after { content: counter(page); }
        .page-count:after { content: counter(pages); }

        /* ---------- Cover ---------- */

        .cover {
            page-break-after: always;
            /* Pull the cover up into the header margin so it reads as a title
               page rather than a first content page. */
            margin-top: -10mm;
        }

        .cover-rule {
            height: 4mm;
            background: {{ $branding['primary_colour'] }};
            border-bottom: 1.5mm solid {{ $branding['accent_colour'] }};
            margin-bottom: 16mm;
        }

        .cover-logo { max-height: 22mm; margin-bottom: 10mm; }

        .cover-org {
            font-size: 15pt;
            font-weight: bold;
            color: {{ $branding['primary_colour'] }};
            margin-bottom: 1mm;
        }

        .cover-org-meta { font-size: 8.5pt; color: #5b6773; margin-bottom: 26mm; }

        .cover-title {
            font-size: 30pt;
            font-weight: bold;
            color: {{ $branding['primary_colour'] }};
            line-height: 1.15;
            margin-bottom: 4mm;
        }

        .cover-subtitle { font-size: 12pt; color: #5b6773; margin-bottom: 20mm; }

        .cover-stamp {
            border-top: 0.6pt solid #d9dee5;
            padding-top: 5mm;
            font-size: 8.5pt;
            color: #5b6773;
        }

        .cover-stamp table { width: 100%; border-collapse: collapse; }
        .cover-stamp td { padding: 1.2mm 0; vertical-align: top; }
        .cover-stamp td.label { width: 42mm; color: #8b95a1; }

        .cover-confidential {
            margin-top: 18mm;
            padding: 3mm 4mm;
            background: #f4f6f8;
            border-left: 1mm solid {{ $branding['accent_colour'] }};
            font-size: 8pt;
            color: #5b6773;
        }

        /* ---------- Contents ---------- */

        .contents { page-break-after: always; }

        .contents ol { margin: 0; padding-left: 6mm; }
        .contents li { padding: 1.6mm 0; border-bottom: 0.4pt dotted #d9dee5; }
        .contents a { color: {{ $branding['primary_colour'] }}; text-decoration: none; }

        /* ---------- Body ---------- */

        h1.section {
            font-size: 14pt;
            color: {{ $branding['primary_colour'] }};
            border-bottom: 0.8pt solid {{ $branding['accent_colour'] }};
            padding-bottom: 2mm;
            margin: 0 0 5mm 0;
        }

        h2 { font-size: 11pt; color: {{ $branding['primary_colour'] }}; margin: 7mm 0 2.5mm 0; }

        p { margin: 0 0 3mm 0; }

        .section-block { page-break-inside: auto; }
        .section-break { page-break-before: always; }

        table.data {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            margin-bottom: 5mm;
        }

        table.data thead th {
            background: {{ $branding['primary_colour'] }};
            color: #ffffff;
            text-align: left;
            padding: 2mm;
            font-weight: bold;
        }

        table.data tbody td {
            padding: 1.8mm 2mm;
            border-bottom: 0.4pt solid #e4e8ec;
            vertical-align: top;
        }

        table.data tbody tr:nth-child(even) td { background: #f8f9fb; }

        table.data .num { text-align: right; }

        /* Repeat the header row when a table spans pages. */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        .kpi-grid { width: 100%; border-collapse: separate; border-spacing: 3mm 0; margin-bottom: 6mm; }
        .kpi {
            background: #f4f6f8;
            border-left: 1mm solid {{ $branding['primary_colour'] }};
            padding: 3mm;
            width: 25%;
        }
        .kpi .label { font-size: 7.5pt; color: #5b6773; text-transform: uppercase; letter-spacing: 0.3pt; }
        .kpi .value { font-size: 16pt; font-weight: bold; color: {{ $branding['primary_colour'] }}; }
        .kpi .note { font-size: 7pt; color: #8b95a1; }

        .badge {
            display: inline-block;
            padding: 0.6mm 2mm;
            border-radius: 2mm;
            font-size: 7pt;
            font-weight: bold;
        }
        .badge-critical { background: #fbe4e4; color: #a02222; }
        .badge-high     { background: #fdecdc; color: #9c4c10; }
        .badge-medium   { background: #fdf5d9; color: #8a6a10; }
        .badge-low      { background: #e2f2e6; color: #1f5f33; }

        .muted { color: #8b95a1; }
        .empty {
            padding: 6mm;
            text-align: center;
            color: #8b95a1;
            background: #f8f9fb;
            font-size: 8.5pt;
        }

        .note {
            font-size: 7.5pt;
            color: #5b6773;
            background: #f4f6f8;
            padding: 2.5mm 3mm;
            border-left: 0.8mm solid #c3ccd6;
            margin-bottom: 4mm;
        }
    </style>
</head>
<body>

<div id="page-header">
    <div class="left">{{ $branding['organization_name'] }}</div>
    <div class="right">{{ $title }}@if (! empty($periodLabel)) — {{ $periodLabel }} @endif</div>
</div>

<div id="page-footer">
    <div class="left">
        Generated {{ $generatedAt->format('d M Y H:i') }}
        @if (! empty($generatedBy)) by {{ $generatedBy }} @endif
    </div>
    <div class="right">Page <span class="page-number"></span> of <span class="page-count"></span></div>
</div>

{{-- ---------------------------------------------------------------- Cover --}}
<div class="cover">
    <div class="cover-rule"></div>

    @if ($branding['logo'])
        <img src="{{ $branding['logo'] }}" class="cover-logo" alt="">
    @endif

    <div class="cover-org">{{ $branding['organization_name'] }}</div>
    <div class="cover-org-meta">
        @php
            $orgMeta = array_filter([
                $branding['institution_type'] ? ucwords(str_replace('_', ' ', $branding['institution_type'])) : null,
                $branding['cbn_institution_code'] ? 'CBN code '.$branding['cbn_institution_code'] : null,
                $branding['rc_number'] ? 'RC '.$branding['rc_number'] : null,
            ]);
        @endphp
        {{ implode(' · ', $orgMeta) }}
    </div>

    <div class="cover-title">{{ $title }}</div>
    @if (! empty($subtitle))
        <div class="cover-subtitle">{{ $subtitle }}</div>
    @endif

    <div class="cover-stamp">
        <table>
            @if (! empty($periodLabel))
                <tr><td class="label">Reporting period</td><td>{{ $periodLabel }}</td></tr>
            @endif
            {{-- The as-at date is the position the content describes. It is
                 deliberately separate from the generated-at timestamp: a pack
                 run in April for the March position must say March. --}}
            <tr>
                <td class="label">Position as at</td>
                <td>{{ ($periodAsAt ?? $generatedAt)->format('d F Y') }}</td>
            </tr>
            <tr><td class="label">Generated</td><td>{{ $generatedAt->format('d F Y \a\t H:i') }}</td></tr>
            @if (! empty($generatedBy))
                <tr><td class="label">Generated by</td><td>{{ $generatedBy }}</td></tr>
            @endif
            {{-- Preparer and reviewer are named separately from "generated by"
                 because a supervisory return is signed by two people and the
                 person who pressed the button is frequently neither. An
                 unreviewed return says so rather than leaving the row off,
                 which would read as though review had happened. --}}
            @if (! empty($preparedBy))
                <tr><td class="label">Prepared by</td><td>{{ $preparedBy }}</td></tr>
            @endif
            @if (! empty($reviewedBy))
                <tr><td class="label">Reviewed by</td><td>{{ $reviewedBy }}</td></tr>
            @elseif (! empty($reviewRequired))
                <tr><td class="label">Reviewed by</td><td>Not reviewed</td></tr>
            @endif
            @if (! empty($version))
                <tr><td class="label">Version</td><td>{{ $version }}</td></tr>
            @endif
        </table>
    </div>

    {{-- Filter provenance. AC-13 requires the export to reconcile row for row
         to the filtered view it came from, and a reader cannot check that
         without being told which filters were applied. "All records" is
         printed explicitly rather than omitted: a blank here is indis-
         tinguishable from a filter nobody bothered to record. --}}
    @if (! empty($provenance))
        <div class="cover-stamp" style="margin-top: 6mm;">
            <table>
                <tr><td class="label" colspan="2"><strong>View this export was taken from</strong></td></tr>
                @foreach ($provenance as $label => $value)
                    <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="cover-confidential">
        <strong>Confidential.</strong> This document contains {{ $branding['organization_name'] }} risk management
        information and is intended only for its named recipients. Figures are drawn from the risk register as at the
        position date above and will move as the register is updated.
    </div>
</div>

{{-- ------------------------------------------------------------- Contents --}}
@if (! empty($sections))
    <div class="contents">
        <h1 class="section">Contents</h1>
        <ol>
            @foreach ($sections as $section)
                <li><a href="#{{ $section['anchor'] }}">{{ $section['title'] }}</a></li>
            @endforeach
        </ol>
        <p class="muted" style="margin-top: 8mm; font-size: 8pt;">
            Entries are internal links — selecting one jumps to that section.
        </p>
    </div>
@endif

{{-- ----------------------------------------------------------------- Body --}}
@yield('body')

</body>
</html>
