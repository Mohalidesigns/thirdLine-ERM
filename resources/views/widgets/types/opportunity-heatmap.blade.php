{{-- opportunity_heatmap shares the heatmap markup; the payload carries the
     reversed axis and blue palette. --}}
@include('widgets.types.heatmap', ['payload' => $payload])
