{{--
    One gap, written so the recipient can act on it without another email:
    what is required, who requires it, what the contract says today, and the
    words to add.
--}}
<div class="note" style="margin-bottom: 10px;">
    <strong>{{ $row['code'] }} — {{ $row['title'] }}</strong>
    @if ($blocking)
        <span class="badge badge-critical">Condition of activation</span>
    @endif
    <br>

    @if ($row['citation'])
        <em>{{ $row['citation'] }}@if ($row['regulatory_source']) — {{ $row['regulatory_source'] }}@endif</em><br>
    @endif

    <strong>Status:</strong>
    @if ($row['presence'] === 'partial')
        The contract addresses this but stops short of the obligation.
    @elseif ($row['waived'])
        Absent, and waived until the waiver expires.
    @elseif ($row['reviewer_status'] === 'pending' && $row['presence'] === 'present')
        Detected in the contract but not yet reviewed, so it is not yet relied on.
    @else
        Not present in the contract.
    @endif
    @if ($row['determined_by'])
        (Determined against {{ $row['determined_by'] }}.)
    @endif
    <br>

    @if ($row['located_text'])
        <strong>What the contract says:</strong>
        <em>&ldquo;{{ \Illuminate\Support\Str::limit($row['located_text'], 400) }}&rdquo;</em>
        @if ($row['page_reference']) ({{ $row['page_reference'] }})@endif
        <br>
    @endif

    @if ($row['guidance'])
        <strong>What is required:</strong> {{ $row['guidance'] }}<br>
    @endif

    @if ($row['model_text'])
        <strong>Suggested wording:</strong><br>
        <em>{{ $row['model_text'] }}</em>
    @else
        <strong>Suggested wording:</strong> none is held for this clause. Contract language is drafted by your
        legal function; model text added to the clause library appears here on the next report.
    @endif
</div>
