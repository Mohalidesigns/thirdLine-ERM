@extends('layouts.app')

@section('title', 'Webhooks')
@section('page-section', 'Administration')
@section('page-title', 'Webhooks')

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Webhooks</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Tell another system when something happens here. Every payload is signed with the subscription's own
            secret, so a receiver can tell a genuine delivery from anyone who learned the URL.
        </p>
    </div>

    @foreach (['success' => 'green', 'error' => 'red'] as $key => $tone)
        @if (session($key))
            <div class="mb-4 rounded-lg border border-{{ $tone }}-200 bg-{{ $tone }}-50 px-4 py-3 text-sm text-{{ $tone }}-800">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    @if (session('revealed_secret'))
        <div class="mb-6 rounded-xl border-2 border-amber-300 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">
                Signing secret for “{{ session('revealed_secret_for') }}”
            </p>
            <p class="mt-1 text-xs text-amber-800">
                This is the only time it is shown. Configure your receiver with it now — only its encrypted form is kept.
            </p>
            <code class="mt-2 block break-all rounded-lg bg-white px-3 py-2 font-mono text-xs">{{ session('revealed_secret') }}</code>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            @forelse ($subscriptions as $subscription)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ $subscription->name }}
                                @if ($subscription->disabled_at)
                                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700">disabled</span>
                                @elseif (! $subscription->is_active)
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">paused</span>
                                @endif
                            </h3>
                            <p class="truncate font-mono text-[11px] text-gray-500">{{ $subscription->url }}</p>
                            <p class="mt-1 text-[11px] text-gray-500">
                                {{ implode(', ', (array) $subscription->events) }}
                            </p>
                            @if ($subscription->disabled_reason)
                                <p class="mt-1 text-[11px] text-red-600">{{ $subscription->disabled_reason }}</p>
                            @endif
                        </div>
                        <div class="shrink-0 text-right text-[11px] text-gray-500">
                            <p class="text-green-700">{{ $subscription->delivered_count }} delivered</p>
                            <p class="{{ $subscription->failed_count > 0 ? 'text-red-600' : '' }}">{{ $subscription->failed_count }} failed</p>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                        <a href="{{ route('admin.webhooks.deliveries', $subscription) }}"
                           class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            Delivery log
                        </a>
                        @can('webhook.manage')
                            <form method="POST" action="{{ route('admin.webhooks.test', $subscription) }}" class="inline">
                                @csrf
                                <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    Send test event
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.webhooks.rotate-secret', $subscription) }}" class="inline">
                                @csrf
                                <button class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50">
                                    Rotate secret
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.webhooks.destroy', $subscription) }}" class="inline">
                                @csrf @method('DELETE')
                                <button class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                                    Remove
                                </button>
                            </form>
                        @endcan
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    No webhooks yet.
                </div>
            @endforelse
        </div>

        @can('webhook.manage')
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">New webhook</h3>

                <form method="POST" action="{{ route('admin.webhooks.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">Name</span>
                        <input type="text" name="name" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">URL</span>
                        <input type="url" name="url" required placeholder="https://…"
                               class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs">
                        <span class="text-[10px] text-gray-400">
                            Must be https and reachable from the public internet.
                        </span>
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">Events</span>
                        <select name="events[]" multiple size="12" required class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                            @foreach ($events as $type => $names)
                                <optgroup label="{{ str_replace('_', ' ', $type) }}">
                                    @foreach ($names as $name)
                                        <option value="{{ $name }}">{{ $name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit"
                            class="w-full rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
                        Create
                    </button>
                </form>
            </div>
        @endcan
    </div>
@endsection
