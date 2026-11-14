import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { RegionSaveFailure } from '@/Components/Sm/FailureBanner';
import useSaveFailure from '@/Components/Sm/useSaveFailure';

const DEFAULT_TEMPLATES = [
    {
        value: 'staff_turnover_interval',
        label: 'Staff Turnover (Count × Salary × Months × Frequency Interval)',
        description: 'Multiplies monthly cost by duration and the annual frequency interval factor.',
    },
    {
        value: 'litigation_probability',
        label: 'Litigation / Legal ((Count × Unit Amount + Fees) × Probability)',
        description: 'Sums variable and flat costs, scaled by the probability of legal action per severity.',
    },
];

const COMPUTATION_ROLES = [
    { value: 'count', label: 'Count / Volume' },
    { value: 'unit_amount', label: 'Unit Amount / Salary' },
    { value: 'duration', label: 'Duration (Months)' },
    { value: 'probability', label: 'Probability (0 to 1)' },
    { value: 'flat_fee', label: 'Flat Fee / Legal Costs' },
];

function formatCurrency(amount, currency = 'NGN') {
    const val = parseFloat(amount || 0);
    if (isNaN(val)) return '—';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency || 'NGN',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(val);
}

export default function ImpactTab({ scenario, impact, can, urls, onDirtyChange }) {
    const editable = Boolean(can?.design) && !impact?.is_read_only;
    const saveFailure = useSaveFailure({
        copySummary: () => (types || []).map((t) => t.name).join('\n'),
    });

    const currency = impact?.currency || scenario?.currency || 'NGN';
    const freqInterval = parseFloat(impact?.frequency_interval || 0.0);

    const [types, setTypes] = useState(impact?.types || []);
    const [saving, setSaving] = useState(false);
    const [isDirty, setIsDirty] = useState(false);

    useEffect(() => {
        if (impact?.types) {
            setTypes(impact.types);
            setIsDirty(false);
        }
    }, [impact]);

    useEffect(() => {
        onDirtyChange?.(editable && isDirty);
    }, [isDirty, editable, onDirtyChange]);

    const handleTypeChange = (typeIndex, field, value) => {
        if (!editable) return;
        setTypes((prev) => {
            const next = [...prev];
            next[typeIndex] = { ...next[typeIndex], [field]: value };
            return next;
        });
        setIsDirty(true);
    };

    const handleCriterionChange = (typeIndex, critIndex, field, value) => {
        if (!editable) return;
        setTypes((prev) => {
            const next = [...prev];
            const critList = [...next[typeIndex].criteria];
            critList[critIndex] = { ...critList[critIndex], [field]: value };
            next[typeIndex] = { ...next[typeIndex], criteria: critList };
            return next;
        });
        setIsDirty(true);
    };

    const handleAddCriterion = (typeIndex) => {
        if (!editable) return;
        setTypes((prev) => {
            const next = [...prev];
            const critList = [...(next[typeIndex].criteria || [])];
            critList.push({
                id: null,
                label: 'New criterion',
                unit: '',
                computation_role: 'count',
                data_source: 'Internal estimate',
                typical_value: '0',
                serious_value: '0',
                extreme_value: '0',
                sort_order: critList.length + 1,
            });
            next[typeIndex] = { ...next[typeIndex], criteria: critList };
            return next;
        });
        setIsDirty(true);
    };

    const handleRemoveCriterion = (typeIndex, critIndex) => {
        if (!editable) return;
        setTypes((prev) => {
            const next = [...prev];
            const critList = next[typeIndex].criteria.filter((_, idx) => idx !== critIndex);
            next[typeIndex] = { ...next[typeIndex], criteria: critList };
            return next;
        });
        setIsDirty(true);
    };

    const handleAddType = () => {
        if (!editable) return;
        setTypes((prev) => [
            ...prev,
            {
                id: null,
                name: 'New Impact Category',
                aggregation_template: 'staff_turnover_interval',
                eligible_to_transfer: false,
                sort_order: prev.length + 1,
                criteria: [
                    {
                        id: null,
                        label: 'Staff Count',
                        unit: 'people',
                        computation_role: 'count',
                        data_source: 'HR records',
                        typical_value: '10',
                        serious_value: '20',
                        extreme_value: '50',
                        sort_order: 1,
                    },
                    {
                        id: null,
                        label: 'Monthly Cost',
                        unit: currency,
                        computation_role: 'unit_amount',
                        data_source: 'Finance',
                        typical_value: '500000',
                        serious_value: '500000',
                        extreme_value: '500000',
                        sort_order: 2,
                    },
                    {
                        id: null,
                        label: 'Months Duration',
                        unit: 'months',
                        computation_role: 'duration',
                        data_source: 'HR records',
                        typical_value: '3',
                        serious_value: '6',
                        extreme_value: '12',
                        sort_order: 3,
                    },
                ],
            },
        ]);
        setIsDirty(true);
    };

    const handleRemoveType = (typeIndex) => {
        if (!editable) return;
        setTypes((prev) => prev.filter((_, idx) => idx !== typeIndex));
        setIsDirty(true);
    };

    const handleSave = (e) => {
        e?.preventDefault();
        if (!editable || saving) return;

        setSaving(true);
        saveFailure.begin();

        const payload = {
            types: types.map((t) => ({
                name: t.name,
                aggregation_template: t.aggregation_template,
                eligible_to_transfer: Boolean(t.eligible_to_transfer),
                criteria: (t.criteria || []).map((c) => ({
                    label: c.label,
                    unit: c.unit,
                    computation_role: c.computation_role,
                    data_source: c.data_source,
                    typical_value: c.typical_value,
                    serious_value: c.serious_value,
                    extreme_value: c.extreme_value,
                })),
            })),
        };

        const targetUrl = urls?.impact || `/risk/scenarios/${scenario.id}/impact`;

        router.put(targetUrl, payload, {
            preserveScroll: true,
            onSuccess: () => {
                saveFailure.succeeded();
                setIsDirty(false);
                setSaving(false);
            },
            onError: () => {
                saveFailure.succeeded();
                setSaving(false);
            },
        });
    };

    return (
        <section aria-labelledby="impact-heading" className="space-y-6 max-w-6xl">
            <RegionSaveFailure saveFailure={saveFailure} onResend={handleSave} />

            {/* Gross Impact Summary Header */}
            <div className="bg-slate-900 border border-slate-800 rounded-lg p-6 shadow-sm text-slate-100">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-800 gap-2">
                    <div>
                        <span className="text-xs font-semibold tracking-wider text-slate-400 uppercase">
                            Total Gross Financial Loss
                        </span>
                        <h2 id="impact-heading" className="text-sm text-slate-300 font-medium">
                            Aggregated across all defined loss categories
                        </h2>
                    </div>
                    {editable && (
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving || !isDirty}
                            className={`inline-flex items-center justify-center px-4 py-2 rounded-md text-xs font-semibold transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 ${
                                isDirty
                                    ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                    : 'bg-slate-800 text-slate-500 cursor-not-allowed'
                            }`}
                        >
                            {saving ? 'Saving...' : 'Save Impact Assessment'}
                        </button>
                    )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-5">
                    <div className="bg-slate-800/60 rounded-md p-4 border border-slate-700/60">
                        <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                            Typical Severity
                        </span>
                        <div className="mt-1 text-2xl font-bold text-white tracking-tight">
                            {formatCurrency(impact?.gross_impact?.typical, currency)}
                        </div>
                        <p className="mt-1 text-[11px] text-slate-400">Baseline occurrence impact</p>
                    </div>

                    <div className="bg-slate-800/60 rounded-md p-4 border border-slate-700/60">
                        <span className="text-xs font-semibold text-amber-400 uppercase tracking-wider">
                            Serious Severity
                        </span>
                        <div className="mt-1 text-2xl font-bold text-amber-300 tracking-tight">
                            {formatCurrency(impact?.gross_impact?.serious, currency)}
                        </div>
                        <p className="mt-1 text-[11px] text-slate-400">Heightened operational disruption</p>
                    </div>

                    <div className="bg-slate-800/60 rounded-md p-4 border border-slate-700/60">
                        <span className="text-xs font-semibold text-rose-400 uppercase tracking-wider">
                            Extreme Severity
                        </span>
                        <div className="mt-1 text-2xl font-bold text-rose-300 tracking-tight">
                            {formatCurrency(impact?.gross_impact?.extreme, currency)}
                        </div>
                        <p className="mt-1 text-[11px] text-slate-400">Tail-risk catastrophic outcome</p>
                    </div>
                </div>
            </div>

            {/* Categories */}
            <div className="space-y-6">
                {types.map((t, tIdx) => (
                    <div key={t.id || tIdx} className="bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden">
                        <div className="p-5 bg-slate-50/70 border-b border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                            <div className="flex-1 space-y-2">
                                <div className="flex items-center gap-3">
                                    <span className="text-xs font-mono font-bold text-slate-400">#{tIdx + 1}</span>
                                    {editable ? (
                                        <input
                                            type="text"
                                            value={t.name}
                                            onChange={(e) => handleTypeChange(tIdx, 'name', e.target.value)}
                                            className="text-base font-bold text-slate-900 border-b border-slate-300 focus:border-indigo-500 bg-transparent px-1 py-0.5 w-full max-w-md"
                                            placeholder="Category name"
                                        />
                                    ) : (
                                        <h3 className="text-base font-bold text-slate-900">{t.name}</h3>
                                    )}
                                </div>

                                <div className="flex flex-wrap items-center gap-4 text-xs">
                                    <div className="flex items-center gap-2">
                                        <span className="text-slate-500 font-medium">Template:</span>
                                        {editable ? (
                                            <select
                                                value={t.aggregation_template}
                                                onChange={(e) => handleTypeChange(tIdx, 'aggregation_template', e.target.value)}
                                                className="text-xs border border-slate-300 rounded px-2 py-1 bg-white font-medium text-slate-800 focus:ring-1 focus:ring-indigo-500"
                                            >
                                                {DEFAULT_TEMPLATES.map((tmpl) => (
                                                    <option key={tmpl.value} value={tmpl.value}>
                                                        {tmpl.label}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <span className="font-semibold text-slate-800">
                                                {DEFAULT_TEMPLATES.find((x) => x.value === t.aggregation_template)?.label || t.aggregation_template}
                                            </span>
                                        )}
                                    </div>

                                    <label className="flex items-center gap-2 cursor-pointer select-none">
                                        <input
                                            type="checkbox"
                                            checked={t.eligible_to_transfer}
                                            disabled={!editable}
                                            onChange={(e) => handleTypeChange(tIdx, 'eligible_to_transfer', e.target.checked)}
                                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                                        />
                                        <span className="font-medium text-slate-700">Eligible to Transfer to Insurance</span>
                                    </label>
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <div className="text-right text-xs">
                                    <div className="text-slate-500 text-[11px] font-medium">Category Subtotal (Typ / Ser / Ext)</div>
                                    <div className="font-semibold text-slate-900">
                                        {formatCurrency(t.typical_total, currency)} / {formatCurrency(t.serious_total, currency)} / {formatCurrency(t.extreme_total, currency)}
                                    </div>
                                </div>

                                {editable && types.length > 1 && (
                                    <button
                                        type="button"
                                        onClick={() => handleRemoveType(tIdx)}
                                        className="text-rose-600 hover:text-rose-800 text-xs font-semibold px-2 py-1 rounded hover:bg-rose-50"
                                    >
                                        Remove Category
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Criteria Matrix */}
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-left text-xs">
                                <thead className="bg-slate-50 text-[11px] font-semibold text-slate-600 uppercase tracking-wider">
                                    <tr>
                                        <th scope="col" className="px-4 py-2.5">Criterion</th>
                                        <th scope="col" className="px-3 py-2.5">Unit</th>
                                        <th scope="col" className="px-3 py-2.5">Computation Role</th>
                                        <th scope="col" className="px-3 py-2.5">Source</th>
                                        <th scope="col" className="px-3 py-2.5">Typical</th>
                                        <th scope="col" className="px-3 py-2.5">Serious</th>
                                        <th scope="col" className="px-3 py-2.5">Extreme</th>
                                        {editable && <th scope="col" className="px-3 py-2.5 text-right">Action</th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {(t.criteria || []).map((c, cIdx) => (
                                        <tr key={c.id || cIdx} className="hover:bg-slate-50/60">
                                            <td className="px-4 py-2.5">
                                                {editable ? (
                                                    <input
                                                        type="text"
                                                        value={c.label}
                                                        onChange={(e) => handleCriterionChange(tIdx, cIdx, 'label', e.target.value)}
                                                        className="w-full min-w-[120px] px-2 py-1 border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-medium"
                                                    />
                                                ) : (
                                                    <span className="font-semibold text-slate-900">{c.label}</span>
                                                )}
                                            </td>

                                            <td className="px-3 py-2.5">
                                                {editable ? (
                                                    <input
                                                        type="text"
                                                        value={c.unit || ''}
                                                        onChange={(e) => handleCriterionChange(tIdx, cIdx, 'unit', e.target.value)}
                                                        placeholder="Unit"
                                                        className="w-20 px-2 py-1 border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500"
                                                    />
                                                ) : (
                                                    <span className="text-slate-500">{c.unit || '—'}</span>
                                                )}
                                            </td>

                                            <td className="px-3 py-2.5">
                                                {editable ? (
                                                    <select
                                                        value={c.computation_role}
                                                        onChange={(e) => handleCriterionChange(tIdx, cIdx, 'computation_role', e.target.value)}
                                                        className="border border-slate-300 rounded px-2 py-1 bg-white focus:ring-1 focus:ring-indigo-500"
                                                    >
                                                        {COMPUTATION_ROLES.map((role) => (
                                                            <option key={role.value} value={role.value}>
                                                                {role.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-800">
                                                        {c.computation_role}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-3 py-2.5">
                                                {editable ? (
                                                    <input
                                                        type="text"
                                                        value={c.data_source || ''}
                                                        onChange={(e) => handleCriterionChange(tIdx, cIdx, 'data_source', e.target.value)}
                                                        placeholder="Source"
                                                        className="w-24 px-2 py-1 border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500"
                                                    />
                                                ) : (
                                                    <span className="text-slate-500">{c.data_source || '—'}</span>
                                                )}
                                            </td>

                                            <td className="px-3 py-2.5">
                                                {editable ? (
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        value={c.typical_value}
                                                        onChange={(e) => handleCriterionChange(tIdx, cIdx, 'typical_value', e.target.value)}
                                                        className="w-24 px-2 py-1 border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono font-medium text-slate-900"
                                                    />
                                                ) : (
                                                    <span className="font-mono">{c.typical_value}</span>
                                                )}
                                            </td>

                                            <td className="px-3 py-2.5">
                                                {editable ? (
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        value={c.serious_value}
                                                        onChange={(e) => handleCriterionChange(tIdx, cIdx, 'serious_value', e.target.value)}
                                                        className="w-24 px-2 py-1 border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono font-medium text-amber-800"
                                                    />
                                                ) : (
                                                    <span className="font-mono text-amber-800">{c.serious_value}</span>
                                                )}
                                            </td>

                                            <td className="px-3 py-2.5">
                                                {editable ? (
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        value={c.extreme_value}
                                                        onChange={(e) => handleCriterionChange(tIdx, cIdx, 'extreme_value', e.target.value)}
                                                        className="w-24 px-2 py-1 border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono font-medium text-rose-800"
                                                    />
                                                ) : (
                                                    <span className="font-mono text-rose-800">{c.extreme_value}</span>
                                                )}
                                            </td>

                                            {editable && (
                                                <td className="px-3 py-2.5 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleRemoveCriterion(tIdx, cIdx)}
                                                        disabled={(t.criteria || []).length <= 1}
                                                        className="text-slate-400 hover:text-rose-600 disabled:opacity-30 text-xs"
                                                    >
                                                        ✕
                                                    </button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {editable && (
                            <div className="p-3 bg-slate-50/40 border-t border-slate-100 flex justify-end">
                                <button
                                    type="button"
                                    onClick={() => handleAddCriterion(tIdx)}
                                    className="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    + Add Criterion
                                </button>
                            </div>
                        )}
                    </div>
                ))}
            </div>

            {editable && (
                <div className="flex justify-center pt-2">
                    <button
                        type="button"
                        onClick={handleAddType}
                        className="inline-flex items-center px-4 py-2 border border-slate-300 rounded-md text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 shadow-sm"
                    >
                        + Add Loss Category
                    </button>
                </div>
            )}
        </section>
    );
}

