@extends('reports.pdf.layout')

{{--
    A continuity plan, printed.

    THE DOCUMENT-CONTROL BLOCK IS FIRST AND IS NOT DECORATION. A printed plan
    leaves the platform and is photocopied, emailed and left in drawers; the
    only thing that lets somebody holding a copy know whether it is the current
    one is the version, the approval and the review date on its face. A plan
    printed without them is the laminated binder this module exists to replace.

    Bound sections print their live table AND the date the section was last
    verified against its source. An approved plan prints its frozen snapshot,
    so the copy an examiner was handed in March is still the copy they were
    handed — see PlanAssembler::document().
--}}

@section('body')

    <div class="section-block">
        <a name="document-control"></a>
        <h1 class="section">Document control</h1>

        <table class="data">
            <tbody>
                <tr><td style="width: 45mm;"><strong>Plan</strong></td><td>{{ $plan['title'] }}</td></tr>
                <tr><td><strong>Type</strong></td><td>{{ $plan['plan_type_label'] }}</td></tr>
                <tr><td><strong>Version</strong></td><td>{{ $plan['version'] }}</td></tr>
                <tr>
                    <td><strong>Status</strong></td>
                    <td>{{ ucfirst($plan['status']) }}@if (! empty($plan['is_superseded'])) — superseded by a later version @endif</td>
                </tr>
                <tr>
                    <td><strong>Owner</strong></td>
                    <td>{{ $plan['owner'] ?? 'Not assigned' }}</td>
                </tr>
                <tr>
                    <td><strong>Approved by</strong></td>
                    <td>
                        @if ($plan['approver'])
                            {{ $plan['approver'] }} on {{ $plan['approved_at'] }}
                        @else
                            {{-- Said plainly. A draft that printed a blank
                                 approval line reads as an approved plan to
                                 anybody who is not looking for the difference. --}}
                            <span class="muted">Not approved. This is a draft and must not be relied on.</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td><strong>Effective from</strong></td>
                    <td>{{ $plan['effective_from'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td><strong>Next review</strong></td>
                    <td>
                        {{ $plan['next_review_date'] ?? 'No review date set' }}
                        @if (! empty($plan['is_stale']))
                            <span class="badge badge-critical">Overdue</span>
                        @endif
                    </td>
                </tr>
                @if ($plan['supersedes'])
                    <tr><td><strong>Supersedes</strong></td><td>{{ $plan['supersedes'] }}</td></tr>
                @endif
                <tr><td><strong>ISO clause</strong></td><td>{{ $plan['iso_clause_ref'] ?? '—' }}</td></tr>
            </tbody>
        </table>

        @if (! empty($plan['drifted_sections']))
            <div class="note" style="margin-top: 5mm;">
                <strong>This plan has drifted from its source data.</strong>
                The following sections are bound to information that has changed since this version was
                assembled: {{ implode(', ', $plan['drifted_sections']) }}. An approved version is not edited;
                the correction is a new version.
            </div>
        @endif
    </div>

    @foreach ($sections as $index => $section)
        <div class="section-block section-break">
            <a name="{{ $section['anchor'] }}"></a>
            <h1 class="section">{{ $index + 1 }}. {{ $section['title'] }}</h1>

            @if (! empty($section['body']))
                @foreach (preg_split('/\n{2,}/', trim($section['body'])) as $paragraph)
                    <p>{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
            @endif

            @if ($section['live'])
                @include('reports.pdf.bcms.bound-section', ['live' => $section['live'], 'section' => $section])
            @elseif (empty($section['body']))
                <div class="empty">This section has not been written.</div>
            @endif
        </div>
    @endforeach

@endsection
