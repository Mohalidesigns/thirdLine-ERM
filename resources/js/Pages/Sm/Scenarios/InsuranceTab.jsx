import { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import { RegionSaveFailure } from '@/Components/Sm/FailureBanner';
import useSaveFailure from '@/Components/Sm/useSaveFailure';

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

function formatPercent(decimal) {
    const val = parseFloat(decimal || 0);
    if (isNaN(val)) return '0.00%';
    return `${(val * 100).toFixed(2)}%`;
}

/**
 * Insurance Tab (spec 09, ADR 0027 Amendment 2 §A2.5 & §A2.6).
 *
 * Tiered 5-branch residual formula + dispute & credit haircuts.
 */
export default function InsuranceTab({ scenario, insurance, can, urls, onDirtyChange }) {
    const editable = Boolean(can?.design) && !insurance?.is_read_only;
    const saveFailure = useSaveFailure({
        copySummary: () => severities.map((s) => `${s}: ${coverages[s]?.limit_local || '0'}`).join('\n'),
    });

    const currency = insurance?.currency || scenario?.currency || 'NGN';
    const severities = insurance?.severities || ['typical', 'serious', 'extreme'];

    const [coverages, setCoverages] = useState(insurance?.coverages || {});
    const [saving, setSaving] = useState(false);
    const [isDirty, setIsDirty] = useState(false);

    // Validation errors for this tab. Before SM-2026-001 Bug 3 these were never rendered, so a
    // refused save looked exactly like a successful one.
    const pageErrors = usePage().props.errors || {};
    const insuranceErrors = Object.entries(pageErrors).filter(([key]) => key.startsWith('coverages'));
    const hasErrors = insuranceErrors.length > 0;

    useEffect(() => {
        // A refused save redirects back with the same server values; keep what the user typed.
        if (insurance?.coverages && !hasErrors) {
            setCoverages(insurance.coverages);
            setIsDirty(false);
        }
    }, [insurance, hasErrors]);


    useEffect(() => {
        onDirtyChange?.(editable && isDirty);
    }, [isDirty, editable, onDirtyChange]);

    const handleFieldChange = (severity, field, value) => {
        if (!editable) return;
        setCoverages((prev) => ({
            ...prev,
            [severity]: {
                ...prev[severity],
                [field]: value,
            },
        }));
        setIsDirty(true);
    };

    const handleSave = (e) => {
        e?.preventDefault();
        if (!editable || saving) return;

        setSaving(true);
        saveFailure.begin();

        // UpdateInsuranceRequest expects a LIST of rows each naming its severity. This used to
        // send an object keyed by severity, which validation refused on every save (Bug 3).
        const pick = (v, fallback) => (v === undefined || v === null || v === '' ? fallback : v);
        const payload = {
            coverages: severities.map((sev) => {
                const row = coverages[sev] || {};
                return {
                    severity: sev,
                    deductible_local: pick(row.deductible_local, '0'),
                    limit_local: pick(row.limit_local, '0'),
                    deductible_group: pick(row.deductible_group, '0'),
                    limit_group: pick(row.limit_group, '0'),
                    recovery_rate: pick(row.recovery_rate, '1'),
                    probability_of_cover: pick(row.probability_of_cover, '1'),
                    insurer_default_haircut: pick(row.insurer_default_haircut, '0'),
                };
            }),
        };

        const targetUrl = urls?.insurance || `/risk/scenarios/${scenario.id}/insurance`;

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
        <section aria-labelledby="insurance-heading" className="space-y-6 max-w-6xl">
            <RegionSaveFailure saveFailure={saveFailure} onResend={handleSave} />

            {hasErrors && (
                <div role="alert" className="rounded-lg border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">
                    <p className="font-semibold">Insurance terms were not saved.</p>
                    <ul className="mt-1 list-disc pl-5">
                        {insuranceErrors.map(([key, message]) => {
                            const m = key.match(/^coverages\.(\d+)\./);
                            const sev = m ? severities[Number(m[1])] : null;
                            return <li key={key}>{sev ? `${sev}: ` : ''}{message}</li>;
                        })}
                    </ul>
                </div>
            )}

            {/* Net vs Gross Impact Header */}
            <div className="bg-slate-900 border border-slate-800 rounded-lg p-6 shadow-sm text-slate-100">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-800 gap-2">
                    <div>
                        <span className="text-xs font-semibold tracking-wider text-slate-400 uppercase">
                            Net Impact After Insurance Mitigation
                        </span>
                        <h2 id="insurance-heading" className="text-sm text-slate-300 font-medium">
                            Gross loss netted against policy limits, retention deductibles & haircut risks
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
                            {saving ? 'Saving...' : 'Save Insurance Terms'}
                        </button>
                    )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-5">
                    {severities.map((sev) => {
                        const data = coverages[sev] || {};
                        const grossVal = parseFloat(data.gross_impact || 0) || 0;
                        const netVal = parseFloat(data.net_impact || 0) || 0;
                        const benefitVal = Math.max(0.0, grossVal - netVal);
                        const titleColor =
                            sev === 'extreme' ? 'text-rose-400' : (sev === 'serious' ? 'text-amber-400' : 'text-slate-300');

                        return (
                            <div key={sev} className="bg-slate-800/60 rounded-md p-4 border border-slate-700/60">
                                <span className={`text-xs font-semibold uppercase tracking-wider ${titleColor}`}>
                                    {sev} Severity
                                </span>
                                <div className="mt-2 space-y-1">
                                    <div className="flex justify-between items-baseline text-xs text-slate-400">
                                        <span>Gross Impact:</span>
                                        <span className="font-mono text-slate-300">{formatCurrency(data.gross_impact, currency)}</span>
                                    </div>
                                    <div className="flex justify-between items-baseline text-xs text-slate-400">
                                        <span>Recovery Benefit:</span>
                                        <span className="font-mono text-emerald-400">-{formatCurrency(benefitVal, currency)}</span>
                                    </div>
                                    <div className="pt-2 border-t border-slate-700 flex justify-between items-baseline">
                                        <span className="text-xs font-bold text-white">Net Retained:</span>
                                        <span className="text-lg font-bold text-white font-mono tracking-tight">
                                            {formatCurrency(data.net_impact, currency)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Insurance Policy Terms & Netting Matrix */}
            <div className="bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden">
                <div className="p-5 border-b border-slate-100">
                    <h3 className="text-base font-semibold text-slate-900">
                        Insurance Policy Structure & Multi-Tier Calibration
                    </h3>
                    <p className="mt-1 text-xs text-slate-500">
                        Configure local retention and group master policy layers along with insurer dispute and credit haircut rates.
                    </p>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-left text-xs">
                        <thead className="bg-slate-50 text-[11px] font-semibold text-slate-600 uppercase tracking-wider">
                            <tr>
                                <th scope="col" className="px-5 py-3">Policy Parameter</th>
                                <th scope="col" className="px-4 py-3 text-center">Typical</th>
                                <th scope="col" className="px-4 py-3 text-center">Serious</th>
                                <th scope="col" className="px-4 py-3 text-center">Extreme</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {/* Transferable Amount (Read Only from Impact) */}
                            <tr className="bg-slate-50/50">
                                <td className="px-5 py-3 font-semibold text-slate-900">
                                    Transferable Loss Amount (from Impact)
                                    <div className="text-[11px] font-normal text-slate-400">Sum of impact categories eligible to transfer</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center font-mono font-semibold text-slate-800">
                                        {formatCurrency(coverages[sev]?.transferable_amount, currency)}
                                    </td>
                                ))}
                            </tr>

                            {/* Local Deductible */}
                            <tr>
                                <td className="px-5 py-3">
                                    <div className="font-semibold text-slate-900">Local Retention / Deductible</div>
                                    <div className="text-[11px] text-slate-400">Amount retained before local insurance responds</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center">
                                        {editable ? (
                                            <input
                                                type="number"
                                                step="any"
                                                value={coverages[sev]?.deductible_local || ''}
                                                onChange={(e) => handleFieldChange(sev, 'deductible_local', e.target.value)}
                                                className="w-32 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono text-center"
                                            />
                                        ) : (
                                            <span className="font-mono">{formatCurrency(coverages[sev]?.deductible_local, currency)}</span>
                                        )}
                                    </td>
                                ))}
                            </tr>

                            {/* Local Limit */}
                            <tr>
                                <td className="px-5 py-3">
                                    <div className="font-semibold text-slate-900">Local Policy Limit</div>
                                    <div className="text-[11px] text-slate-400">Maximum coverage cap of local entity policy</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center">
                                        {editable ? (
                                            <input
                                                type="number"
                                                step="any"
                                                value={coverages[sev]?.limit_local || ''}
                                                onChange={(e) => handleFieldChange(sev, 'limit_local', e.target.value)}
                                                className="w-32 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono text-center"
                                            />
                                        ) : (
                                            <span className="font-mono">{formatCurrency(coverages[sev]?.limit_local, currency)}</span>
                                        )}
                                    </td>
                                ))}
                            </tr>

                            {/* Group Deductible */}
                            <tr>
                                <td className="px-5 py-3">
                                    <div className="font-semibold text-slate-900">Group Umbrella Deductible</div>
                                    <div className="text-[11px] text-slate-400">Group policy attachment point</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center">
                                        {editable ? (
                                            <input
                                                type="number"
                                                step="any"
                                                value={coverages[sev]?.deductible_group || ''}
                                                onChange={(e) => handleFieldChange(sev, 'deductible_group', e.target.value)}
                                                className="w-32 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono text-center"
                                            />
                                        ) : (
                                            <span className="font-mono">{formatCurrency(coverages[sev]?.deductible_group, currency)}</span>
                                        )}
                                    </td>
                                ))}
                            </tr>

                            {/* Group Limit */}
                            <tr>
                                <td className="px-5 py-3">
                                    <div className="font-semibold text-slate-900">Group Umbrella Limit</div>
                                    <div className="text-[11px] text-slate-400">Maximum group master policy coverage</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center">
                                        {editable ? (
                                            <input
                                                type="number"
                                                step="any"
                                                value={coverages[sev]?.limit_group || ''}
                                                onChange={(e) => handleFieldChange(sev, 'limit_group', e.target.value)}
                                                className="w-32 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono text-center"
                                            />
                                        ) : (
                                            <span className="font-mono">{formatCurrency(coverages[sev]?.limit_group, currency)}</span>
                                        )}
                                    </td>
                                ))}
                            </tr>

                            {/* Recovery Rate */}
                            <tr className="bg-slate-50/30">
                                <td className="px-5 py-3">
                                    <div className="font-semibold text-slate-900">Expected Recovery Rate (0 to 1)</div>
                                    <div className="text-[11px] text-slate-400">Anticipated settlement percentage of valid claim</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center">
                                        {editable ? (
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="1"
                                                value={coverages[sev]?.recovery_rate || ''}
                                                onChange={(e) => handleFieldChange(sev, 'recovery_rate', e.target.value)}
                                                className="w-24 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono text-center"
                                            />
                                        ) : (
                                            <span className="font-mono">{formatPercent(coverages[sev]?.recovery_rate)}</span>
                                        )}
                                    </td>
                                ))}
                            </tr>

                            {/* Probability of Cover */}
                            <tr className="bg-slate-50/30">
                                <td className="px-5 py-3">
                                    <div className="font-semibold text-slate-900">Probability of Coverage (0 to 1)</div>
                                    <div className="text-[11px] text-slate-400">1 minus probability of policy dispute or exclusion</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center">
                                        {editable ? (
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="1"
                                                value={coverages[sev]?.probability_of_cover || ''}
                                                onChange={(e) => handleFieldChange(sev, 'probability_of_cover', e.target.value)}
                                                className="w-24 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono text-center"
                                            />
                                        ) : (
                                            <span className="font-mono">{formatPercent(coverages[sev]?.probability_of_cover)}</span>
                                        )}
                                    </td>
                                ))}
                            </tr>

                            {/* Default Haircut */}
                            <tr className="bg-slate-50/30">
                                <td className="px-5 py-3">
                                    <div className="font-semibold text-slate-900">Insurer Credit Default Haircut (0 to 1)</div>
                                    <div className="text-[11px] text-slate-400">Haircut allowance for insurer insolvency risk</div>
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center">
                                        {editable ? (
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="1"
                                                value={coverages[sev]?.insurer_default_haircut || ''}
                                                onChange={(e) => handleFieldChange(sev, 'insurer_default_haircut', e.target.value)}
                                                className="w-24 px-2 py-1 text-xs border border-slate-300 rounded focus:ring-1 focus:ring-indigo-500 font-mono text-center"
                                            />
                                        ) : (
                                            <span className="font-mono">{formatPercent(coverages[sev]?.insurer_default_haircut)}</span>
                                        )}
                                    </td>
                                ))}
                            </tr>

                            {/* Computed Residual */}
                            <tr className="bg-slate-50/70 border-t-2 border-slate-200">
                                <td className="px-5 py-3 font-semibold text-slate-800">
                                    Tiered Residual Retained
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center font-mono font-semibold text-slate-700">
                                        {formatCurrency(coverages[sev]?.residual_amount, currency)}
                                    </td>
                                ))}
                            </tr>

                            {/* Haircut Impact */}
                            <tr className="bg-slate-50/70">
                                <td className="px-5 py-3 font-semibold text-slate-800">
                                    Haircut Impact (Dispute & Credit)
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3 text-center font-mono font-semibold text-rose-700">
                                        +{formatCurrency(coverages[sev]?.haircut_impact, currency)}
                                    </td>
                                ))}
                            </tr>

                            {/* Final Net Impact */}
                            <tr className="bg-indigo-50/50 border-t border-indigo-100">
                                <td className="px-5 py-3.5 font-bold text-indigo-950">
                                    Net Financial Impact Retained
                                </td>
                                {severities.map((sev) => (
                                    <td key={sev} className="px-4 py-3.5 text-center font-mono font-bold text-sm text-indigo-950">
                                        {formatCurrency(coverages[sev]?.net_impact, currency)}
                                    </td>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    );
}
