@extends('reports.pdf.layout')

{{--
    The board pack body. Sections come from BoardPackAssembler in the order the
    organization configured; each renders its own partial and starts on a new
    page so the pack can be tabbed and circulated in parts.
--}}

@section('body')
    @foreach ($sections as $index => $section)
        <div class="section-block @if ($index > 0) section-break @endif">
            <a name="{{ $section['anchor'] }}"></a>
            <h1 class="section">{{ $index + 1 }}. {{ $section['title'] }}</h1>

            @includeIf('reports.pdf.sections.'.$section['key'], ['data' => $section['data']])
        </div>
    @endforeach
@endsection
