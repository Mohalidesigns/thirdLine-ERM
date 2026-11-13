import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { RegionSaveFailure } from '@/Components/Sm/FailureBanner';
import useSaveFailure from '@/Components/Sm/useSaveFailure';

export default function VariantsTab({ scenario, variants, can, urls, onDirtyChange }) {
    const editable = Boolean(can?.update);
    const saveFailure = useSaveFailure('variants');

    const [items, setItems] = useState(variants?.items || []);
    const [saving, setSaving] = useState(false);
    const [isDirty, setIsDirty] = useState(false);

    useEffect(() => {
        if (variants?.items) {
            setItems(variants.items);
            setIsDirty(false);
        }
    }, [variants]);

    useEffect(() => {
        onDirtyChange?.(editable && isDirty);
    }, [isDirty, editable, onDirtyChange]);

    const updateTierField = (tier, field, value) => {
        if (!editable) return;
        setItems((prev) =>
            prev.map((item) => (item.tier === tier ? { ...item, [field]: value } : item))
        );
        setIsDirty(true);
    };

    const addListItem = (tier, listField) => {
        if (!editable) return;
        setItems((prev) =>
            prev.map((item) => {
                if (item.tier !== tier) return item;
                const currentList = Array.isArray(item[listField]) ? item[listField] : [];
                return { ...item, [listField]: [...currentList, ''] };
            })
        );
        setIsDirty(true);
    };

    const updateListItem = (tier, listField, index, value) => {
        if (!editable) return;
        setItems((prev) =>
            prev.map((item) => {
                if (item.tier !== tier) return item;
                const currentList = [...(item[listField] || [])];
                currentList[index] = value;
                return { ...item, [listField]: currentList };
            })
        );
        setIsDirty(true);
    };

    const removeListItem = (tier, listField, index) => {
        if (!editable) return;
        setItems((prev) =>
            prev.map((item) => {
                if (item.tier !== tier) return item;
                const currentList = (item[listField] || []).filter((_, i) => i !== index);
                return { ...item, [listField]: currentList };
            })
        );
        setIsDirty(true);
    };

    const handleSave = (e) => {
        e?.preventDefault();
        if (!editable || saving) return;

        setSaving(true);
        saveFailure.clear();

        const payload = {
            variants: items.map((item) => ({
                tier: item.tier,
                name: item.name,
                narrative: item.narrative,
                is_cumulative: Boolean(item.is_cumulative),
                triggers: (item.triggers || []).filter((s) => s.trim().length > 0),
                operational_impacts: (item.operational_impacts || []).filter((s) => s.trim().length > 0),
                responses: (item.responses || []).filter((s) => s.trim().length > 0),
                response_owners: (item.response_owners || []).filter((s) => s.trim().length > 0),
            })),
        };

        router.put(urls.variants, payload, {
            preserveScroll: true,
            onSuccess: () => {
                setSaving(false);
                setIsDirty(false);
            },
            onError: (errs) => {
                setSaving(false);
                saveFailure.record(errs);
            },
        });
    };

    return (
        <form onSubmit={handleSave} className="space-y-6">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-200 dark:border-gray-800">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Severe but Plausible Variant Deck
                    </h2>
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        Escalating severity tiers, trigger thresholds, operational impacts, and response measures.
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    {urls?.deck && (
                        <a
                            href={urls.deck}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                        >
                            <svg className="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                            </svg>
                            Executive Deck View
                        </a>
                    )}
                    {editable && (
                        <button
                            type="submit"
                            disabled={saving || !isDirty}
                            className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {saving ? 'Saving...' : 'Save Variant Deck'}
                        </button>
                    )}
                </div>
            </div>

            <RegionSaveFailure failure={saveFailure.failure} onDismiss={saveFailure.clear} />

            <div className="space-y-6">
                {items.map((item, index) => {
                    const tierBadgeColors = {
                        tier_1: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800',
                        tier_2: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border-amber-300 dark:border-amber-800',
                        tier_3: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border-rose-300 dark:border-rose-800',
                    }[item.tier] || 'bg-gray-100 text-gray-800 border-gray-300';

                    return (
                        <div
                            key={item.tier}
                            className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg shadow-sm p-5 space-y-4"
                        >
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-gray-100 dark:border-gray-800">
                                <div className="flex items-center gap-3 flex-1">
                                    <span className={`px-2.5 py-1 text-xs font-semibold rounded-full border ${tierBadgeColors}`}>
                                        {item.tier_label || `Tier ${index + 1}`}
                                    </span>
                                    <input
                                        type="text"
                                        disabled={!editable}
                                        value={item.name || ''}
                                        onChange={(e) => updateTierField(item.tier, 'name', e.target.value)}
                                        className="text-base font-semibold text-gray-900 dark:text-gray-100 bg-transparent border-0 border-b border-transparent hover:border-gray-300 focus:border-indigo-500 focus:ring-0 w-full sm:w-2/3 px-1 py-0.5"
                                        placeholder="Variant tier name..."
                                    />
                                </div>
                                <label className="inline-flex items-center gap-2 cursor-pointer text-xs text-gray-600 dark:text-gray-400">
                                    <input
                                        type="checkbox"
                                        disabled={!editable}
                                        checked={Boolean(item.is_cumulative)}
                                        onChange={(e) => updateTierField(item.tier, 'is_cumulative', e.target.checked)}
                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900"
                                    />
                                    <span>Cumulative response build</span>
                                </label>
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Tier Narrative & Operating Context
                                </label>
                                <textarea
                                    disabled={!editable}
                                    rows={2}
                                    value={item.narrative || ''}
                                    onChange={(e) => updateTierField(item.tier, 'narrative', e.target.value)}
                                    placeholder="Describe the operating conditions, external situation, and firm impact at this tier..."
                                    className="w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500 shadow-sm"
                                />
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-5 pt-2">
                                {/* Triggers */}
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-amber-500"></span>
                                            Triggers & Thresholds
                                        </h4>
                                        {editable && (
                                            <button
                                                type="button"
                                                onClick={() => addListItem(item.tier, 'triggers')}
                                                className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                            >
                                                + Add Trigger
                                            </button>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        {(item.triggers || []).map((trigger, tIdx) => (
                                            <div key={tIdx} className="flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    disabled={!editable}
                                                    value={trigger}
                                                    onChange={(e) => updateListItem(item.tier, 'triggers', tIdx, e.target.value)}
                                                    placeholder="Trigger threshold (e.g. cases > 50, BCP alert)..."
                                                    className="flex-1 text-xs rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500 py-1"
                                                />
                                                {editable && (
                                                    <button
                                                        type="button"
                                                        onClick={() => removeListItem(item.tier, 'triggers', tIdx)}
                                                        className="text-gray-400 hover:text-rose-500 text-sm px-1"
                                                    >
                                                        &times;
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                        {(!item.triggers || item.triggers.length === 0) && (
                                            <p className="text-xs italic text-gray-400">No triggers documented.</p>
                                        )}
                                    </div>
                                </div>

                                {/* Operational Impacts */}
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-rose-500"></span>
                                            Operational Impacts
                                        </h4>
                                        {editable && (
                                            <button
                                                type="button"
                                                onClick={() => addListItem(item.tier, 'operational_impacts')}
                                                className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                            >
                                                + Add Impact
                                            </button>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        {(item.operational_impacts || []).map((impact, iIdx) => (
                                            <div key={iIdx} className="flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    disabled={!editable}
                                                    value={impact}
                                                    onChange={(e) => updateListItem(item.tier, 'operational_impacts', iIdx, e.target.value)}
                                                    placeholder="Operational impact (e.g. branch closure, staff quarantine)..."
                                                    className="flex-1 text-xs rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500 py-1"
                                                />
                                                {editable && (
                                                    <button
                                                        type="button"
                                                        onClick={() => removeListItem(item.tier, 'operational_impacts', iIdx)}
                                                        className="text-gray-400 hover:text-rose-500 text-sm px-1"
                                                    >
                                                        &times;
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                        {(!item.operational_impacts || item.operational_impacts.length === 0) && (
                                            <p className="text-xs italic text-gray-400">No impacts documented.</p>
                                        )}
                                    </div>
                                </div>

                                {/* Firm Responses */}
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-indigo-500"></span>
                                            Organizational Responses
                                        </h4>
                                        {editable && (
                                            <button
                                                type="button"
                                                onClick={() => addListItem(item.tier, 'responses')}
                                                className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                            >
                                                + Add Response
                                            </button>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        {(item.responses || []).map((resp, rIdx) => (
                                            <div key={rIdx} className="flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    disabled={!editable}
                                                    value={resp}
                                                    onChange={(e) => updateListItem(item.tier, 'responses', rIdx, e.target.value)}
                                                    placeholder="Mitigation or response measure..."
                                                    className="flex-1 text-xs rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500 py-1"
                                                />
                                                {editable && (
                                                    <button
                                                        type="button"
                                                        onClick={() => removeListItem(item.tier, 'responses', rIdx)}
                                                        className="text-gray-400 hover:text-rose-500 text-sm px-1"
                                                    >
                                                        &times;
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                        {(!item.responses || item.responses.length === 0) && (
                                            <p className="text-xs italic text-gray-400">No responses documented.</p>
                                        )}
                                    </div>
                                </div>

                                {/* Response Owners */}
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-cyan-500"></span>
                                            Response Owners & Departments
                                        </h4>
                                        {editable && (
                                            <button
                                                type="button"
                                                onClick={() => addListItem(item.tier, 'response_owners')}
                                                className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                            >
                                                + Add Owner
                                            </button>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        {(item.response_owners || []).map((owner, oIdx) => (
                                            <div key={oIdx} className="flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    disabled={!editable}
                                                    value={owner}
                                                    onChange={(e) => updateListItem(item.tier, 'response_owners', oIdx, e.target.value)}
                                                    placeholder="Department or owning unit (e.g. ERM, Corporate Services)..."
                                                    className="flex-1 text-xs rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500 py-1"
                                                />
                                                {editable && (
                                                    <button
                                                        type="button"
                                                        onClick={() => removeListItem(item.tier, 'response_owners', oIdx)}
                                                        className="text-gray-400 hover:text-rose-500 text-sm px-1"
                                                    >
                                                        &times;
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                        {(!item.response_owners || item.response_owners.length === 0) && (
                                            <p className="text-xs italic text-gray-400">No owners specified.</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </form>
    );
}

