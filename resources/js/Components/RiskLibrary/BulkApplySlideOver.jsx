import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const STEPS = [
    { key: 'items', label: '1. Library Risks' },
    { key: 'targets', label: '2. Targets' },
    { key: 'preview', label: '3. Preview & Confirm' },
];

/**
 * Edge-anchored Bulk Apply slide-over (ADR 0028, add-from-library-picker.md §3B).
 *
 * Applies 1..N active Risk Library items to 1..N Entities or Business Processes
 * with a mandatory server-computed dry-run preview before committing.
 */
export default function BulkApplySlideOver({
    open,
    onClose,
    initialItem = null,
    canCreateLibraryItem = false,
}) {
    const [step, setStep] = useState('items');
    const [loadingOptions, setLoadingOptions] = useState(false);
    const [optionsError, setOptionsError] = useState(null);
    const [pickerData, setPickerData] = useState({
        items: [],
        categories: [],
        baselL1: [],
        owners: [],
        entities: [],
        processes: [],
    });

    // Step 1: selected library items
    const [itemSearch, setItemSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [baselL1Filter, setBaselL1Filter] = useState('');
    const [selectedItemIds, setSelectedItemIds] = useState([]);

    // Step 2: target mode & targets
    const [targetMode, setTargetMode] = useState('entities');
    const [targetSearch, setTargetSearch] = useState('');
    const [selectedEntityIds, setSelectedEntityIds] = useState([]);
    const [selectedProcessIds, setSelectedProcessIds] = useState([]);
    const [defaultOwnerId, setDefaultOwnerId] = useState('');

    // Step 3: dry-run preview & commit
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewData, setPreviewData] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState(null);

    const searchInputRef = useRef(null);
    const createLibraryUrl = tryRoute('risk.library.create');

    // Reset state whenever the slide-over opens
    useEffect(() => {
        if (!open) return;

        if (initialItem?.id) {
            setSelectedItemIds([initialItem.id]);
            setStep('targets');
        } else {
            setSelectedItemIds([]);
            setStep('items');
        }

        setItemSearch('');
        setCategoryFilter('');
        setBaselL1Filter('');
        setTargetMode('entities');
        setTargetSearch('');
        setSelectedEntityIds([]);
        setSelectedProcessIds([]);
        setDefaultOwnerId('');
        setPreviewData(null);
        setErrorMessage(null);
        setOptionsError(null);
    }, [open, initialItem?.id]);

    // Lazy-load picker options when open or server-side item filters change
    useEffect(() => {
        if (!open) return;

        const controller = new AbortController();
        const url = tryRoute('risk.library.picker-options');
        if (!url) return;

        const params = new URLSearchParams();
        if (itemSearch.trim()) params.set('search', itemSearch.trim());
        if (categoryFilter) params.set('category_id', categoryFilter);
        if (baselL1Filter) params.set('basel_l1', baselL1Filter);

        const timeout = setTimeout(async () => {
            setLoadingOptions(true);
            setOptionsError(null);
            try {
                const res = await fetch(`${url}${params.toString() ? `?${params.toString()}` : ''}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!res.ok) {
                    throw new Error(`Failed to load library options (${res.status}).`);
                }
                const json = await res.json();
                setPickerData({
                    items: json.items ?? [],
                    categories: json.categories ?? [],
                    baselL1: json.baselL1 ?? [],
                    owners: json.owners ?? [],
                    entities: json.entities ?? [],
                    processes: json.processes ?? [],
                });
            } catch (err) {
                if (err.name !== 'AbortError') {
                    setOptionsError('Could not load Risk Library options. Please try again.');
                }
            } finally {
                setLoadingOptions(false);
            }
        }, 250);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [open, itemSearch, categoryFilter, baselL1Filter]);

    // Focus search input when opening
    useEffect(() => {
        if (open) {
            const t = setTimeout(() => searchInputRef.current?.focus(), 60);
            return () => clearTimeout(t);
        }
    }, [open, step]);

    const hasSelections =
        selectedEntityIds.length > 0 ||
        selectedProcessIds.length > 0 ||
        (!initialItem && selectedItemIds.length > 0);

    const requestClose = () => {
        if (submitting || previewLoading) return;
        if (hasSelections && !window.confirm('Discard selection?')) {
            return;
        }
        onClose();
    };

    // Escape key closes drawer
    useEffect(() => {
        if (!open) return;
        const onKeyDown = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                requestClose();
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, hasSelections, submitting, previewLoading]);

    // Merge initialItem with loaded items so pre-selected item chip always displays
    const knownItemsById = useMemo(() => {
        const map = new Map();
        if (initialItem?.id) {
            map.set(initialItem.id, {
                id: initialItem.id,
                code: initialItem.code,
                title: initialItem.title,
                current_version: initialItem.current_version,
            });
        }
        for (const item of pickerData.items) {
            map.set(item.id, item);
        }
        return map;
    }, [initialItem, pickerData.items]);

    const toggleItemId = (id) => {
        setPreviewData(null);
        setSelectedItemIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const visibleTargets = useMemo(() => {
        const source = targetMode === 'processes' ? pickerData.processes : pickerData.entities;
        const q = targetSearch.trim().toLowerCase();
        if (!q) return source;
        return source.filter(
            (t) =>
                (t.name ?? '').toLowerCase().includes(q) ||
                (t.code ?? '').toLowerCase().includes(q),
        );
    }, [targetMode, pickerData.entities, pickerData.processes, targetSearch]);

    const selectedTargetIds = targetMode === 'processes' ? selectedProcessIds : selectedEntityIds;
    const setSelectedTargetIds = targetMode === 'processes' ? setSelectedProcessIds : setSelectedEntityIds;

    const toggleTargetId = (id) => {
        setPreviewData(null);
        setSelectedTargetIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const selectAllVisibleTargets = () => {
        setPreviewData(null);
        const visibleIds = visibleTargets.map((t) => t.id);
        setSelectedTargetIds((prev) => Array.from(new Set([...prev, ...visibleIds])));
    };

    const clearTargets = () => {
        setPreviewData(null);
        setSelectedTargetIds([]);
    };

    const ownerNameById = useMemo(() => {
        const map = new Map();
        for (const o of pickerData.owners) {
            map.set(String(o.id), o.name);
        }
        return map;
    }, [pickerData.owners]);

    const runPreview = async () => {
        setErrorMessage(null);
        if (selectedItemIds.length === 0) {
            setErrorMessage('Select at least one active Risk Library item.');
            setStep('items');
            return;
        }
        if (selectedTargetIds.length === 0) {
            setErrorMessage(`Select at least one ${targetMode === 'processes' ? 'process' : 'entity'} to apply to.`);
            return;
        }

        const previewUrl = tryRoute('risk.library.bulk-apply.preview');
        if (!previewUrl) {
            setErrorMessage('Bulk Apply preview endpoint is unavailable.');
            return;
        }

        setPreviewLoading(true);
        try {
            const response = await window.axios.post(previewUrl, {
                library_item_ids: selectedItemIds,
                target_mode: targetMode,
                entity_ids: targetMode === 'entities' ? selectedEntityIds : [],
                process_ids: targetMode === 'processes' ? selectedProcessIds : [],
                default_owner_id: defaultOwnerId ? Number(defaultOwnerId) : null,
            });
            setPreviewData(response.data);
            setStep('preview');
        } catch (err) {
            const msg =
                err?.response?.data?.message ||
                Object.values(err?.response?.data?.errors ?? {})?.[0]?.[0] ||
                'Could not generate Bulk Apply preview. Please check your selections and try again.';
            setErrorMessage(msg);
        } finally {
            setPreviewLoading(false);
        }
    };

    const commitBulkApply = () => {
        if (!previewData || (previewData.summary?.new_count ?? 0) === 0) return;

        const commitUrl = tryRoute('risk.library.bulk-apply');
        if (!commitUrl) {
            setErrorMessage('Bulk Apply commit endpoint is unavailable.');
            return;
        }

        setErrorMessage(null);
        setSubmitting(true);

        router.post(
            commitUrl,
            {
                library_item_ids: selectedItemIds,
                target_mode: targetMode,
                entity_ids: targetMode === 'entities' ? selectedEntityIds : [],
                process_ids: targetMode === 'processes' ? selectedProcessIds : [],
                default_owner_id: defaultOwnerId ? Number(defaultOwnerId) : null,
                confirm: true,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSubmitting(false);
                    onClose();
                },
                onError: (errors) => {
                    setSubmitting(false);
                    const first = Object.values(errors ?? {})[0];
                    setErrorMessage(first || 'Bulk Apply failed. Please review your selections.');
                },
                onFinish: () => {
                    setSubmitting(false);
                },
            },
        );
    };

    if (!open) return null;

    const newCount = previewData?.summary?.new_count ?? 0;
    const skipCount = previewData?.summary?.skip_count ?? 0;

    return (
        <div
            className="fixed inset-0 z-40 flex justify-end"
            role="dialog"
            aria-modal="true"
            aria-labelledby="bulk-apply-title"
        >
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-gray-900/30 transition-opacity"
                onClick={requestClose}
                aria-hidden="true"
            />

            {/* Slide-over panel */}
            <div className="relative z-10 w-full max-w-2xl bg-white h-full shadow-2xl border-l border-gray-200 flex flex-col">
                {/* Header */}
                <div className="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-4">
                    <div>
                        <h2 id="bulk-apply-title" className="text-lg font-semibold text-[#1A365D]">
                            Bulk Apply from Risk Library
                        </h2>
                        <p className="text-xs text-gray-500 mt-0.5">
                            Instantiate active master risk definitions onto entities or business processes.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={requestClose}
                        aria-label="Close"
                        className="p-1.5 rounded-lg text-gray-500 hover:text-gray-800 hover:bg-gray-100"
                    >
                        <span className="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>

                {/* Step Rail */}
                <div className="px-6 py-2.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2 overflow-x-auto">
                    {STEPS.map((s) => {
                        const isActive = step === s.key;
                        const disabled =
                            (s.key === 'targets' && selectedItemIds.length === 0) ||
                            (s.key === 'preview' && !previewData);
                        return (
                            <button
                                key={s.key}
                                type="button"
                                disabled={disabled}
                                aria-current={isActive ? 'step' : undefined}
                                onClick={() => !disabled && setStep(s.key)}
                                className={`px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-colors ${
                                    isActive
                                        ? 'bg-[#1A365D] text-white'
                                        : disabled
                                          ? 'text-gray-400 cursor-not-allowed'
                                          : 'text-gray-700 hover:bg-gray-200/70'
                                }`}
                            >
                                {s.label}
                            </button>
                        );
                    })}
                </div>

                {/* Error Alert */}
                {(errorMessage || optionsError) && (
                    <div
                        role="alert"
                        className="mx-6 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-800 flex items-start gap-2"
                    >
                        <span className="material-symbols-outlined text-base shrink-0 mt-0.5">error</span>
                        <span>{errorMessage || optionsError}</span>
                    </div>
                )}

                {/* Body */}
                <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                    {/* Selected items chip summary */}
                    {selectedItemIds.length > 0 && (
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-xs font-semibold text-gray-700">
                                    Selected Library Risks ({selectedItemIds.length})
                                </span>
                                {step !== 'items' && (
                                    <button
                                        type="button"
                                        onClick={() => setStep('items')}
                                        className="text-xs font-medium text-[#1A365D] hover:underline"
                                    >
                                        Edit selection
                                    </button>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {selectedItemIds.map((id) => {
                                    const item = knownItemsById.get(id);
                                    return (
                                        <span
                                            key={id}
                                            className="inline-flex items-center gap-1 rounded-full bg-white border border-gray-300 px-2.5 py-0.5 text-xs text-gray-800"
                                        >
                                            <span className="font-mono font-semibold text-[#1A365D]">
                                                {item?.code ?? `#${id}`}
                                            </span>
                                            {item?.title && (
                                                <span className="max-w-[180px] truncate text-gray-600">
                                                    {item.title}
                                                </span>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => toggleItemId(id)}
                                                aria-label={`Remove ${item?.code ?? id}`}
                                                className="text-gray-400 hover:text-gray-700 ml-0.5"
                                            >
                                                ×
                                            </button>
                                        </span>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* STEP 1: Select Library Risks */}
                    {step === 'items' && (
                        <div className="space-y-3">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <input
                                    ref={searchInputRef}
                                    type="search"
                                    value={itemSearch}
                                    onChange={(e) => setItemSearch(e.target.value)}
                                    placeholder="Search code or title…"
                                    aria-label="Search library risks"
                                    className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                />
                                <select
                                    value={categoryFilter}
                                    onChange={(e) => setCategoryFilter(e.target.value)}
                                    aria-label="Filter by category"
                                    className="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">All Categories</option>
                                    {pickerData.categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    value={baselL1Filter}
                                    onChange={(e) => setBaselL1Filter(e.target.value)}
                                    aria-label="Filter by Basel L1"
                                    className="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">All Basel L1</option>
                                    {pickerData.baselL1.map((b) => (
                                        <option key={b.value} value={b.value}>
                                            {b.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {loadingOptions ? (
                                <div className="space-y-2" aria-busy="true">
                                    {[1, 2, 3, 4, 5].map((n) => (
                                        <div key={n} className="h-14 rounded-lg bg-gray-100 animate-pulse" />
                                    ))}
                                </div>
                            ) : pickerData.items.length === 0 ? (
                                itemSearch || categoryFilter || baselL1Filter ? (
                                    <div className="rounded-lg border border-gray-200 p-6 text-center">
                                        <p className="text-sm text-gray-600">
                                            No active library risks match your current search or filter.
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setItemSearch('');
                                                setCategoryFilter('');
                                                setBaselL1Filter('');
                                            }}
                                            className="mt-2 text-xs font-medium text-[#1A365D] hover:underline"
                                        >
                                            Clear filters
                                        </button>
                                    </div>
                                ) : (
                                    <EmptyState
                                        icon="menu_book"
                                        title="No active risks in the Risk Library."
                                        description={
                                            canCreateLibraryItem
                                                ? 'Create a master risk definition first before applying it to entities or processes.'
                                                : 'Ask your Risk Manager to populate the Risk Library.'
                                        }
                                        actionLabel={canCreateLibraryItem && createLibraryUrl ? 'Create Library Risk' : null}
                                        actionHref={canCreateLibraryItem ? createLibraryUrl : null}
                                    />
                                )
                            ) : (
                                <div className="divide-y divide-gray-200 border border-gray-200 rounded-lg">
                                    {pickerData.items.map((item) => {
                                        const checked = selectedItemIds.includes(item.id);
                                        return (
                                            <label
                                                key={item.id}
                                                className={`flex items-start gap-3 p-3 cursor-pointer hover:bg-blue-50/40 ${
                                                    checked ? 'bg-blue-50/60' : 'bg-white'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => toggleItemId(item.id)}
                                                    className="mt-1 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span className="font-mono text-xs font-semibold text-[#1A365D]">
                                                            {item.code}
                                                        </span>
                                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100 text-gray-700 font-mono text-[11px]">
                                                            v{item.current_version}
                                                        </span>
                                                        {item.category_name && (
                                                            <span className="text-xs text-gray-500">
                                                                {item.category_name}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="text-sm font-medium text-gray-900 mt-0.5">
                                                        {item.title}
                                                    </p>
                                                    {item.basel_l1_label && (
                                                        <p className="text-xs text-gray-500 mt-0.5">
                                                            {item.basel_l1_label}
                                                        </p>
                                                    )}
                                                </div>
                                            </label>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    )}

                    {/* STEP 2: Select Targets */}
                    {step === 'targets' && (
                        <div className="space-y-4">
                            <fieldset>
                                <legend className="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">
                                    Target Type
                                </legend>
                                <div className="grid grid-cols-2 gap-3">
                                    <label
                                        className={`flex items-center gap-2.5 p-3 rounded-lg border cursor-pointer ${
                                            targetMode === 'entities'
                                                ? 'border-[#1A365D] bg-blue-50/40 text-[#1A365D]'
                                                : 'border-gray-200 text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="target_mode"
                                            value="entities"
                                            checked={targetMode === 'entities'}
                                            onChange={() => {
                                                setTargetMode('entities');
                                                setPreviewData(null);
                                            }}
                                            className="text-[#1A365D] focus:ring-[#1A365D]"
                                        />
                                        <div>
                                            <p className="text-sm font-medium">Apply to Entities</p>
                                            <p className="text-xs text-gray-500">
                                                {pickerData.entities.length} available
                                            </p>
                                        </div>
                                    </label>
                                    <label
                                        className={`flex items-center gap-2.5 p-3 rounded-lg border cursor-pointer ${
                                            targetMode === 'processes'
                                                ? 'border-[#1A365D] bg-blue-50/40 text-[#1A365D]'
                                                : 'border-gray-200 text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="target_mode"
                                            value="processes"
                                            checked={targetMode === 'processes'}
                                            onChange={() => {
                                                setTargetMode('processes');
                                                setPreviewData(null);
                                            }}
                                            className="text-[#1A365D] focus:ring-[#1A365D]"
                                        />
                                        <div>
                                            <p className="text-sm font-medium">Apply to Processes</p>
                                            <p className="text-xs text-gray-500">
                                                {pickerData.processes.length} available
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </fieldset>

                            <div>
                                <label
                                    htmlFor="bulk-apply-owner"
                                    className="block text-xs font-semibold text-gray-700 mb-1"
                                >
                                    Default Risk Owner (optional)
                                </label>
                                <select
                                    id="bulk-apply-owner"
                                    value={defaultOwnerId}
                                    onChange={(e) => setDefaultOwnerId(e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">Leave unassigned</option>
                                    {pickerData.owners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between gap-2">
                                    <input
                                        ref={searchInputRef}
                                        type="search"
                                        value={targetSearch}
                                        onChange={(e) => setTargetSearch(e.target.value)}
                                        placeholder={`Search ${targetMode === 'processes' ? 'processes' : 'entities'}…`}
                                        aria-label={`Search ${targetMode}`}
                                        className="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                    />
                                    <button
                                        type="button"
                                        onClick={selectAllVisibleTargets}
                                        className="text-xs font-medium text-[#1A365D] hover:underline whitespace-nowrap px-2"
                                    >
                                        Select all visible
                                    </button>
                                    {selectedTargetIds.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={clearTargets}
                                            className="text-xs text-gray-600 hover:underline whitespace-nowrap"
                                        >
                                            Clear
                                        </button>
                                    )}
                                </div>

                                {loadingOptions ? (
                                    <div className="space-y-2" aria-busy="true">
                                        {[1, 2, 3, 4].map((n) => (
                                            <div key={n} className="h-10 rounded-lg bg-gray-100 animate-pulse" />
                                        ))}
                                    </div>
                                ) : visibleTargets.length === 0 ? (
                                    <div className="rounded-lg border border-gray-200 p-6 text-center text-sm text-gray-500">
                                        No {targetMode === 'processes' ? 'active processes' : 'entities'} found.
                                    </div>
                                ) : (
                                    <div className="max-h-72 overflow-y-auto divide-y divide-gray-200 border border-gray-200 rounded-lg">
                                        {visibleTargets.map((target) => {
                                            const checked = selectedTargetIds.includes(target.id);
                                            return (
                                                <label
                                                    key={target.id}
                                                    className={`flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-blue-50/40 ${
                                                        checked ? 'bg-blue-50/60' : 'bg-white'
                                                    }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleTargetId(target.id)}
                                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                    />
                                                    <div className="min-w-0 flex-1 flex items-center gap-2">
                                                        {target.code && (
                                                            <span className="font-mono text-xs text-gray-500">
                                                                {target.code}
                                                            </span>
                                                        )}
                                                        <span className="text-sm text-gray-900 truncate">
                                                            {target.name}
                                                        </span>
                                                    </div>
                                                </label>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* STEP 3: Mandatory Preview & Confirm */}
                    {step === 'preview' && previewData && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3">
                                    <p className="text-xs font-medium text-[#2D7D46]">
                                        New instances to create
                                    </p>
                                    <p className="text-2xl font-bold text-[#2D7D46] mt-0.5">{newCount}</p>
                                </div>
                                <div className="rounded-lg border border-gray-200 bg-gray-100 px-4 py-3">
                                    <p className="text-xs font-medium text-gray-700">
                                        Already attached — will skip
                                    </p>
                                    <p className="text-2xl font-bold text-gray-800 mt-0.5">{skipCount}</p>
                                </div>
                            </div>

                            <div className="rounded-lg border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs text-[#1A365D]">
                                New risk instances are created unrated (no scores copied). Risk owners will assess
                                inherent and residual scores in the Risk Register.
                            </div>

                            {newCount === 0 && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                                    All selected targets already have the chosen risk(s) attached. Go back to pick
                                    different targets.
                                </div>
                            )}

                            {(previewData.will_create ?? []).length > 0 && (
                                <div>
                                    <h3 className="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">
                                        Will Create ({previewData.will_create.length})
                                    </h3>
                                    <div className="overflow-x-auto border border-gray-200 rounded-lg">
                                        <table className="w-full text-xs">
                                            <thead className="bg-gray-50 text-gray-600 border-b border-gray-200">
                                                <tr>
                                                    <th className="px-3 py-2 text-left font-semibold">Library Code</th>
                                                    <th className="px-3 py-2 text-left font-semibold">Target</th>
                                                    <th className="px-3 py-2 text-left font-semibold">Owner</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200">
                                                {previewData.will_create.map((pair, idx) => (
                                                    <tr key={`${pair.library_item_id}-${pair.target_id}-${idx}`}>
                                                        <td className="px-3 py-2 font-mono font-semibold text-[#1A365D]">
                                                            {pair.library_code}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-800">
                                                            {pair.target_name}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-600">
                                                            {defaultOwnerId
                                                                ? ownerNameById.get(String(defaultOwnerId)) ?? '—'
                                                                : 'Unassigned'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            {(previewData.will_skip ?? []).length > 0 && (
                                <div>
                                    <h3 className="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">
                                        Will Skip ({previewData.will_skip.length})
                                    </h3>
                                    <div className="overflow-x-auto border border-gray-200 rounded-lg">
                                        <table className="w-full text-xs">
                                            <thead className="bg-gray-50 text-gray-600 border-b border-gray-200">
                                                <tr>
                                                    <th className="px-3 py-2 text-left font-semibold">Library Code</th>
                                                    <th className="px-3 py-2 text-left font-semibold">Target</th>
                                                    <th className="px-3 py-2 text-left font-semibold">Existing Risk</th>
                                                    <th className="px-3 py-2 text-left font-semibold">Reason</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200">
                                                {previewData.will_skip.map((pair, idx) => (
                                                    <tr
                                                        key={`${pair.library_item_id}-${pair.target_id}-${idx}`}
                                                        className="bg-gray-50/50 text-gray-600"
                                                    >
                                                        <td className="px-3 py-2 font-mono">{pair.library_code}</td>
                                                        <td className="px-3 py-2">{pair.target_name}</td>
                                                        <td className="px-3 py-2 font-mono">
                                                            {pair.existing_risk_code ?? '—'}
                                                        </td>
                                                        <td className="px-3 py-2">{pair.reason}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="px-6 py-4 border-t border-gray-200 bg-gray-50 flex items-center justify-between gap-3">
                    <div className="text-xs text-gray-600">
                        {step === 'items' && `${selectedItemIds.length} risk(s) selected`}
                        {step === 'targets' &&
                            `${selectedTargetIds.length} ${targetMode === 'processes' ? 'process(es)' : 'entit(ies)'} selected`}
                        {step === 'preview' && `${newCount} to create · ${skipCount} skipped`}
                    </div>

                    <div className="flex items-center gap-2">
                        {step === 'items' && (
                            <>
                                <button
                                    type="button"
                                    onClick={requestClose}
                                    className="btn-secondary text-sm"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    disabled={selectedItemIds.length === 0}
                                    onClick={() => {
                                        setErrorMessage(null);
                                        setStep('targets');
                                    }}
                                    className="btn-primary text-sm disabled:opacity-50"
                                >
                                    Next: Select Targets
                                </button>
                            </>
                        )}

                        {step === 'targets' && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => setStep('items')}
                                    className="btn-secondary text-sm"
                                >
                                    Back
                                </button>
                                <button
                                    type="button"
                                    disabled={selectedTargetIds.length === 0 || previewLoading}
                                    onClick={runPreview}
                                    className="btn-primary text-sm disabled:opacity-50 inline-flex items-center gap-1.5"
                                >
                                    {previewLoading ? 'Checking…' : 'Preview Changes'}
                                </button>
                            </>
                        )}

                        {step === 'preview' && (
                            <>
                                <button
                                    type="button"
                                    disabled={submitting}
                                    onClick={() => setStep('targets')}
                                    className="btn-secondary text-sm disabled:opacity-50"
                                >
                                    Back
                                </button>
                                <button
                                    type="button"
                                    disabled={newCount === 0 || submitting}
                                    onClick={commitBulkApply}
                                    className="btn-primary text-sm disabled:opacity-50 inline-flex items-center gap-1.5"
                                >
                                    {submitting
                                        ? 'Creating…'
                                        : `Confirm & Create ${newCount} Instance${newCount === 1 ? '' : 's'}`}
                                </button>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
