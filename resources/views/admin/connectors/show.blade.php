@extends('layouts.app')

@section('title', $connector->name)
@section('page-section', 'Administration')
@section('page-title', $connector->name)

@section('breadcrumbs')
    <a href="{{ route('admin.connectors.index') }}" class="hover:text-[#1A365D]">Connectors</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $connector->name }}</span>
@endsection

@section('content')
    @foreach (['success' => 'green', 'error' => 'red'] as $key => $tone)
        @if (session($key))
            <div class="mb-4 rounded-lg border border-{{ $tone }}-200 bg-{{ $tone }}-50 px-4 py-3 text-sm text-{{ $tone }}-800">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $connector->name }}</h2>
                        <p class="text-xs text-gray-500">{{ $driver['description'] ?? $connector->type }}</p>
                        <p class="mt-1 text-[11px] text-gray-500">{{ $connector->healthLabel() }}</p>
                    </div>
                    @can('connector.run')
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('admin.connectors.run', $connector) }}">
                                @csrf
                                <input type="hidden" name="dry_run" value="1">
                                <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    Dry run
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.connectors.run', $connector) }}">
                                @csrf
                                <button class="rounded-lg bg-[#1A365D] px-3 py-1.5 text-xs font-medium text-white hover:bg-[#12263f]">
                                    Run now
                                </button>
                            </form>
                        </div>
                    @endcan
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Run history</h3>
                    <p class="text-[11px] text-gray-500">
                        “Read 412, wrote 0” means the field mapping matched nothing — which a green tick would hide.
                    </p>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">When</th>
                            <th class="px-4 py-2 text-left font-medium">Trigger</th>
                            <th class="px-4 py-2 text-left font-medium">Result</th>
                            <th class="px-4 py-2 text-left font-medium">Took</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($runs as $run)
                            <tr>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ $run->created_at->format('d M H:i') }}</td>
                                <td class="px-4 py-3 text-xs">
                                    {{ $run->trigger }}{{ $run->triggerer ? ' — '.$run->triggerer->name : '' }}
                                </td>
                                <td class="px-4 py-3 text-xs {{ $run->status === 'failed' ? 'text-red-600' : 'text-gray-700' }}">
                                    {{ $run->summary() }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ $run->durationSeconds() !== null ? $run->durationSeconds().'s' : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center text-sm text-gray-500">Never run.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @can('connector.manage')
            <div class="space-y-4">
                <form method="POST" action="{{ route('admin.connectors.update', $connector) }}"
                      class="rounded-xl border border-gray-200 bg-white p-4">
                    @csrf @method('PUT')
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Settings</h3>

                    <label class="mt-3 block">
                        <span class="text-[11px] font-medium text-gray-500">Name</span>
                        <input type="text" name="name" value="{{ $connector->name }}" required
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>

                    @foreach ($driver['config'] ?? [] as $key => $field)
                        <label class="mt-3 block">
                            <span class="text-[11px] font-medium text-gray-500">{{ $field['label'] }}</span>
                            @if (($field['type'] ?? 'text') === 'select')
                                <select name="config[{{ $key }}]" class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                                    <option value="">—</option>
                                    @foreach ($field['options'] ?? [] as $value => $label)
                                        <option value="{{ $value }}" @selected($connector->config($key) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @elseif (($field['type'] ?? '') === 'boolean')
                                <input type="checkbox" name="config[{{ $key }}]" value="1"
                                       @checked($connector->config($key)) class="mt-1 rounded border-gray-300">
                            @else
                                <input type="text" name="config[{{ $key }}]" value="{{ $connector->config($key) }}"
                                       class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs">
                            @endif
                            @if (! empty($field['help']))
                                <span class="text-[10px] text-gray-400">{{ $field['help'] }}</span>
                            @endif
                        </label>
                    @endforeach

                    <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-500">Credentials</h3>
                    <p class="text-[10px] text-gray-400">Left blank to keep what is stored. They are never sent to this page.</p>

                    @foreach ($driver['credentials'] ?? [] as $key => $field)
                        <label class="mt-2 block">
                            <span class="text-[11px] font-medium text-gray-500">{{ $field['label'] }}</span>
                            @if (($field['type'] ?? '') === 'select')
                                <select name="credentials[{{ $key }}]" class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                                    @foreach ($field['options'] ?? [] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="password" name="credentials[{{ $key }}]" autocomplete="new-password"
                                       class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                            @endif
                        </label>
                    @endforeach

                    <label class="mt-4 flex items-center gap-2 text-[11px] text-gray-600">
                        <input type="checkbox" name="is_active" value="1" @checked($connector->is_active)
                               class="rounded border-gray-300 text-[#1A365D]"> Active
                    </label>

                    <button type="submit" class="mt-4 w-full rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
                        Save
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.connectors.test', $connector) }}">
                    @csrf
                    <button class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Test connection
                    </button>
                </form>
            </div>
        @endcan
    </div>
@endsection
