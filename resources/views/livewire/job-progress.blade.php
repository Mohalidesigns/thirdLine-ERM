{{--
    WP-07 — the progress bar for a queued job.

    wire:poll is conditional: it stops the moment the job ends, so a screen left
    open overnight is not still polling in the morning.
--}}
@if ($run)
    <div @if (! $run->isFinished()) wire:poll.2s @endif
         class="rounded-xl border border-gray-200 bg-white p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-900">{{ $run->label }}</p>
                <p class="text-[11px] text-gray-500">
                    @php
                        $badge = match ($run->statusColor()) {
                            'green' => 'bg-green-100 text-green-800',
                            'red' => 'bg-red-100 text-red-800',
                            'blue' => 'bg-blue-100 text-blue-800',
                            'amber' => 'bg-amber-100 text-amber-800',
                            default => 'bg-slate-100 text-slate-700',
                        };
                    @endphp
                    <span class="rounded-full px-2 py-0.5 font-medium {{ $badge }}">{{ ucfirst($run->status) }}</span>

                    @if ($run->total)
                        <span class="ml-1">{{ number_format($run->processed) }} of {{ number_format($run->total) }}</span>
                    @endif

                    @if ($seconds = $run->estimatedSecondsRemaining())
                        <span class="ml-1">· about {{ $seconds < 60 ? $seconds.'s' : ceil($seconds / 60).' min' }} left</span>
                    @endif

                    @if ($duration = $run->durationSeconds())
                        <span class="ml-1">· took {{ $duration }}s</span>
                    @endif
                </p>
            </div>

            @if ($showCancel && ! $run->isFinished())
                <button wire:click="cancel" type="button"
                        @disabled($run->isCancelRequested())
                        class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                    {{ $run->isCancelRequested() ? 'Stopping…' : 'Cancel' }}
                </button>
            @endif
        </div>

        @unless ($run->isFinished())
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full bg-[#1A365D] transition-all duration-500"
                     style="width: {{ max(2, $run->progress) }}%"></div>
            </div>
        @endunless

        @if ($run->message)
            <p class="mt-2 text-[11px] text-gray-500">{{ $run->message }}</p>
        @endif

        @if ($run->error)
            {{-- The failure is shown where the user is looking, not left in
                 failed_jobs where nobody has ever looked. --}}
            <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-[11px] text-red-700">{{ $run->error }}</p>
        @endif
    </div>
@endif
