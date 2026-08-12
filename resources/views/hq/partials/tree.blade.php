{{-- Recursive org-tree navigator. $nodes is the nested array HqController
     builds; $currentId highlights the page's node. --}}
<ul class="space-y-0.5 {{ ($nodes[0]['depth'] ?? 0) > 0 ? 'ml-3 border-l border-gray-100 pl-2' : '' }}">
    @foreach($nodes as $node)
        <li x-data="{ open: {{ $node['depth'] < 2 || $node['id'] === $currentId ? 'true' : 'false' }} }">
            <div class="group flex items-center gap-1">
                @if($node['children'] !== [])
                    <button type="button" @click="open = !open" class="shrink-0 text-gray-300 hover:text-gray-500">
                        <span class="material-symbols-outlined text-[16px] leading-none" x-text="open ? 'expand_more' : 'chevron_right'"></span>
                    </button>
                @else
                    <span class="w-4 shrink-0"></span>
                @endif
                <a href="{{ route('hq.show', $node['id']) }}"
                   class="flex min-w-0 flex-1 items-center gap-1.5 rounded px-1.5 py-1 text-xs
                          {{ $node['id'] === $currentId ? 'bg-[--color-primary] font-semibold text-white' : 'text-gray-700 hover:bg-gray-100' }}"
                   title="{{ $node['type'] }}">
                    @if($node['icon'])
                        <span class="material-symbols-outlined shrink-0 text-[15px] leading-none opacity-70">{{ $node['icon'] }}</span>
                    @endif
                    <span class="truncate">{{ $node['name'] }}</span>
                </a>
            </div>
            @if($node['children'] !== [])
                <div x-show="open" x-cloak>
                    @include('hq.partials.tree', ['nodes' => $node['children'], 'currentId' => $currentId])
                </div>
            @endif
        </li>
    @endforeach
</ul>
