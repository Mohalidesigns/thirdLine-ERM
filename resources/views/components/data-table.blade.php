@props(['id' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl border border-gray-200 overflow-hidden']) }}>
    <div class="overflow-x-auto">
        <table @if($id) id="{{ $id }}" @endif class="data-table">
            @if (isset($head))
                <thead>
                    <tr>
                        {{ $head }}
                    </tr>
                </thead>
            @endif
            <tbody>
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
