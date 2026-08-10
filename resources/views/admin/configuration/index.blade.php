@extends('layouts.app')

@section('title', 'Configuration Bundles')
@section('page-section', 'Administration')
@section('page-title', 'Configuration Bundles')

@section('breadcrumbs')
    <a href="{{ route('admin.builder') }}" class="hover:text-[#1A365D]">Configuration Builder</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Bundles</span>
@endsection

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Configuration Bundles</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            A bundle is this organisation's configuration — object types, fields, relationship types, lifecycles,
            scoring profiles, measures, thresholds and workflow definitions — captured as a versioned artefact that
            can be carried to another environment. Importing always produces a diff first and writes nothing until
            you confirm it.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- The diff, when one has just been produced --}}
    @if ($diff)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-[#1A365D]">Dry run — nothing has been written</h3>

            @if ($diff['is_empty'])
                <p class="mt-2 text-sm text-gray-600">
                    No differences. This organisation's configuration already matches the bundle.
                </p>
            @else
                <div class="mt-3 flex flex-wrap gap-3 text-xs">
                    <span class="rounded bg-green-50 px-2 py-1 font-medium text-green-700">{{ $diff['totals']['added'] }} added</span>
                    <span class="rounded bg-amber-50 px-2 py-1 font-medium text-amber-700">{{ $diff['totals']['changed'] }} changed</span>
                    <span class="rounded bg-red-50 px-2 py-1 font-medium text-red-700">{{ $diff['totals']['removed'] }} removed</span>
                    <span class="rounded bg-purple-50 px-2 py-1 font-medium text-purple-700">{{ $diff['totals']['conflicting'] }} conflicting</span>
                </div>

                @if ($diff['totals']['conflicting'] > 0)
                    <div class="mt-3 rounded-lg border border-purple-300 bg-purple-50 p-3 text-xs text-purple-900">
                        <p class="font-semibold">Some items were changed both here and in the bundle since the last apply.</p>
                        <p class="mt-1">
                            Applying takes the bundle's version and discards the change made here. Review each one
                            below before deciding — this is the case where an import quietly destroys somebody's work.
                        </p>
                    </div>
                @endif

                @if ($diff['totals']['removed'] > 0)
                    <p class="mt-3 text-xs text-gray-500">
                        Removed items are <span class="font-medium">kept</span> unless you tick “remove configuration
                        the bundle does not contain”. A bundle missing something is usually an older or narrower
                        export, not an instruction to delete.
                    </p>
                @endif

                <div class="mt-4 space-y-4">
                    @foreach ($diff['sections'] as $section => $changes)
                        <div>
                            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ str_replace('_', ' ', $section) }}</h4>
                            <ul class="mt-1 space-y-0.5 font-mono text-[11px]">
                                @foreach ($changes['added'] as $entry)
                                    <li class="text-green-700">+ {{ $entry['key'] }}</li>
                                @endforeach
                                @foreach ($changes['changed'] as $entry)
                                    <li class="text-amber-700">
                                        ~ {{ $entry['key'] }}
                                        <span class="text-gray-400">({{ implode(', ', array_keys($entry['fields'])) }})</span>
                                    </li>
                                @endforeach
                                @foreach ($changes['removed'] as $entry)
                                    <li class="text-red-700">- {{ $entry['key'] }}</li>
                                @endforeach
                                @foreach ($changes['conflicting'] as $entry)
                                    <li class="text-purple-700">
                                        ! {{ $entry['key'] }}
                                        <span class="text-gray-400">({{ implode(', ', array_keys($entry['fields'])) }})</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>

                @if ($diffBundleId)
                    <form method="POST" action="{{ route('admin.configuration.apply', $diffBundleId) }}" class="mt-5 border-t border-gray-100 pt-4">
                        @csrf
                        <div class="space-y-2 text-xs text-gray-700">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="prune" value="1">
                                Also remove configuration the bundle does not contain
                            </label>
                            @if ($diff['totals']['conflicting'] > 0)
                                <label class="flex items-center gap-2 text-purple-800">
                                    <input type="checkbox" name="force" value="1">
                                    Take the bundle's version for the {{ $diff['totals']['conflicting'] }} conflicting item(s)
                                </label>
                            @endif
                            <label class="flex items-center gap-2 font-medium">
                                <input type="checkbox" name="confirm" value="1" required>
                                I have read this diff and want to apply it
                            </label>
                        </div>
                        <button type="submit" class="mt-3 rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
                            Apply this bundle
                        </button>
                        <p class="mt-2 text-[11px] text-gray-400">
                            A snapshot of the current configuration is taken first, so this can be rolled back.
                        </p>
                    </form>
                @else
                    <p class="mt-4 text-xs text-gray-500">
                        This diff came from an uploaded file. To apply it, export it as a bundle first or use
                        <span class="font-mono">php artisan config:import &lt;file&gt; --apply</span>.
                    </p>
                @endif
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Export --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-[#1A365D]">Export</h3>
            <p class="mt-1 text-xs text-gray-500">
                Capture the configuration as it stands. Re-exporting the same code creates a new version.
            </p>
            <form method="POST" action="{{ route('admin.configuration.export') }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-gray-700">Name</label>
                    <input type="text" name="name" required value="{{ old('name') }}"
                           class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">Code</label>
                    <input type="text" name="code" required value="{{ old('code', 'baseline') }}"
                           class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">Description</label>
                    <textarea name="description" rows="2" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">{{ old('description') }}</textarea>
                </div>
                <button type="submit" class="w-full rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
                    Export configuration
                </button>
            </form>
        </div>

        {{-- Dry run --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-[#1A365D]">Dry run an import</h3>
            <p class="mt-1 text-xs text-gray-500">
                Produces a diff. Writes nothing. This is the only way into an apply.
            </p>
            <form method="POST" action="{{ route('admin.configuration.diff') }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-gray-700">A stored bundle</label>
                    <select name="bundle_id" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                        <option value="">— none —</option>
                        @foreach ($bundles as $bundle)
                            <option value="{{ $bundle->id }}">{{ $bundle->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="text-center text-[11px] uppercase tracking-wide text-gray-400">or</div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">Upload a bundle file</label>
                    <input type="file" name="file" accept=".json,application/json"
                           class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-xs">
                </div>
                <button type="submit" class="w-full rounded-lg border border-[#1A365D] px-4 py-2 text-sm font-medium text-[#1A365D] hover:bg-gray-50">
                    Show me the diff
                </button>
            </form>
        </div>

        {{-- Bundles --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-[#1A365D]">Bundles</h3>
            <ul class="mt-3 divide-y divide-gray-100 text-sm">
                @forelse ($bundles as $bundle)
                    <li class="py-2">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-900">{{ $bundle->label() }}</p>
                                <p class="text-[11px] text-gray-400">
                                    {{ $bundle->exported_at?->format('d M Y H:i') }} ·
                                    {{ $bundle->source_environment }} ·
                                    {{ $bundle->totalRows() }} rows
                                </p>
                                @unless ($bundle->isIntact())
                                    <p class="text-[11px] font-medium text-red-600">checksum mismatch — modified since export</p>
                                @endunless
                            </div>
                            <a href="{{ route('admin.configuration.download', $bundle) }}"
                               class="shrink-0 text-xs text-[#1A365D] hover:underline">Download</a>
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-center text-xs text-gray-400">No bundles exported yet.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- History --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-5 py-3">
            <h3 class="text-sm font-semibold text-[#1A365D]">History</h3>
            <p class="text-xs text-gray-500">Every dry run, apply and rollback. Append-only.</p>
        </div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2">#</th>
                    <th class="px-4 py-2">When</th>
                    <th class="px-4 py-2">Who</th>
                    <th class="px-4 py-2">Mode</th>
                    <th class="px-4 py-2">Bundle</th>
                    <th class="px-4 py-2">Changes</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($applications as $application)
                    <tr class="{{ $application->outcome === 'failed' ? 'bg-red-50' : '' }}">
                        <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $application->id }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $application->applied_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $application->actor?->name ?? 'system' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-medium
                                {{ $application->mode === 'apply' ? 'bg-blue-50 text-blue-700' : ($application->mode === 'rollback' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ str_replace('_', ' ', $application->mode) }}
                            </span>
                            @if ($application->outcome !== 'ok')
                                <span class="ml-1 text-[10px] text-gray-500">{{ str_replace('_', ' ', $application->outcome) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $application->bundle?->label() ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $application->summary() }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($application->isRollbackable())
                                <form method="POST" action="{{ route('admin.configuration.rollback', $application) }}"
                                      onsubmit="return confirm('Restore the configuration to how it was before this apply?')">
                                    @csrf
                                    <button type="submit" class="text-xs text-amber-700 hover:underline">Roll back</button>
                                </form>
                            @elseif ($application->rolled_back_by_application_id)
                                <span class="text-[11px] text-gray-400">rolled back by #{{ $application->rolled_back_by_application_id }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-xs text-gray-400">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
