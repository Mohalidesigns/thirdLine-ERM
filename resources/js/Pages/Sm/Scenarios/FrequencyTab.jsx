import { useState, useEffect } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { RegionSaveFailure } from '@/Components/Sm/FailureBanner';
import useSaveFailure from '@/Components/Sm/useSaveFailure';

/**
 * Frequency Tab (spec 07, ADR 0027 Amendment 2 §A2.3 & §A2.6).
 *
 * λ = Count × (1 - ScorecardScore) × Probability
 * Frequency Interval = 1 / λ (years)
 */
export default function FrequencyTab({ scenario, frequency, can, urls, onDirtyChange }) {
    const editable = Boolean(can?.design) && !frequency?.is_read_only;
    const saveFailure = useSaveFailure({
        copySummary: () => drivers.map((d) => `${d.criterion}: ${d.value}`).join('\n'),
    });

    const scorecardScore = frequency?.scorecard_global_score !== null && frequency?.scorecard_global_score !== undefined
        ? parseFloat(frequency.scorecard_global_score)
        : null;

    const [drivers, setDrivers] = useState(frequency?.drivers || []);
    const [saving, setSaving] = useState(false);
    const [isDirty, setIsDirty] = useState(false);

    const pageErrors = usePage().props.errors || {};
    const driverErrors = Object.entries(pageErrors).filter(([key]) => key.startsWith('drivers'));
    const hasErrors = driverErrors.length > 0;

    useEffect(() => {
        // A refused save redirects back with the same server values; keep what the user typed.
        if (frequency?.drivers && !hasErrors) {
            setDrivers(frequency.drivers);
            setIsDirty(false);
        }
    }, [frequency, hasErrors]);

    useEffect(() => {
        onDirtyChange?.(editable && isDirty);
    }, [isDirty, editable, onDirtyChange]);

    const presets = frequency?.presets || [];
    const [presetKey, setPresetKey] = useState('');

    // A preset only fills in labels and sources (SM-2026-001, Bug 5). Values, driver keys,
    // roles and the scorecard link are left as they are, so λ does not move.
    const ROLE_ORDER = ['count', 'control_score_complement', 'probability'];
    const applyPreset = (key) => {
        const preset = presets.find((p) => p.value === key);
        if (!editable || !preset) return;
        setDrivers((prev) => prev.map((d) => {
            const template = preset.drivers[ROLE_ORDER.indexOf(d.computation_role)];
            if (!template) return d;
            const keepSource = d.computation_role === 'control_score_complement';
            return {
                ...d,
                criterion: template.criterion,
                data_source: keepSource ? d.data_source : template.data_source,
            };
        }));
        setPresetKey(key);
        setIsDirty(true);
    };

    const handleDriverChange = (index, field, val) => {
        if (!editable) return;
        setDrivers((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [field]: val };
            return next;
        });
        setIsDirty(true);
    };

    const handleToggleScorecardLink = (index) => {
        if (!editable) return;
        setDrivers((prev) => {
            const next = [...prev];
            const current = next[index];
            const willLink = !current.is_linked_to_scorecard;
            next[index] = {
                ...current,
                is_linked_to_scorecard: willLink,
                use_scorecard: willLink,
                value: willLink && scorecardScore !== null ? String(scorecardScore) : current.value,
                data_source: willLink ? 'Scorecard' : (current.data_source === 'Scorecard' ? 'Manual assessment' : current.data_source),
            };
            return next;
        });
        setIsDirty(true);
    };

    const handleSave = (e) => {
        e?.preventDefault();
        if (!editable || saving) return;

        setSaving(true);
        saveFailure.begin();

        const payload = {
            drivers: drivers.map((d) => ({
                driver_type: d.driver_type,
                criterion: d.criterion,
                computation_role: d.computation_role,
                value: d.value,
                data_source: d.data_source,
                use_scorecard: Boolean(d.is_linked_to_scorecard),
            })),
        };

        const targetUrl = urls?.frequency || `/risk/scenarios/${scenario.id}/frequency`;

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

    const scorecardTabUrl = typeof window !== 'undefined' ? `${window.location.pathname}?tab=scorecard` : '?tab=scorecard';

    return (
        <section aria-labelledby="frequency-heading" className="space-y-6 max-w-5xl">
            <RegionSaveFailure saveFailure={saveFailure} onResend={handleSave} />

            {hasErrors && (
                <div role="alert" className="rounded-lg border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">
                    <p className="font-semibold">Frequency drivers were not saved.</p>
                    <ul className="mt-1 list-disc pl-5">
                        {driverErrors.map(([key, message]) => <li key={key}>{message}</li>)}
                    </ul>
                </div>
            )}

            {/* Frequency Summary Banner */}
            <div className="bg-slate-900 border border-slate-800 rounded-lg p-6 shadow-sm text-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div>
                    <span className="text-xs font-semibold tracking-wider text-slate-400 uppercase">
                        Expected Annual Frequency (λ)
                    </span>
                    <div className="mt-1 flex items-baseline gap-2">
                        <span className="text-3xl font-extrabold text-white tracking-tight">
                            {frequency?.total_frequency ?? '0.0000'}
                        </span>
                        <span className="text-sm font-medium text-slate-400">events / year</span>
                    </div>
                </div>

                <div className="border-t md:border-t-0 md:border-l border-slate-800 pt-4 md:pt-0 md:pl-6">
                    <span className="text-xs font-semibold tracking-wider text-slate-400 uppercase">
                        Occurrence Interval (1 / λ)
                    </span>
                    <div className="mt-1 text-base font-semibold text-emerald-400">
                        {frequency?.interval_display ?? '—'}
                    </div>
                </div>
            </div>

            {/* Drivers Configuration Table */}
            <div className="bg-white border border-slate-200 rounded-lg shadow-sm">
                <div className="p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 id="frequency-heading" className="text-base font-semibold text-slate-900">
                            Frequency Drivers & Calibration
                        </h2>
                        <p className="mt-1 text-xs text-slate-500">
                            Expected event rate is formulated as{' '}
                            <code className="bg-slate-100 px-1 py-0.5 rounded text-slate-800 font-mono text-[11px]">
                                Count × (1 - ScorecardScore) × Probability
                            </code>.
                        </p>
                    </div>

                    {editable && (
                        <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                        {presets.length > 0 && (
                            <label className="flex items-center gap-2 text-xs text-slate-600">
                                <span className="font-medium">Driver labels</span>
                                <select
                                    value={presetKey}
                                    onChange={(e) => applyPreset(e.target.value)}
                                    className="text-xs border border-slate-300 rounded px-2 py-1 bg-white font-medium text-slate-800 focus:ring-1 focus:ring-indigo-500"
                                    aria-describedby="driver-preset-help"
                                >
                                    <option value="" disabled>Start from a preset…</option>
                                    {presets.map((p) => (
                                        <option key={p.value} value={p.value}>{p.label}</option>
                                    ))}
                                </select>
                                <span id="driver-preset-help" className="sr-only">
                                    Fills in the driver labels and sources only. Values are not changed.
                                </span>
                            </label>
                        )}
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving || !isDirty}
                            className={`inline-flex items-center justify-center px-4 py-2 rounded-md text-xs font-semibold transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 ${
                                isDirty
                                    ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                    : 'bg-slate-100 text-slate-400 cursor-not-allowed'
                            }`}
                        >
                            {saving ? 'Saving...' : 'Save Frequency'}
                        </button>
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead className="bg-slate-50 text-[11px] font-semibold text-slate-600 uppercase tracking-wider">
                            <tr>
                                <th scope="col" className="px-5 py-3">Driver / Criterion</th>
                                <th scope="col" className="px-4 py-3">Computation Role</th>
                                <th scope="col" className="px-4 py-3">Assessed Value</th>
                                <th scope="col" className="px-5 py-3">Provenance / Source</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700 text-xs">
                            {drivers.map((d, idx) => {
                                const isScorecardComplement = d.computation_role === 'control_score_complement';

                                return (
                                    <tr key={d.driver_type || idx} className="hover:bg-slate-50/60 transition-colors">
                                        <td className="px-5 py-3.5">
                                            {editable ? (
                                                <input
                                                    type="text"
                                                    value={d.criterion || ''}
                                                    maxLength={250}
                                                    onChange={(e) => handleDriverChange(idx, 'criterion', e.target.value)}
                                                    aria-label={`Label for the ${d.computation_role.replaceAll('_', ' ')} driver`}
                                                    className="w-full min-w-[14rem] border border-slate-300 rounded px-2 py-1 text-xs font-semibold text-slate-900 focus:ring-1 focus:ring-indigo-500"
                                                />
                                            ) : (
                                                <div className="font-semibold text-slate-900">{d.criterion}</div>
                                            )}
                                        </td>

                                        <td className="px-4 py-3.5">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-800">
                                                {d.computation_role}
                                            </span>
                                        </td>

                                        <td className="px-4 py-3.5">
                                            {isScorecardComplement ? (
                                                <div className="space-y-1.5">
                                                    {d.is_linked_to_scorecard ? (
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-semibold text-slate-900">
                                                                {scorecardScore !== null
                                                                    ? `${(scorecardScore * 100).toFixed(2)}%`
                                                                    : 'No scorecard evaluated'}
                                                            </span>
                                                            <span className="text-[10px] text-emerald-600 font-medium bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
                                                                Linked
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            max="1"
                                                            disabled={!editable}
                                                            value={d.value}
                                                            onChange={(e) => handleDriverChange(idx, 'value', e.target.value)}
                                                            className="w-28 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-slate-50 disabled:text-slate-500"
                                                        />
                                                    )}

                                                    {editable && (
                                                        <div className="flex items-center gap-2 text-[11px]">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleToggleScorecardLink(idx)}
                                                                className="text-indigo-600 hover:text-indigo-800 underline font-medium"
                                                            >
                                                                {d.is_linked_to_scorecard ? 'Use manual score' : 'Link to Scorecard'}
                                                            </button>
                                                            {scorecardScore === null && (
                                                                <Link
                                                                    href={scorecardTabUrl}
                                                                    className="text-slate-500 hover:text-slate-700 underline"
                                                                >
                                                                    Configure Scorecard
                                                                </Link>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            ) : (
                                                <div>
                                                    <input
                                                        type="number"
                                                        step={d.computation_role === 'probability' ? '0.01' : '1'}
                                                        min="0"
                                                        max={d.computation_role === 'probability' ? '1.0' : undefined}
                                                        disabled={!editable}
                                                        value={d.value}
                                                        onChange={(e) => handleDriverChange(idx, 'value', e.target.value)}
                                                        className="w-28 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-slate-50 disabled:text-slate-500 font-medium"
                                                    />
                                                </div>
                                            )}
                                        </td>

                                        <td className="px-5 py-3.5">
                                            {editable ? (
                                                <input
                                                    type="text"
                                                    value={d.data_source || ''}
                                                    disabled={isScorecardComplement && d.is_linked_to_scorecard}
                                                    onChange={(e) => handleDriverChange(idx, 'data_source', e.target.value)}
                                                    placeholder="Source (e.g. HR records)"
                                                    className="w-full max-w-xs px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-slate-50 disabled:text-slate-500"
                                                />
                                            ) : (
                                                <span className="text-slate-600">{d.data_source || '—'}</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    );
}

