<div class="py-2 {{ $depth > 0 ? 'ml-' . ($depth * 6) . ' border-l border-gray-200 pl-4' : '' }}">
    <div class="flex items-center gap-2">
        @if($node->children->count())
            <span class="material-symbols-outlined text-gray-400 text-sm">subdirectory_arrow_right</span>
        @else
            <span class="w-4 h-4 rounded-full bg-blue-100 flex items-center justify-center"><span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span></span>
        @endif
        <span class="text-sm font-medium text-gray-800">{{ $node->name }}</span>
        @if($node->framework)<span class="badge bg-gray-100 text-gray-500 text-[10px]">{{ $node->framework }}</span>@endif
    </div>
    @if($node->description)<p class="text-xs text-gray-400 ml-6">{{ $node->description }}</p>@endif
    @foreach($node->children as $child)
        @include('risk.regulatory._taxonomy-node', ['node' => $child, 'depth' => $depth + 1])
    @endforeach
</div>
