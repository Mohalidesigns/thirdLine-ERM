{{--
    WP-06 TASK 3 — the workflow designer.

    Three panes: a palette, the canvas, and an inspector for whatever is
    selected. The canvas draws edges as SVG paths behind the node cards, so a
    process reads as a diagram rather than as a list of "next step" dropdowns —
    which is the format in which nobody ever notices the branch that goes
    nowhere.

    Positions are dragged with Alpine and pushed to the component on drop, so a
    layout survives a reload. Validation runs continuously and is shown as a
    live checklist; publish refuses while anything in it is outstanding.
--}}
<div class="space-y-4" x-data="workflowCanvas()">
    @if (session('designer-message'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('designer-message') }}
        </div>
    @endif
    @if (session('designer-error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ session('designer-error') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <label class="block">
                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Name</span>
                <input type="text" wire:model.blur="name"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]"
                       placeholder="Loss event approval">
                @error('name') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Code</span>
                <input type="text" wire:model.blur="code"
                       class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm focus:border-[#1A365D] focus:ring-[#1A365D]"
                       placeholder="loss_event_approval">
                @error('code') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Runs over</span>
                <select wire:model.live="entityType"
                        class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]">
                    <option value="">Choose a record type…</option>
                    @foreach ($subjectTypes as $type)
                        <option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                    @endforeach
                </select>
                @error('entityType') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Starts</span>
                <select wire:model="trigger"
                        class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]">
                    <option value="manual">When somebody submits it</option>
                    <option value="on_create">When the record is created</option>
                    <option value="on_transition">On a lifecycle transition</option>
                    <option value="on_schedule">On a schedule</option>
                    <option value="on_event">On a domain event</option>
                </select>
            </label>
        </div>

        <label class="mt-3 block">
            <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Description</span>
            <textarea wire:model.blur="description" rows="2"
                      class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]"
                      placeholder="What this process decides, and who decides it."></textarea>
        </label>

        @if ($entityType === 'graph_object')
            <label class="mt-3 block max-w-sm">
                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Object type</span>
                <select wire:model="objectTypeId"
                        class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]">
                    <option value="">Any type</option>
                    @foreach ($objectTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
            <p class="text-[11px] text-gray-500">
                @if ($definition)
                    Version {{ $definition->version }} ·
                    <span class="{{ $definition->is_published ? 'text-green-700' : 'text-amber-700' }}">
                        {{ $definition->is_published ? 'published' : 'draft' }}
                    </span>
                    @if ($dirty) · <span class="text-amber-700">unsaved changes</span> @endif
                @else
                    New draft
                @endif
            </p>
            <div class="flex gap-2">
                <button wire:click="save" type="button"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Save draft
                </button>
                <button wire:click="publish" type="button"
                        @disabled(count($liveErrors) > 0)
                        class="rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f] disabled:cursor-not-allowed disabled:opacity-40">
                    Publish
                </button>
            </div>
        </div>
    </div>

    {{-- Validation checklist --}}
    <div class="rounded-xl border {{ count($liveErrors) ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50' }} p-4">
        @if (count($liveErrors))
            <p class="text-xs font-semibold text-amber-900">
                {{ count($liveErrors) }} {{ Str::plural('thing', count($liveErrors)) }} to fix before this can be published
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-amber-800">
                @foreach ($liveErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @else
            <p class="text-xs font-semibold text-green-900">
                Every step is reachable, every branch reaches an end, and every decision has somebody to make it.
            </p>
        @endif

        @if ($publishErrors)
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-red-700">
                @foreach ($publishErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
        {{-- Palette --}}
        <div class="space-y-3 xl:col-span-1">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Add a step</h3>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    @foreach ($nodeTypes as $type)
                        <button wire:click="addNode('{{ $type->value }}')" type="button"
                                class="rounded-lg border border-gray-200 px-2 py-2 text-left text-[11px] font-medium text-gray-700 hover:border-[#1A365D] hover:bg-gray-50">
                            {{ $type->label() }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Connections --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Connections</h3>
                <p class="mt-1 text-[11px] text-gray-500">
                    A decision step takes the first connection whose condition holds, so order matters: put the
                    default — the one with no condition — last.
                </p>

                <div class="mt-3 flex gap-2">
                    <select wire:model="edgeFrom" class="w-full rounded-lg border-gray-300 text-xs">
                        <option value="">From…</option>
                        @foreach ($nodes as $node)
                            <option value="{{ $node['code'] }}">{{ $node['name'] ?? $node['code'] }}</option>
                        @endforeach
                    </select>
                    <select wire:model="edgeTo" class="w-full rounded-lg border-gray-300 text-xs">
                        <option value="">To…</option>
                        @foreach ($nodes as $node)
                            <option value="{{ $node['code'] }}">{{ $node['name'] ?? $node['code'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button wire:click="addEdge" type="button"
                        class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    Connect
                </button>
                @error('edge') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror

                <ul class="mt-3 space-y-1">
                    @foreach ($edges as $index => $edge)
                        <li class="flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] {{ $selectedEdge === $index ? 'bg-blue-50 ring-1 ring-blue-200' : 'hover:bg-gray-50' }}">
                            <button wire:click="selectEdge({{ $index }})" type="button" class="flex-1 text-left">
                                <span class="font-mono">{{ $edge['from'] }}</span>
                                <span class="text-gray-400">→</span>
                                <span class="font-mono">{{ $edge['to'] }}</span>
                                @if (! empty($edge['when']))
                                    <span class="ml-1 rounded bg-gray-100 px-1 text-[10px] text-gray-600">if</span>
                                @else
                                    <span class="ml-1 rounded bg-gray-100 px-1 text-[10px] text-gray-500">default</span>
                                @endif
                            </button>
                            <button wire:click="moveEdge({{ $index }}, -1)" type="button" class="px-1 text-gray-400 hover:text-gray-700">↑</button>
                            <button wire:click="moveEdge({{ $index }}, 1)" type="button" class="px-1 text-gray-400 hover:text-gray-700">↓</button>
                            <button wire:click="deleteEdge({{ $index }})" type="button" class="px-1 text-red-500 hover:text-red-700">×</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- Canvas --}}
        <div class="xl:col-span-2">
            <div class="relative h-[560px] overflow-auto rounded-xl border border-gray-200 bg-[linear-gradient(to_right,#f3f4f6_1px,transparent_1px),linear-gradient(to_bottom,#f3f4f6_1px,transparent_1px)] bg-[size:20px_20px]"
                 x-ref="canvas" @mousemove="drag($event)" @mouseup="drop()" @mouseleave="drop()">
                <svg class="pointer-events-none absolute inset-0 h-[1400px] w-[1600px]">
                    <defs>
                        <marker id="wf-arrow" viewBox="0 0 10 10" refX="9" refY="5"
                                markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 0 L 10 5 L 0 10 z" fill="#94a3b8" />
                        </marker>
                    </defs>
                    @php
                        $positions = collect($nodes)->keyBy('code');
                    @endphp
                    @foreach ($edges as $index => $edge)
                        @php
                            $from = $positions[$edge['from']] ?? null;
                            $to = $positions[$edge['to']] ?? null;
                        @endphp
                        @if ($from && $to)
                            @php
                                $x1 = ($from['x'] ?? 0) + 176; $y1 = ($from['y'] ?? 0) + 34;
                                $x2 = ($to['x'] ?? 0);        $y2 = ($to['y'] ?? 0) + 34;
                                $mid = ($x1 + $x2) / 2;
                            @endphp
                            <path d="M {{ $x1 }} {{ $y1 }} C {{ $mid }} {{ $y1 }}, {{ $mid }} {{ $y2 }}, {{ $x2 }} {{ $y2 }}"
                                  fill="none" stroke="{{ $selectedEdge === $index ? '#1A365D' : '#94a3b8' }}"
                                  stroke-width="{{ $selectedEdge === $index ? 2.5 : 1.5 }}"
                                  stroke-dasharray="{{ empty($edge['when']) ? '0' : '5 3' }}"
                                  marker-end="url(#wf-arrow)" />
                            @if (! empty($edge['label']))
                                <text x="{{ $mid }}" y="{{ (($y1 + $y2) / 2) - 6 }}" text-anchor="middle"
                                      class="fill-gray-500" style="font-size:10px">{{ $edge['label'] }}</text>
                            @endif
                        @endif
                    @endforeach
                </svg>

                @foreach ($nodes as $node)
                    @php
                        $palette = match ($node['type'] ?? '') {
                            'start' => 'border-green-300 bg-green-50',
                            'end' => ($node['outcome'] ?? '') === 'rejected' ? 'border-red-300 bg-red-50' : 'border-emerald-300 bg-emerald-50',
                            'approval', 'task' => 'border-blue-300 bg-blue-50',
                            'exclusive_gateway', 'parallel_gateway', 'join' => 'border-amber-300 bg-amber-50',
                            default => 'border-gray-300 bg-white',
                        };
                    @endphp
                    <div class="absolute w-44 cursor-move select-none rounded-lg border-2 p-2 shadow-sm {{ $palette }} {{ $selectedNode === $node['code'] ? 'ring-2 ring-[#1A365D]' : '' }}"
                         style="left: {{ $node['x'] ?? 40 }}px; top: {{ $node['y'] ?? 40 }}px"
                         wire:key="node-{{ $node['code'] }}"
                         @mousedown="grab($event, '{{ $node['code'] }}', {{ $node['x'] ?? 40 }}, {{ $node['y'] ?? 40 }})"
                         wire:click="selectNode('{{ $node['code'] }}')">
                        <p class="truncate text-xs font-semibold text-gray-900">{{ $node['name'] ?? $node['code'] }}</p>
                        <p class="truncate font-mono text-[10px] text-gray-500">{{ $node['code'] }}</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <span class="rounded bg-white/70 px-1 text-[9px] font-medium text-gray-600">
                                {{ \App\Enums\WorkflowNodeType::tryFrom($node['type'] ?? '')?->label() ?? $node['type'] ?? '?' }}
                            </span>
                            @if (! empty($node['sla_hours']))
                                <span class="rounded bg-white/70 px-1 text-[9px] text-gray-600">{{ $node['sla_hours'] }}h</span>
                            @endif
                            @if (! empty($node['assignee_rule']))
                                <span class="rounded bg-white/70 px-1 text-[9px] text-gray-600">{{ $node['assignee_rule'] }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-[11px] text-gray-500">
                Drag a step to move it. A dashed connection carries a condition; a solid one is unconditional.
            </p>
        </div>

        {{-- Inspector --}}
        <div class="xl:col-span-1">
            @include('livewire.admin.partials.workflow-inspector')
        </div>
    </div>

    @script
    <script>
        Alpine.data('workflowCanvas', () => ({
            dragging: null,
            init() {},
            grab(event, code, x, y) {
                // Only a primary-button press on the card body starts a drag;
                // anything else stays a click so selection still works.
                if (event.button !== 0) return;
                const rect = this.$refs.canvas.getBoundingClientRect();
                this.dragging = {
                    code,
                    offsetX: event.clientX - rect.left - x + this.$refs.canvas.scrollLeft,
                    offsetY: event.clientY - rect.top - y + this.$refs.canvas.scrollTop,
                    moved: false,
                    el: event.currentTarget,
                };
            },
            drag(event) {
                if (!this.dragging) return;
                const rect = this.$refs.canvas.getBoundingClientRect();
                const x = event.clientX - rect.left + this.$refs.canvas.scrollLeft - this.dragging.offsetX;
                const y = event.clientY - rect.top + this.$refs.canvas.scrollTop - this.dragging.offsetY;
                this.dragging.moved = true;
                this.dragging.x = Math.max(0, x);
                this.dragging.y = Math.max(0, y);
                this.dragging.el.style.left = this.dragging.x + 'px';
                this.dragging.el.style.top = this.dragging.y + 'px';
            },
            drop() {
                if (!this.dragging) return;
                // A press without movement is a selection, not a move — pushing
                // an unchanged position on every click would mark the draft
                // dirty for doing nothing.
                if (this.dragging.moved) {
                    this.$wire.moveNode(this.dragging.code,
                        Math.round(this.dragging.x), Math.round(this.dragging.y));
                }
                this.dragging = null;
            },
        }));
    </script>
    @endscript
</div>
