import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import Figure from '@/Components/Quantification/Figure';
import ReportShell from '@/Components/Quantification/ReportShell';
import { NOT_ASSESSED, NOT_RECORDED, naira, percent, trimmedPercent } from '@/Components/Quantification/figures';

/**
 * Capital Adequacy Summary (migration Phase 5.2).
 *
 * Every figure comes from QuantificationReportService::capitalAdequacy() and
 * is pinned by Characterisation/QuantificationReportsCharacterisationTest.
 * Nothing is computed here — this page decides only how an absence looks.
 */
export default function CapitalAdequacy({ d, hasData }) {
    const money = (value, absent = NOT_RECORDED) => (
        <Figure value={value} formatted={naira(value)} absent={absent} />
    );

    return (
        <AuthenticatedLayout title="Capital Adequacy Summary">
            <Head title="Capital Adequacy Summary" />

            <ReportShell
                title="Capital Adequacy Summary"
                subtitle="Capital position with Pillar 1 requirement and Pillar 2 add-ons for CBN submission"
                asOf={d.as_of}
                notice={
                    hasData
                        ? null
                        : 'No ICAAP assessment has been recorded yet. Every figure below is sourced from an assessment, so the report stays blank rather than showing zeroes.'
                }
            >
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <KpiCard
                        title="Capital Adequacy Ratio"
                        value={percent(d.car_computed)}
                        unavailable={d.car_computed === null}
                        icon="shield"
                        color={d.car_computed !== null && d.car_computed >= d.car_required ? 'success' : 'danger'}
                        subtitle={`CBN minimum: ${trimmedPercent(d.car_required)}%`}
                    />
                    <KpiCard
                        title="Total Qualifying Capital"
                        value={naira(d.total_capital)}
                        unavailable={d.total_capital === null}
                        unavailableLabel={NOT_RECORDED}
                        icon="account_balance"
                        color="primary"
                    />
                    <KpiCard
                        title="CET1 Ratio"
                        value={percent(d.cet1_ratio)}
                        unavailable={d.cet1_ratio === null}
                        icon="verified_user"
                        color="success"
                        subtitle={`CET1 capital: ${naira(d.cet1) ?? 'not recorded'}`}
                    />
                    <KpiCard
                        title="Tier 1 Ratio"
                        value={percent(d.tier1_ratio)}
                        unavailable={d.tier1_ratio === null}
                        icon="workspace_premium"
                        color="info"
                        subtitle={`Tier 1 capital: ${naira(d.tier1) ?? 'not recorded'}`}
                    />
                </div>

                {d.car_variance_material && (
                    <div className="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 text-sm text-red-800">
                        <span className="font-semibold">CAR variance.</span> The ratio computed from stored capital and
                        RWA ({percent(d.car_computed)}) differs from the CAR recorded on the assessment (
                        {percent(d.car_reported)}) by {Math.abs(d.car_variance).toFixed(2)} percentage points. Both are
                        shown; neither is overwritten.
                    </div>
                )}

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Pillar 1 — Minimum Capital Requirement</h3>
                            <p className="text-xs text-gray-500 mt-1">Minimum CAR × total risk-weighted assets</p>
                        </header>
                        <table className="data-table">
                            <thead>
                                <tr><th>Input</th><th className="text-right">Value</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Total risk-weighted assets</td><td className="text-right">{money(d.total_rwa)}</td></tr>
                                <tr><td>Minimum CAR applied</td><td className="text-right">{trimmedPercent(d.car_required)}%</td></tr>
                                <tr className="font-semibold bg-gray-50">
                                    <td>Pillar 1 requirement</td>
                                    <td className="text-right">{money(d.pillar1_requirement, NOT_ASSESSED)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Pillar 2 — ICAAP Capital Add-on</h3>
                            <p className="text-xs text-gray-500 mt-1">As recorded on the assessment; nothing is apportioned</p>
                        </header>
                        <table className="data-table">
                            <thead>
                                <tr><th>Component</th><th className="text-right">Capital Charge</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Pillar 2A — Credit Risk</td><td className="text-right">{money(d.pillar2a_credit)}</td></tr>
                                <tr><td>Pillar 2A — Market Risk</td><td className="text-right">{money(d.pillar2a_market)}</td></tr>
                                <tr><td>Pillar 2A — Operational Risk</td><td className="text-right">{money(d.pillar2a_operational)}</td></tr>
                                <tr><td>Pillar 2A — Other</td><td className="text-right">{money(d.pillar2a_other)}</td></tr>
                                <tr className="font-semibold bg-gray-50">
                                    <td>Total Pillar 2A</td><td className="text-right">{money(d.total_pillar2a)}</td>
                                </tr>
                                <tr><td>Pillar 2B — Stress Buffer</td><td className="text-right">{money(d.pillar2b_buffer)}</td></tr>
                                <tr><td>Capital conservation buffer</td><td className="text-right">{trimmedPercent(d.conservation_buffer)}% of RWA</td></tr>
                            </tbody>
                        </table>
                    </section>
                </div>

                <section className="mt-6 bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Capital Position Summary</h3>
                    <dl className="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                        <div>
                            <dt className="text-xs text-gray-500">Total Qualifying Capital</dt>
                            <dd className="font-semibold text-[#1A365D]">{money(d.total_capital)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Pillar 1 Requirement</dt>
                            <dd className="font-semibold text-[#1A365D]">{money(d.pillar1_requirement, NOT_ASSESSED)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Pillar 2A Add-on</dt>
                            <dd className="font-semibold text-[#1A365D]">{money(d.total_pillar2a)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Pillar 2B Stress Buffer</dt>
                            <dd className="font-semibold text-[#1A365D]">{money(d.pillar2b_buffer)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Headroom</dt>
                            <dd
                                className={`font-semibold ${
                                    d.headroom === null ? 'text-gray-400' : d.headroom > 0 ? 'text-green-600' : 'text-red-600'
                                }`}
                            >
                                {money(d.headroom, NOT_ASSESSED)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">CAR vs CBN Minimum</dt>
                            <dd
                                className={`font-semibold ${
                                    d.car_surplus === null ? 'text-gray-400' : d.car_surplus >= 0 ? 'text-green-600' : 'text-red-600'
                                }`}
                            >
                                {d.car_surplus === null
                                    ? '—'
                                    : `${d.car_surplus >= 0 ? '+' : ''}${d.car_surplus.toFixed(2)}pp`}
                            </dd>
                        </div>
                    </dl>
                    <p className="text-xs text-gray-500 mt-4 leading-relaxed">
                        CAR, the CET1 ratio and the Tier 1 ratio are computed from stored capital and total
                        risk-weighted assets; each is shown as &quot;Not assessed&quot; rather than 0% when RWA is not
                        on file. Headroom is total qualifying capital less the Pillar 1 requirement, the Pillar 2A
                        add-on and the Pillar 2B stress buffer, and is withheld until all three are known. The minimum
                        CAR of {trimmedPercent(d.car_required)}% is resolved from the assessment, then the
                        organisation&apos;s quantification settings, then the CBN default of 10.0% — the previous code
                        read a <code className="text-xs">car_required</code> column that does not exist and therefore
                        showed every institution a 10% minimum. A bank on international authorisation or designated a
                        D-SIB is measured against 15.0% and must configure it.
                    </p>
                </section>
            </ReportShell>
        </AuthenticatedLayout>
    );
}
