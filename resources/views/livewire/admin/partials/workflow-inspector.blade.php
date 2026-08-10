{{--
    The inspector: whatever is selected on the canvas, editable in place.

    Fields appear per node type rather than all at once — an SLA on an end step
    or an assignee on a gateway are settings that cannot mean anything, and
    offering them is how a definition ends up carrying configuration the engine
    will never read.
--}}
<div class="rounded-xl border border-gray-200 bg-white p-4">
    @php
        $nodeIndex = collect($nodes)->search(fn ($n) => $n['code'] === $selectedNode);
    @endphp

    @if ($selectedNode !== null && $nodeIndex !== false)
        @php
            $node = $nodes[$nodeIndex];
            $type = \App\Enums\WorkflowNodeType::tryFrom($node['type'] ?? '');
        @endphp

        <div class="flex items-start justify-between">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Step</h3>
                <p class="text-sm font-semibold text-gray-900">{{ $type?->label() ?? $node['type'] }}</p>
            </div>
            @if ($type !== \App\Enums\WorkflowNodeType::Start)
                <button wire:click="deleteNode('{{ $node['code'] }}')" type="button"
                        class="rounded px-2 py-1 text-[11px] text-red-600 hover:bg-red-50">Delete</button>
            @endif
        </div>

        <div class="mt-3 space-y-3">
            <label class="block">
                <span class="text-[11px] font-medium text-gray-500">Label</span>
                <input type="text" wire:model.blur="nodes.{{ $nodeIndex }}.name"
                       class="mt-1 w-full rounded-lg border-gray-300 text-xs">
            </label>

            <label class="block">
                <span class="text-[11px] font-medium text-gray-500">Code</span>
                <input type="text" value="{{ $node['code'] }}"
                       wire:change="renameNode('{{ $node['code'] }}', $event.target.value)"
                       class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs">
                <span class="text-[10px] text-gray-400">Renaming rewires every connection automatically.</span>
                @error('nodeCode') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
            </label>

            @if ($type?->waitsForHuman())
                <div class="border-t border-gray-100 pt-3">
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">Assigned to</span>
                        <select wire:model.live="nodes.{{ $nodeIndex }}.assignee_rule"
                                class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                            <option value="role">Anyone with a role</option>
                            <option value="user">A named person</option>
                            <option value="owner">The record's owner</option>
                            <option value="delegate">The record's assigned reviewer</option>
                            <option value="manager">The owner of the parent unit</option>
                            <option value="relationship_traversal">Follow a relationship</option>
                            <option value="group">A shortlist of people</option>
                            <option value="expression">An expression</option>
                        </select>
                    </label>

                    @php $rule = $node['assignee_rule'] ?? 'role'; @endphp

                    @if ($rule === 'role' || $rule === 'group')
                        <label class="mt-2 block">
                            <span class="text-[11px] font-medium text-gray-500">Roles</span>
                            <select multiple size="4" wire:model="nodes.{{ $nodeIndex }}.assignee_config.roles"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                                @foreach ($roles as $role)
                                    <option value="{{ $role }}">{{ $role }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    @if ($rule === 'user')
                        <label class="mt-2 block">
                            <span class="text-[11px] font-medium text-gray-500">Person</span>
                            <select wire:model="nodes.{{ $nodeIndex }}.assignee_config.user_id"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                                <option value="">Choose…</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    @if ($rule === 'relationship_traversal')
                        <label class="mt-2 block">
                            <span class="text-[11px] font-medium text-gray-500">Relationship code</span>
                            <input type="text" wire:model.blur="nodes.{{ $nodeIndex }}.assignee_config.relationship"
                                   class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs" placeholder="assures">
                        </label>
                    @endif

                    @if ($rule === 'expression')
                        <label class="mt-2 block">
                            <span class="text-[11px] font-medium text-gray-500">Expression</span>
                            <input type="text" wire:model.blur="nodes.{{ $nodeIndex }}.assignee_config.expression"
                                   class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs"
                                   placeholder="get(subject, 'priority') == 'critical' ? 'chief-risk-officer' : 'risk-manager'">
                        </label>
                    @endif

                    @if (in_array($rule, ['owner', 'delegate', 'manager', 'relationship_traversal'], true))
                        <label class="mt-2 block">
                            <span class="text-[11px] font-medium text-gray-500">Fallback roles</span>
                            <select multiple size="3" wire:model="nodes.{{ $nodeIndex }}.assignee_config.fallback_roles"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                                @foreach ($roles as $role)
                                    <option value="{{ $role }}">{{ $role }}</option>
                                @endforeach
                            </select>
                            <span class="text-[10px] text-gray-400">
                                Used when the record has no owner set, or the owner has left.
                            </span>
                        </label>
                    @endif
                </div>

                <div class="border-t border-gray-100 pt-3">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="text-[11px] font-medium text-gray-500">Due within (hours)</span>
                            <input type="number" min="0" step="1" wire:model.blur="nodes.{{ $nodeIndex }}.sla_hours"
                                   class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-medium text-gray-500">When it runs out</span>
                            <select wire:model="nodes.{{ $nodeIndex }}.on_timeout"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                                <option value="escalate">Escalate</option>
                                <option value="notify">Remind only</option>
                                <option value="auto_approve">Approve automatically</option>
                                <option value="auto_reject">Reject automatically</option>
                            </select>
                        </label>
                    </div>

                    @if (($node['on_timeout'] ?? '') === 'auto_approve')
                        <p class="mt-2 rounded bg-amber-50 px-2 py-1 text-[10px] text-amber-800">
                            An approval nobody granted still counts as an approval on the record. Use this only
                            where the decision is genuinely low-stakes.
                        </p>
                    @endif

                    <label class="mt-2 flex items-center gap-2 text-[11px] text-gray-600">
                        <input type="checkbox" wire:model="nodes.{{ $nodeIndex }}.allow_delegate"
                               class="rounded border-gray-300 text-[#1A365D]"> Can be delegated
                    </label>
                    <label class="mt-1 flex items-center gap-2 text-[11px] text-gray-600">
                        <input type="checkbox" wire:model="nodes.{{ $nodeIndex }}.allow_return"
                               class="rounded border-gray-300 text-[#1A365D]"> Can be returned for rework
                    </label>

                    <label class="mt-2 block">
                        <span class="text-[11px] font-medium text-gray-500">Instructions to the assignee</span>
                        <textarea rows="2" wire:model.blur="nodes.{{ $nodeIndex }}.instructions"
                                  class="mt-1 w-full rounded-lg border-gray-300 text-xs"></textarea>
                    </label>
                </div>
            @endif

            @if ($type === \App\Enums\WorkflowNodeType::End)
                <label class="block border-t border-gray-100 pt-3">
                    <span class="text-[11px] font-medium text-gray-500">Outcome</span>
                    <select wire:model="nodes.{{ $nodeIndex }}.outcome"
                            class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <span class="text-[10px] text-gray-400">
                        This is what the record is told when the process finishes here.
                    </span>
                </label>
            @endif

            @if ($type === \App\Enums\WorkflowNodeType::Timer)
                <label class="block border-t border-gray-100 pt-3">
                    <span class="text-[11px] font-medium text-gray-500">Wait (hours)</span>
                    <input type="number" min="0" wire:model.blur="nodes.{{ $nodeIndex }}.wait_hours"
                           class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                </label>
            @endif

            @if ($type === \App\Enums\WorkflowNodeType::Notification)
                <label class="block border-t border-gray-100 pt-3">
                    <span class="text-[11px] font-medium text-gray-500">Message</span>
                    <textarea rows="2" wire:model.blur="nodes.{{ $nodeIndex }}.message"
                              class="mt-1 w-full rounded-lg border-gray-300 text-xs"></textarea>
                </label>
            @endif

            @if ($type === \App\Enums\WorkflowNodeType::Join)
                <label class="block border-t border-gray-100 pt-3">
                    <span class="text-[11px] font-medium text-gray-500">Branches to wait for</span>
                    <input type="number" min="2" wire:model.blur="nodes.{{ $nodeIndex }}.join_count"
                           class="mt-1 w-full rounded-lg border-gray-300 text-xs"
                           placeholder="{{ collect($edges)->where('to', $node['code'])->count() }}">
                    <span class="text-[10px] text-gray-400">
                        Defaults to the number of incoming connections.
                    </span>
                </label>
            @endif
        </div>

    @elseif ($selectedEdge !== null && isset($edges[$selectedEdge]))
        @php $edge = $edges[$selectedEdge]; @endphp

        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Connection</h3>
        <p class="mt-1 font-mono text-xs text-gray-700">{{ $edge['from'] }} → {{ $edge['to'] }}</p>

        <label class="mt-3 block">
            <span class="text-[11px] font-medium text-gray-500">Label</span>
            <input type="text" wire:model.blur="edges.{{ $selectedEdge }}.label"
                   class="mt-1 w-full rounded-lg border-gray-300 text-xs" placeholder="Approved">
        </label>

        <label class="mt-3 block">
            <span class="text-[11px] font-medium text-gray-500">Condition</span>
            <textarea rows="3" wire:model.blur="edges.{{ $selectedEdge }}.when"
                      class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs"
                      placeholder="outcome == 'approve'"></textarea>
        </label>

        <div class="mt-2 rounded-lg bg-gray-50 p-2 text-[10px] leading-relaxed text-gray-600">
            <p class="font-semibold text-gray-700">Available to a condition</p>
            <p><code>outcome</code> — the decision just taken</p>
            <p><code>get(subject, 'field')</code> — a value from the record, as at the start</p>
            <p><code>get(context, 'key')</code> — anything the process has collected</p>
            <p><code>escalated</code> — whether this instance has ever breached an SLA</p>
            <p class="mt-1 text-gray-500">Leave it empty for a default branch. A condition that cannot be
                evaluated is treated as false, so the branch is not taken.</p>
        </div>

        <button wire:click="deleteEdge({{ $selectedEdge }})" type="button"
                class="mt-3 w-full rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
            Remove connection
        </button>

    @else
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Escalation rules</h3>
        <p class="mt-1 text-[11px] text-gray-500">
            Applied to any step that does not set its own timeout behaviour. Longest overdue period wins.
        </p>

        @foreach ($escalationRules as $index => $rule)
            <div class="mt-2 rounded-lg border border-gray-200 p-2">
                <div class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="text-[10px] text-gray-500">After (hours overdue)</span>
                        <input type="number" min="0" wire:model.blur="escalationRules.{{ $index }}.after_hours"
                               class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                    </label>
                    <label class="block">
                        <span class="text-[10px] text-gray-500">Then</span>
                        <select wire:model="escalationRules.{{ $index }}.action"
                                class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                            <option value="escalate">Escalate</option>
                            <option value="notify">Remind</option>
                            <option value="auto_approve">Approve automatically</option>
                            <option value="auto_reject">Reject automatically</option>
                        </select>
                    </label>
                </div>
                <label class="mt-2 block">
                    <span class="text-[10px] text-gray-500">Escalate to roles</span>
                    <select multiple size="3" wire:model="escalationRules.{{ $index }}.escalate_to.roles"
                            class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                        @foreach ($roles as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                </label>
                <button wire:click="removeEscalationRule({{ $index }})" type="button"
                        class="mt-1 text-[11px] text-red-600 hover:underline">Remove</button>
            </div>
        @endforeach

        <button wire:click="addEscalationRule" type="button"
                class="mt-3 w-full rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
            Add an escalation rule
        </button>

        <p class="mt-4 border-t border-gray-100 pt-3 text-[11px] text-gray-500">
            Select a step or a connection on the canvas to configure it.
        </p>
    @endif
</div>
