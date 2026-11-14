import { useState, useEffect, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
import Icon from '@thirdline/ui/Components/Icon';

export default function DeckView({ scenario, variants, gap_actions, immediate_actions, quantitative, urls }) {
    const [currentSlide, setCurrentSlide] = useState(0);
    const totalSlides = 6;

    const nextSlide = useCallback(() => {
        setCurrentSlide((curr) => (curr < totalSlides - 1 ? curr + 1 : curr));
    }, [totalSlides]);

    const prevSlide = useCallback(() => {
        setCurrentSlide((curr) => (curr > 0 ? curr - 1 : curr));
    }, []);

    // Keyboard navigation
    useEffect(() => {
        const handleKeyDown = (e) => {
            if (e.key === 'ArrowRight' || e.key === 'Space') {
                nextSlide();
            } else if (e.key === 'ArrowLeft') {
                prevSlide();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [nextSlide, prevSlide]);

    const slideTitles = [
        'Executive Cover',
        'Scenario Context & Baseline',
        'Escalating Scenario Tiers (S1/S2/S3)',
        'Possible Risk & Resolution Plan',
        'Immediate Action Roadmap',
        'Quantitative Loss & Frequency Recap',
    ];

    const formatCurrency = (val) => {
        if (!val || val === '0.00' || val === '0') return '—';
        const num = parseFloat(val);
        if (isNaN(num)) return val;
        return `${scenario.currency || 'NGN'} ${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    return (
        <div className="min-h-screen bg-slate-900 text-slate-100 flex flex-col font-sans select-none">
            <Head title={`Deck: ${scenario.reference} - ${scenario.title}`} />

            {/* Deck Top Bar (hidden during print) */}
            <header className="print:hidden border-b border-slate-800 bg-slate-950/80 backdrop-blur px-6 py-3 flex items-center justify-between z-20">
                <div className="flex items-center gap-4">
                    <Link
                        href={urls.workspace}
                        className="text-xs text-slate-400 hover:text-white flex items-center gap-1.5 transition-colors"
                    >
                        <Icon name="arrow-left" className="w-4 h-4" />
                        Back to Scenario Workspace
                    </Link>
                    <span className="text-slate-700">|</span>
                    <span className="text-xs font-mono font-semibold text-amber-400">
                        {scenario.reference}
                    </span>
                    <span className="text-xs text-slate-300 truncate max-w-md">
                        {scenario.title}
                    </span>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="text-xs bg-slate-800 hover:bg-slate-700 text-slate-200 px-3 py-1.5 rounded flex items-center gap-1.5 transition-colors"
                    >
                        <Icon name="printer" className="w-3.5 h-3.5" />
                        Print / Export PDF
                    </button>
                    <div className="flex items-center gap-1 bg-slate-800 rounded p-1">
                        <button
                            type="button"
                            onClick={prevSlide}
                            disabled={currentSlide === 0}
                            className="p-1 text-slate-400 hover:text-white disabled:opacity-30 disabled:hover:text-slate-400 rounded"
                            title="Previous slide (Left Arrow)"
                        >
                            <Icon name="chevron-left" className="w-4 h-4" />
                        </button>
                        <span className="px-2 text-xs font-mono text-slate-300">
                            {currentSlide + 1} / {totalSlides}
                        </span>
                        <button
                            type="button"
                            onClick={nextSlide}
                            disabled={currentSlide === totalSlides - 1}
                            className="p-1 text-slate-400 hover:text-white disabled:opacity-30 disabled:hover:text-slate-400 rounded"
                            title="Next slide (Right Arrow)"
                        >
                            <Icon name="chevron-right" className="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </header>

            {/* Presentation Stage */}
            <main className="flex-1 flex items-center justify-center p-4 sm:p-8 overflow-y-auto">
                <div className="w-full max-w-6xl aspect-[16/9] bg-slate-950 rounded-xl border border-slate-800 shadow-2xl p-8 sm:p-12 flex flex-col justify-between relative overflow-hidden print:border-none print:shadow-none print:aspect-auto print:max-w-none print:w-full print:p-0">
                    
                    {/* Watermark branding */}
                    <div className="absolute top-6 right-8 text-xs font-mono tracking-widest text-slate-700 uppercase flex items-center gap-2">
                        <span>Enterprise Risk Management</span>
                        <span className="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                        <span>Scenario Analysis Deck</span>
                    </div>

                    {/* SLIDE 0: Executive Cover */}
                    {currentSlide === 0 && (
                        <div className="flex-1 flex flex-col justify-center">
                            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs font-semibold uppercase tracking-wider w-max mb-6">
                                <span>Scenario Modelling & Stress Test</span>
                                <span>•</span>
                                <span>{scenario.status_label}</span>
                            </div>

                            <h1 className="text-4xl sm:text-5xl font-extrabold tracking-tight text-white mb-4 leading-tight">
                                {scenario.title}
                            </h1>

                            <p className="text-lg text-slate-400 max-w-3xl mb-8 leading-relaxed">
                                {scenario.background || scenario.rationale || 'Operational resilience assessment, escalating event tiers, gap resolution plan, and immediate tactical horizons.'}
                            </p>

                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-6 border-t border-slate-800/80 pt-6 mt-4">
                                <div>
                                    <div className="text-xs uppercase font-medium text-slate-500">Scenario Ref</div>
                                    <div className="text-sm font-mono font-bold text-amber-400 mt-1">{scenario.reference}</div>
                                </div>
                                <div>
                                    <div className="text-xs uppercase font-medium text-slate-500">Business Unit</div>
                                    <div className="text-sm font-semibold text-slate-200 mt-1">{scenario.business_unit || 'Enterprise-wide'}</div>
                                </div>
                                <div>
                                    <div className="text-xs uppercase font-medium text-slate-500">Event Classification</div>
                                    <div className="text-sm font-semibold text-slate-200 mt-1">{scenario.taxonomy_node || 'Operational Risk'}</div>
                                </div>
                                <div>
                                    <div className="text-xs uppercase font-medium text-slate-500">Review Due</div>
                                    <div className="text-sm font-semibold text-slate-200 mt-1">{scenario.review_due_on || 'Not scheduled'}</div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* SLIDE 1: Context & Operational Scope */}
                    {currentSlide === 1 && (
                        <div className="flex-1 flex flex-col justify-start">
                            <div className="mb-6">
                                <span className="text-xs font-mono text-amber-400 uppercase tracking-widest">Section 1 · Qualitative Context</span>
                                <h2 className="text-3xl font-bold text-white mt-1">Operational Baseline & Stress Narrative</h2>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 flex-1">
                                <div className="bg-slate-900/60 p-6 rounded-lg border border-slate-800 flex flex-col justify-between">
                                    <div>
                                        <h3 className="text-xs uppercase tracking-wider font-semibold text-slate-400 mb-3">
                                            Scenario Background & Context
                                        </h3>
                                        <div className="text-sm text-slate-300 space-y-3 leading-relaxed">
                                            {scenario.background ? (
                                                <p>{scenario.background}</p>
                                            ) : (
                                                <p className="text-slate-500 italic">No detailed background recorded.</p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-6 pt-4 border-t border-slate-800 text-xs text-slate-400 flex items-center justify-between">
                                        <span>Governance: Designed on {scenario.designed_on || '—'}</span>
                                        <span>Validated: {scenario.validated_on || '—'}</span>
                                    </div>
                                </div>

                                <div className="bg-slate-900/60 p-6 rounded-lg border border-slate-800 flex flex-col justify-between">
                                    <div>
                                        <h3 className="text-xs uppercase tracking-wider font-semibold text-slate-400 mb-3">
                                            Stress Test Rationale & Threat Vector
                                        </h3>
                                        <div className="text-sm text-slate-300 space-y-3 leading-relaxed">
                                            {scenario.rationale ? (
                                                <p>{scenario.rationale}</p>
                                            ) : (
                                                <p className="text-slate-500 italic">No stress testing rationale recorded.</p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-6 pt-4 border-t border-slate-800 text-xs text-slate-400 flex items-center justify-between">
                                        <span>Nature: {scenario.nature || 'Custom'}</span>
                                        <span>Base Currency: {scenario.currency}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* SLIDE 2: Escalating Scenario Tiers */}
                    {currentSlide === 2 && (
                        <div className="flex-1 flex flex-col justify-start">
                            <div className="mb-6 flex items-center justify-between">
                                <div>
                                    <span className="text-xs font-mono text-amber-400 uppercase tracking-widest">Section 2 · Variant Decks</span>
                                    <h2 className="text-3xl font-bold text-white mt-1">Escalating Event Severity Matrix</h2>
                                </div>
                                <div className="text-xs text-slate-400">
                                    Three severity thresholds (S1 / S2 / S3)
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 flex-1">
                                {(variants || []).map((v) => {
                                    const tierColors = {
                                        tier_1: 'border-blue-500/40 bg-blue-950/20 text-blue-400',
                                        tier_2: 'border-amber-500/40 bg-amber-950/20 text-amber-400',
                                        tier_3: 'border-red-500/40 bg-red-950/20 text-red-400',
                                    }[v.tier] || 'border-slate-800 bg-slate-900 text-slate-300';

                                    const badgeLabel = {
                                        tier_1: 'S1 · Low Severity',
                                        tier_2: 'S2 · Medium Severity',
                                        tier_3: 'S3 · High Severity',
                                    }[v.tier] || v.tier;

                                    return (
                                        <div key={v.tier} className="bg-slate-900/80 rounded-lg border border-slate-800 p-5 flex flex-col justify-between">
                                            <div>
                                                <div className="flex items-center justify-between mb-3">
                                                    <span className={`px-2 py-0.5 rounded text-xs font-bold border ${tierColors}`}>
                                                        {badgeLabel}
                                                    </span>
                                                    {v.is_cumulative && (
                                                        <span className="text-[10px] text-slate-400 uppercase tracking-wider font-mono">
                                                            Cumulative +
                                                        </span>
                                                    )}
                                                </div>

                                                <h4 className="text-sm font-bold text-white mb-2">{v.name}</h4>
                                                <p className="text-xs text-slate-400 mb-4 line-clamp-3 leading-relaxed">{v.narrative || '—'}</p>

                                                {/* Triggers */}
                                                <div className="mb-3">
                                                    <div className="text-[11px] font-semibold text-slate-300 uppercase tracking-wider mb-1">
                                                        Trigger Points:
                                                    </div>
                                                    <ul className="text-xs text-slate-400 list-disc list-inside space-y-0.5">
                                                        {(v.triggers || []).slice(0, 3).map((t, idx) => (
                                                            <li key={idx} className="truncate">{t}</li>
                                                        ))}
                                                        {(v.triggers || []).length === 0 && <li className="italic text-slate-600">None</li>}
                                                    </ul>
                                                </div>

                                                {/* Impacts */}
                                                <div className="mb-3">
                                                    <div className="text-[11px] font-semibold text-slate-300 uppercase tracking-wider mb-1">
                                                        Operational Impacts:
                                                    </div>
                                                    <ul className="text-xs text-slate-400 list-disc list-inside space-y-0.5">
                                                        {(v.operational_impacts || []).slice(0, 3).map((imp, idx) => (
                                                            <li key={idx} className="truncate">{imp}</li>
                                                        ))}
                                                        {(v.operational_impacts || []).length === 0 && <li className="italic text-slate-600">None</li>}
                                                    </ul>
                                                </div>
                                            </div>

                                            {/* Responses & Owners */}
                                            <div className="pt-3 border-t border-slate-800/80">
                                                <div className="text-[11px] font-semibold text-amber-400 uppercase tracking-wider mb-1">
                                                    Target Response & Ownership:
                                                </div>
                                                <div className="text-xs text-slate-300 line-clamp-2">
                                                    {(v.responses || []).join(', ') || 'No responses defined.'}
                                                </div>
                                                <div className="mt-1 text-[10px] text-slate-400 font-mono">
                                                    Lead: {(v.response_owners || []).join(', ') || 'Not assigned'}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* SLIDE 3: Possible Risk & Resolution Plan */}
                    {currentSlide === 3 && (
                        <div className="flex-1 flex flex-col justify-start">
                            <div className="mb-6 flex items-center justify-between">
                                <div>
                                    <span className="text-xs font-mono text-amber-400 uppercase tracking-widest">Section 3 · Vulnerabilities & Actions</span>
                                    <h2 className="text-3xl font-bold text-white mt-1">Possible Risk & Resolution Plan (Gap Remediation)</h2>
                                </div>
                                <div className="text-xs text-slate-400">
                                    {gap_actions.length} Identified Gaps
                                </div>
                            </div>

                            <div className="bg-slate-900/80 rounded-lg border border-slate-800 overflow-hidden flex-1 flex flex-col">
                                {gap_actions.length === 0 ? (
                                    <div className="flex-1 flex items-center justify-center text-sm text-slate-500 italic">
                                        No gap remediation actions registered in this scenario.
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto flex-1">
                                        <table className="min-w-full divide-y divide-slate-800 text-left text-xs">
                                            <thead className="bg-slate-900 text-slate-400 font-semibold uppercase tracking-wider">
                                                <tr>
                                                    <th className="px-4 py-3">Risk Area</th>
                                                    <th className="px-4 py-3">Current Position</th>
                                                    <th className="px-4 py-3 text-red-400">Identified Gap</th>
                                                    <th className="px-4 py-3">Action Resolution Plan</th>
                                                    <th className="px-4 py-3">Owner</th>
                                                    <th className="px-4 py-3">Target Due</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-800/60 text-slate-300">
                                                {gap_actions.slice(0, 6).map((item) => (
                                                    <tr key={item.id} className="hover:bg-slate-800/40">
                                                        <td className="px-4 py-2.5 font-bold text-white whitespace-nowrap">
                                                            {item.risk_area}
                                                            {item.issue_reference && (
                                                                <span className="ml-2 px-1.5 py-0.5 rounded text-[10px] bg-purple-900/60 text-purple-300 border border-purple-700">
                                                                    #{item.issue_reference}
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-slate-400 max-w-xs truncate">{item.current_position || '—'}</td>
                                                        <td className="px-4 py-2.5 text-red-300 max-w-xs truncate">{item.identified_gap || '—'}</td>
                                                        <td className="px-4 py-2.5 text-slate-200 max-w-xs truncate">{item.action_plan}</td>
                                                        <td className="px-4 py-2.5 text-slate-400 whitespace-nowrap">{item.owner_name || '—'}</td>
                                                        <td className="px-4 py-2.5 text-slate-400 whitespace-nowrap">{item.due_date || '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* SLIDE 4: Immediate Action Roadmap */}
                    {currentSlide === 4 && (
                        <div className="flex-1 flex flex-col justify-start">
                            <div className="mb-6 flex items-center justify-between">
                                <div>
                                    <span className="text-xs font-mono text-amber-400 uppercase tracking-widest">Section 4 · Phased Milestones</span>
                                    <h2 className="text-3xl font-bold text-white mt-1">Immediate Action Roadmap (Day Horizons)</h2>
                                </div>
                                <div className="text-xs text-slate-400">
                                    {immediate_actions.length} Horizon Actions
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 flex-1">
                                {['today', 'within_7_days', 'within_30_days'].map((hKey) => {
                                    const hTitle = {
                                        today: 'Today (Immediate 24h)',
                                        within_7_days: 'Within 7 Days',
                                        within_30_days: 'Within 30 Days',
                                    }[hKey];

                                    const hActions = immediate_actions.filter((a) => a.time_horizon === hKey);

                                    return (
                                        <div key={hKey} className="bg-slate-900/80 rounded-lg border border-slate-800 p-5 flex flex-col">
                                            <div className="flex items-center justify-between pb-3 border-b border-slate-800 mb-3">
                                                <h4 className="text-sm font-bold text-amber-400 uppercase tracking-wider">{hTitle}</h4>
                                                <span className="text-xs font-mono text-slate-400">{hActions.length} items</span>
                                            </div>

                                            <div className="flex-1 overflow-y-auto space-y-3">
                                                {hActions.length === 0 ? (
                                                    <p className="text-xs text-slate-600 italic">No actions scheduled for this horizon.</p>
                                                ) : (
                                                    hActions.map((act) => (
                                                        <div key={act.id} className="p-3 bg-slate-950/60 rounded border border-slate-800 text-xs">
                                                            <div className="font-semibold text-slate-200">{act.risk_area}</div>
                                                            <div className="mt-1 text-slate-400 leading-snug">{act.action_plan}</div>
                                                            <div className="mt-2 pt-2 border-t border-slate-800/80 flex items-center justify-between text-[11px] text-slate-500">
                                                                <span>Lead: {act.owner_name || 'Unassigned'}</span>
                                                                <span className={act.status === 'completed' ? 'text-green-400 font-semibold' : 'text-slate-400'}>
                                                                    {act.status_label}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    ))
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* SLIDE 5: Quantitative Loss & Frequency Recap */}
                    {currentSlide === 5 && (
                        <div className="flex-1 flex flex-col justify-start">
                            <div className="mb-6 flex items-center justify-between">
                                <div>
                                    <span className="text-xs font-mono text-amber-400 uppercase tracking-widest">Section 5 · Quantitative Exposure</span>
                                    <h2 className="text-3xl font-bold text-white mt-1">Quantitative Loss Recap & Recurrence Interval</h2>
                                </div>
                                <div className="text-xs text-slate-400 font-mono">
                                    Currency: {scenario.currency}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 flex-1">
                                {/* Frequency block */}
                                <div className="bg-slate-900/80 p-6 rounded-lg border border-slate-800 flex flex-col justify-between">
                                    <div>
                                        <h3 className="text-xs uppercase font-semibold text-slate-400 tracking-wider mb-4">
                                            Frequency Assessment & Return Period
                                        </h3>
                                        
                                        <div className="grid grid-cols-2 gap-4 my-6">
                                            <div className="p-4 bg-slate-950 rounded-lg border border-slate-800">
                                                <div className="text-xs text-slate-500 uppercase font-mono">Annual Rate (λ)</div>
                                                <div className="text-3xl font-extrabold text-amber-400 mt-2 font-mono">
                                                    {quantitative.frequency_lambda}
                                                </div>
                                                <div className="text-[11px] text-slate-400 mt-1">Expected events per year</div>
                                            </div>

                                            <div className="p-4 bg-slate-950 rounded-lg border border-slate-800">
                                                <div className="text-xs text-slate-500 uppercase font-mono">Return Period</div>
                                                <div className="text-3xl font-extrabold text-blue-400 mt-2 font-mono">
                                                    {quantitative.recurrence_interval_years ? `${quantitative.recurrence_interval_years} yrs` : '—'}
                                                </div>
                                                <div className="text-[11px] text-slate-400 mt-1">1 in X years recurrence</div>
                                            </div>
                                        </div>

                                        <p className="text-xs text-slate-400 leading-relaxed">
                                            Calculated from baseline driver frequency multiplied by the weighted institutional scorecard factor.
                                        </p>
                                    </div>

                                    <div className="text-xs text-slate-500 font-mono">
                                        Poisson distribution assumption · Basel framework compliant
                                    </div>
                                </div>

                                {/* Loss severity curves */}
                                <div className="bg-slate-900/80 p-6 rounded-lg border border-slate-800 flex flex-col justify-between">
                                    <div>
                                        <h3 className="text-xs uppercase font-semibold text-slate-400 tracking-wider mb-4">
                                            Gross vs Net Insured Loss Potential
                                        </h3>

                                        <div className="space-y-4 my-4">
                                            <div className="p-3 bg-slate-950 rounded border border-slate-800 flex items-center justify-between">
                                                <div>
                                                    <div className="text-xs font-bold text-slate-300">Typical Severity</div>
                                                    <div className="text-[11px] text-slate-500">Gross: {formatCurrency(quantitative.typical_gross)}</div>
                                                </div>
                                                <div className="text-right">
                                                    <div className="text-xs font-mono font-bold text-emerald-400">Net: {formatCurrency(quantitative.typical_net)}</div>
                                                </div>
                                            </div>

                                            <div className="p-3 bg-slate-950 rounded border border-slate-800 flex items-center justify-between">
                                                <div>
                                                    <div className="text-xs font-bold text-slate-300">Serious Severity</div>
                                                    <div className="text-[11px] text-slate-500">Gross: {formatCurrency(quantitative.serious_gross)}</div>
                                                </div>
                                                <div className="text-right">
                                                    <div className="text-xs font-mono font-bold text-amber-400">Net: {formatCurrency(quantitative.serious_net)}</div>
                                                </div>
                                            </div>

                                            <div className="p-3 bg-slate-950 rounded border border-slate-800 flex items-center justify-between">
                                                <div>
                                                    <div className="text-xs font-bold text-slate-300">Extreme Severity (Tail)</div>
                                                    <div className="text-[11px] text-slate-500">Gross: {formatCurrency(quantitative.extreme_gross)}</div>
                                                </div>
                                                <div className="text-right">
                                                    <div className="text-xs font-mono font-bold text-red-400">Net: {formatCurrency(quantitative.extreme_net)}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="text-xs text-slate-500 font-mono">
                                        Gross impact less deductible and policy limits coverage
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Footer Slide Navigation Bar */}
                    <div className="border-t border-slate-800/80 pt-4 mt-6 flex items-center justify-between text-xs text-slate-500">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-slate-400 font-medium">
                                Slide {currentSlide + 1} of {totalSlides}:
                            </span>
                            <span className="text-slate-300 font-semibold">
                                {slideTitles[currentSlide]}
                            </span>
                        </div>

                        {/* Slide dots */}
                        <div className="flex items-center gap-1.5">
                            {slideTitles.map((title, idx) => (
                                <button
                                    key={idx}
                                    type="button"
                                    onClick={() => setCurrentSlide(idx)}
                                    title={title}
                                    className={`w-2.5 h-2.5 rounded-full transition-all ${
                                        idx === currentSlide
                                            ? 'bg-amber-400 w-6'
                                            : 'bg-slate-700 hover:bg-slate-500'
                                    }`}
                                />
                            ))}
                        </div>
                    </div>

                </div>
            </main>
        </div>
    );
}

