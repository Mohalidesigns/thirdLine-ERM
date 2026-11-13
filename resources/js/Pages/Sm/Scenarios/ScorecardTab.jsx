import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { RegionSaveFailure } from '@/Components/Sm/FailureBanner';
import useSaveFailure from '@/Components/Sm/useSaveFailure';

export default function ScorecardTab({ scenario, scorecard, can, urls, onDirtyChange }) {
    const editable = Boolean(can?.design);
    const saveFailure = useSaveFailure('scorecard');

    const [nature, setNature] = useState(scorecard?.control_nature || 'mixed');
    const [components, setComponents] = useState(scorecard?.components || []);
    const [saving, setSaving] = useState(false);
    const [isDirty, setIsDirty] = useState(false);

    useEffect(() => {
        if (scorecard) {
            setNature(scorecard.control_nature || 'mixed');
            setComponents(scorecard.components || []);
            setIsDirty(false);
        }
    }, [scorecard]);

    useEffect(() => {
        onDirtyChange?.(editable && isDirty);
    }, [isDirty, editable, onDirtyChange]);

    const handleNatureChange = (newNature) => {
        if (!editable) return;
        setNature(newNature);
        setIsDirty(true);
    };

    const handleComponentChange = (code, field, val) => {
        if (!editable) return;
        setComponents((prev) =>
            prev.map((c) => (c.component_code === code ? { ...c, [field]: val } : c))
        );
        setIsDirty(true);
    };

    const handleSave = (e) => {
        e?.preventDefault();
        if (!editable || saving) return;

        setSaving(true);
        saveFailure.clear();

        const payload = {
            control_nature: nature,
            components: components.map((c) => ({
                component_code: c.component_code,
                efficiency_level: c.efficiency_level,
                weighting_factor: c.weighting_factor,
                notes: c.notes,
            })),
        };

        router.put(urls.scorecard, payload, {
            preserveScroll: true,
            onSuccess: () => {
                setIsDirty(false);
                setSaving(false);
            },
            onError: () => {
                setSaving(false);
            },
        });
    };

    return (
        <div className="space-y-6 max-w-5xl">
            <RegionSaveFailure failure={saveFailure.failure} onDismiss={saveFailure.clear} />

            {/* Card 1: Control Environment Nature */}
            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                    Control Environment Nature
                </h2>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    The operational nature of the control environment applies a baseline deduction to component efficiency scores.
                </p>

                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {[
                        { key: 'mainly_manual', label: '1. Mainly manual controls', desc: 'Deducts 10% from component efficiency scores.' },
                        { key: 'mixed', label: '2. Mix of manual & automated', desc: 'Deducts 5% from component efficiency scores.' },
                        { key: 'mainly_automated', label: '3. Mainly automated controls', desc: 'No environmental penalty applied (0% deduction).' },
                    ].map((opt) => (
                        <label
                            key={opt.key}
                            className={`flex flex-col justify-between rounded-lg border p-4 cursor-pointer transition-colors ${
                                nature === opt.key
                                    ? 'border-indigo-600 bg-indigo-50/50 dark:border-indigo-500 dark:bg-indigo-950/20'
                                    : 'border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50'
                            }`}
                        >
                            <div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="control_nature"
                                        value={opt.key}
                                        checked={nature === opt.key}
                                        disabled={!editable}
                                        onChange={() => handleNatureChange(opt.key)}
                                        className="text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="font-medium text-slate-900 dark:text-white">{opt.label}</span>
                                </div>
                                <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{opt.desc}</p>
                            </div>
                        </label>
                    ))}
                </div>
            </div>

            {/* Card 2: COSO Components Table & Global Score */}
            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-100 dark:border-slate-800 gap-4">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                            COSO Control Components
                        </h2>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Evaluate efficiency level (1–5) and weighting factor (1–5) for each component.
                        </p>
                    </div>
                    <div className="flex items-center gap-4 bg-slate-50 dark:bg-slate-800/60 px-4 py-2 rounded-lg border border-slate-200 dark:border-slate-700">
                        <div>
                            <span className="text-xs uppercase text-slate-400 font-semibold tracking-wider">Global Efficiency</span>
                            <div className="text-xl font-bold text-indigo-600 dark:text-indigo-400">
                                {scorecard?.global_score_formatted ?? '—'}
                            </div>
                        </div>
                        <div className="border-l border-slate-200 dark:border-slate-700 pl-4">
                            <span className="text-xs text-slate-400">Total Weight: <strong className="text-slate-700 dark:text-slate-200">{scorecard?.total_weight ?? '—'}</strong></span>
                            <br />
                            <span className="text-xs text-slate-400">Weighted Score: <strong className="text-slate-700 dark:text-slate-200">{scorecard?.total_weighted_score ?? '—'}</strong></span>
                        </div>
                    </div>
                </div>

                <div className="mt-4 overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800 text-sm">
                        <thead>
                            <tr className="text-left font-medium text-slate-500 dark:text-slate-400">
                                <th className="py-3 px-3">COSO Component</th>
                                <th className="py-3 px-3">Efficiency Level</th>
                                <th className="py-3 px-3 text-center">Adj. Score</th>
                                <th className="py-3 px-3">Weight Factor</th>
                                <th className="py-3 px-3 text-center">Weighted Score</th>
                                <th className="py-3 px-3">Notes & Evidence</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                            {components.map((comp) => (
                                <tr key={comp.component_code} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                    <td className="py-3 px-3 font-medium text-slate-900 dark:text-white whitespace-nowrap">
                                        {comp.component_name}
                                    </td>
                                    <td className="py-3 px-3 whitespace-nowrap">
                                        <select
                                            value={comp.efficiency_level}
                                            disabled={!editable}
                                            onChange={(e) => handleComponentChange(comp.component_code, 'efficiency_level', parseInt(e.target.value, 10))}
                                            className="rounded-md border-slate-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800"
                                        >
                                            <option value={0}>0. Unset</option>
                                            <option value={1}>1. Very low (0.20)</option>
                                            <option value={2}>2. Low (0.40)</option>
                                            <option value={3}>3. Medium (0.55)</option>
                                            <option value={4}>4. High (0.70)</option>
                                            <option value={5}>5. Very high (0.90)</option>
                                        </select>
                                    </td>
                                    <td className="py-3 px-3 text-center font-mono text-slate-600 dark:text-slate-300">
                                        {comp.adjusted_score}
                                    </td>
                                    <td className="py-3 px-3 whitespace-nowrap">
                                        <select
                                            value={comp.weighting_factor}
                                            disabled={!editable}
                                            onChange={(e) => handleComponentChange(comp.component_code, 'weighting_factor', parseInt(e.target.value, 10))}
                                            className="rounded-md border-slate-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800"
                                        >
                                            <option value={0}>0. Unset</option>
                                            <option value={1}>1. Very low (1)</option>
                                            <option value={2}>2. Low (2)</option>
                                            <option value={3}>3. Medium (3)</option>
                                            <option value={4}>4. High (4)</option>
                                            <option value={5}>5. Very high (5)</option>
                                        </select>
                                    </td>
                                    <td className="py-3 px-3 text-center font-mono font-medium text-slate-900 dark:text-white">
                                        {comp.weighted_score}
                                    </td>
                                    <td className="py-3 px-3">
                                        <input
                                            type="text"
                                            value={comp.notes || ''}
                                            disabled={!editable}
                                            placeholder="Optional evidence..."
                                            onChange={(e) => handleComponentChange(comp.component_code, 'notes', e.target.value)}
                                            className="w-full min-w-[160px] rounded-md border-slate-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800"
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {editable && (
                    <div className="mt-6 flex justify-end">
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving}
                            className="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {saving ? 'Saving...' : 'Save Scorecard'}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
