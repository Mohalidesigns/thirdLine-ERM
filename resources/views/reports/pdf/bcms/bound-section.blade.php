{{--
    One bound section's live data.

    ONE PARTIAL FOR ALL EIGHT SOURCES. The columns differ, so the header row is
    derived from the first row's keys rather than hard-coded per source — which
    means a new source added to PlanSectionSource prints without a matching
    blade file having to be remembered. The alternative is eight partials, seven
    of which are copies, and one that somebody forgets.

    A NULL CELL PRINTS AS A DASH, NEVER AS ZERO. An RTO nobody has agreed is not
    an RTO of zero hours, and on a recovery plan that difference is the whole
    document (development standard §5).
--}}

<p class="muted" style="font-size: 8pt; margin-top: 1mm;">
    Assembled from {{ $live['label'] }}.
    @if (! empty($section['last_verified_at']))
        Last verified {{ \Illuminate\Support\Carbon::parse($section['last_verified_at'])->format('d M Y H:i') }}.
    @else
        <strong>Never verified against its source.</strong>
    @endif
    @if (! empty($section['needs_review']))
        <span class="badge badge-high">Source has changed</span>
    @endif
    @if (! empty($section['is_overridden']))
        <span class="badge badge-medium">Edited by hand</span>
    @endif
</p>

@if (empty($live['rows']))
    <div class="empty">
        {{ $live['empty_reason'] ?? 'Nothing to show for this section.' }}
    </div>
@else
    @php
        // Columns that carry structure rather than text are not printed as
        // table cells: an array rendered by string coercion is "Array", which
        // has appeared in more than one bank's board pack.
        //
        // EVERY ROW IS SCANNED, NOT JUST THE FIRST. A nullable structured
        // column — `resource_requirements` on a strategy is the real case —
        // is null on one row and an array on the next, and a header derived
        // from row zero alone lets that column through and then fatals on the
        // row that has data in it.
        $structured = [];

        foreach ($live['rows'] as $scanned) {
            foreach ($scanned as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $structured[$key] = true;
                }
            }
        }

        // Primary keys are not printed. "Process id 4" means nothing to
        // somebody holding this document during an outage, and a column of
        // database ids on a board pack is the tell that the report was never
        // read by anybody who had to use it.
        $columns = array_values(array_filter(
            array_keys($live['rows'][0]),
            fn (string $column) => ! isset($structured[$column])
                && $column !== 'id'
                && ! str_ends_with($column, '_id'),
        ));
    @endphp

    <table class="data">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ ucfirst(str_replace('_', ' ', $column)) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($live['rows'] as $row)
                <tr>
                    @foreach ($columns as $column)
                        <td class="{{ is_numeric($row[$column] ?? null) ? 'num' : '' }}">
                            @php $value = $row[$column] ?? null; @endphp
                            @if ($value === null)
                                <span class="muted">—</span>
                            @elseif (is_bool($value))
                                {{ $value ? 'Yes' : 'No' }}
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @foreach ($live['notes'] ?? [] as $note)
        <div class="note">{{ $note }}</div>
    @endforeach
@endif
