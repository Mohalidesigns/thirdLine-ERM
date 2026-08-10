@extends('layouts.app')

@section('title', 'API tokens')
@section('page-section', 'Administration')
@section('page-title', 'API tokens')

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">API tokens</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            A token can never do more than the person it belongs to. Scopes narrow it further — they never widen it,
            so issuing yourself one is safe by construction.
        </p>
    </div>

    @foreach (['success' => 'green', 'error' => 'red'] as $key => $tone)
        @if (session($key))
            <div class="mb-4 rounded-lg border border-{{ $tone }}-200 bg-{{ $tone }}-50 px-4 py-3 text-sm text-{{ $tone }}-800">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    @if (session('revealed_token'))
        <div class="mb-6 rounded-xl border-2 border-amber-300 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">Token for “{{ session('revealed_token_for') }}”</p>
            <p class="mt-1 text-xs text-amber-800">
                Copy it now. Only its hash is stored, so a lost token is reissued rather than recovered.
            </p>
            <code class="mt-2 block break-all rounded-lg bg-white px-3 py-2 font-mono text-xs">{{ session('revealed_token') }}</code>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            @forelse ($tokens as $token)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ $token->name }}
                                @if ($token->isRevoked())
                                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700">revoked</span>
                                @elseif ($token->isExpired())
                                    <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">expired</span>
                                @endif
                                @if ($token->isMachine())
                                    <span class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">machine</span>
                                @endif
                            </h3>
                            <p class="text-[11px] text-gray-500">
                                {{ $token->isMachine() ? 'Acts as no user' : 'Acts as '.($token->actingUser()?->name ?? 'a removed user') }}
                                · {{ $token->rate_limit_per_minute }}/min
                                · {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                            </p>
                            <p class="mt-1 font-mono text-[10px] text-gray-400">{{ implode(', ', $token->scopes()) }}</p>
                        </div>
                        @unless ($token->isRevoked())
                            <form method="POST" action="{{ route('admin.api-tokens.destroy', $token) }}" class="shrink-0">
                                @csrf @method('DELETE')
                                <button class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                                    Revoke
                                </button>
                            </form>
                        @endunless
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    No tokens yet.
                </div>
            @endforelse
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">New token</h3>
            <form method="POST" action="{{ route('admin.api-tokens.store') }}" class="mt-3 space-y-3">
                @csrf
                <label class="block">
                    <span class="text-[11px] font-medium text-gray-500">What is it for</span>
                    <input type="text" name="name" required placeholder="Nightly KRI feed"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                </label>
                @if ($canManage)
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">Kind</span>
                        <select name="token_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option value="personal">Personal — acts as me</option>
                            <option value="client_credentials">Machine — acts as no user</option>
                        </select>
                    </label>
                @endif
                <label class="block">
                    <span class="text-[11px] font-medium text-gray-500">Scopes</span>
                    <select name="scopes[]" multiple size="12" required class="mt-1 w-full rounded-lg border-gray-300 text-xs">
                        @foreach ($scopes as $scope)
                            <option value="{{ $scope }}">{{ $scope }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-[11px] font-medium text-gray-500">Expires after (days)</span>
                    <input type="number" name="expires_in_days" min="1" max="3650" placeholder="never"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                </label>
                <button type="submit" class="w-full rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
                    Issue token
                </button>
            </form>
        </div>
    </div>
@endsection
