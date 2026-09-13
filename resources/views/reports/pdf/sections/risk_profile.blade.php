@php
    // Standard 5x5 residual grid. The colour is a function of likelihood ×
    // impact only — it is not a separate judgement layered on top of the score.
    $cellColour = function (int $likelihood, int $impact): string {
        $score = $likelihood * $impact;
        return match (true) {
            $score >= 20 => '#f6d4d4',
            $score >= 12 => '#fbe0cb',
            $score >= 6  => '#fbf1cf',
            default      => '#dcefe2',
        };
    };
    $likelihoodLabels = [5 => 'Almost certain', 4 => 'Likely', 3 => 'Possible', 2 => 'Unlikely', 1 => 'Rare'];
    $impactLabels = [1 => 'Insignificant', 2 => 'Minor', 3 => 'Moderate', 4 => 'Major', 5 => 'Catastrophic'];
@endphp

<p class="muted" style="font-size: 8pt;">
    Count of active risks at each residual likelihood and impact pairing.
</p>

@if ($data['plotted'] === 0)
    <div class="empty">
        No active risk carries both a residual likelihood and a residual impact, so there is nothing to plot.
        @if ($data['unplotted'] > 0)
            {{ number_format($data['unplotted']) }} active risk(s) are awaiting a residual assessment.
        @endif
    </div>
@else
    <table class="data" style="text-align: center;">
        <thead>
            <tr>
                <th style="width: 30mm;">Likelihood \ Impact</th>
                @foreach ($impactLabels as $value => $label)
                    <th style="text-align: center;">{{ $value }}<br><span style="font-weight: normal; font-size: 6.5pt;">{{ $label }}</span></th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($data['grid'] as $likelihood => $row)
                <tr>
                    <td style="text-align: left; font-weight: bold; background: #f4f6f8;">
                        {{ $likelihood }} — {{ $likelihoodLabels[$likelihood] ?? '' }}
                    </td>
                    @foreach ($row as $impact => $count)
                        <td style="background: {{ $cellColour($likelihood, $impact) }}; font-weight: {{ $count > 0 ? 'bold' : 'normal' }};">
                            {{ $count > 0 ? $count : '—' }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="font-size: 8pt;">
        {{ number_format($data['plotted']) }} risk(s) plotted.
        @if ($data['unplotted'] > 0)
            <strong>{{ number_format($data['unplotted']) }} active risk(s) are not on this map</strong> because they
            have no residual likelihood or impact recorded. The map understates the register by that much.
        @endif
    </p>
@endif
