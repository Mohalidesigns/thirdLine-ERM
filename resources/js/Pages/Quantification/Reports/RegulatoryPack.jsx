import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import ReportShell from '@/Components/Quantification/ReportShell';
import { NOT_ASSESSED, NOT_RECORDED, naira, number, percent } from '@/Components/Quantification/figures';

const STATUS_CLASSES = {
    pass: 'bg-green-100 text-green-700',
    warning: 'bg-yellow-100 text-yellow-700',
    fail: 'bg-red-100 text-red-700',
};

const STATUS_ICONS = {
    pass: 'check_circle',
    warning: 'warning',
    fail: 'error',
};

/** Regulatory Compliance Pack (migration Phase 5.2). */
export default function RegulatoryPack({ summary, checklist }) {
    const today = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

    return (
        <AuthenticatedLayout title="Regulatory Compliance Pack">
            <Head title="Regulatory Compliance Pack" />

            <ReportShell
                title="Regulatory Compliance Pack"
                subtitle={`Combined regulatory reporting package for CBN ORMS compliance · ${today}`}
            >
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    {/*
                        WP-08: CAR is computed from capital / RWA where both are on file, and is
                        null — "Not assessed" — where they are not. It is never shown as 0%, which
                        would read as an insolvent bank rather than as missing data.
                    */}
                    <KpiCard
                        title="Capital Adequacy Ratio"
                        value={percent(summary.car_actual)}
                        unavailable={summary.car_actual === null}
                        unavailableLabel={NOT_ASSESSED}
                        icon="shield"
                        color={
                            summary.car_actual === null
                                ? 'info'
                                : summary.car_actual >= summary.car_required
                                  ? 'success'
                                  : 'danger'
                        }
                        subtitle={`CBN minimum: ${percent(summary.car_required)}${
                            summary.car_actual === null ? '' : ` · ${summary.car_basis}`
                        }`}
                    />
                    <KpiCard
                        title="Total Capital"
                        value={naira(summary.total_capital)}
                        unavailable={summary.total_capital === null}
                        unavailableLabel={NOT_RECORDED}
                        icon="account_balance"
                        color="primary"
                    />
                    <KpiCard
                        title="Active Risks"
                        value={number(summary.active_risks)}
                        icon="security"
                        color="primary"
                        subtitle={`${summary.critical_risks} critical / ${summary.high_risks} high`}
                    />
                    <KpiCard
                        title="Loss Events YTD"
                        value={number(summary.loss_events_ytd)}
                        icon="report_problem"
                        color="warning"
                        subtitle={`Net loss ${naira(summary.net_loss_ytd)}`}
                    />
                </div>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Compliance Checklist</h3>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr><th>Item</th><th>Status</th><th>Detail</th></tr>
                            </thead>
                            <tbody>
                                {checklist.map((row) => (
                                    <tr key={row.item}>
                                        <td className="font-medium text-[#1A365D]">{row.item}</td>
                                        <td>
                                            <span
                                                className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold ${STATUS_CLASSES[row.status]}`}
                                            >
                                                <span className="material-symbols-outlined text-[14px]">
                                                    {STATUS_ICONS[row.status]}
                                                </span>
                                                {row.status.toUpperCase()}
                                            </span>
                                        </td>
                                        <td className="text-sm text-gray-600">{row.detail}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Key Risk Indicators</h3>
                        <dl className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="text-xs text-gray-500">Red (Breach)</dt>
                                <dd className="text-lg font-bold text-red-600">{summary.red_kris}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Amber (Warning)</dt>
                                <dd className="text-lg font-bold text-yellow-600">{summary.amber_kris}</dd>
                            </div>
                        </dl>
                    </section>
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Issues &amp; Findings</h3>
                        <dl className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="text-xs text-gray-500">Open</dt>
                                <dd className="text-lg font-bold text-[#1A365D]">{summary.open_issues}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Overdue</dt>
                                <dd className="text-lg font-bold text-red-600">{summary.overdue_issues}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Regulatory</dt>
                                <dd className="text-lg font-bold text-yellow-600">{summary.regulatory_issues}</dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <section className="mt-6 bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Filing Note</h3>
                    <p className="text-sm text-gray-600 leading-relaxed">
                        This pack consolidates the Capital Adequacy position, residual risk profile, KRI traffic-light
                        status, loss-event history, and open regulatory findings into the format expected by the
                        Central Bank of Nigeria Operational Risk Management System (CBN ORMS) cycle submission. Attach
                        the Capital Adequacy Summary and Stress Testing Report alongside when filing.
                    </p>
                </section>
            </ReportShell>
        </AuthenticatedLayout>
    );
}
