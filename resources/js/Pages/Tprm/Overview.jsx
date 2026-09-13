import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The TPRM front door — item 10 of Phase 10.
 *
 * IT OPENS WITH WHAT IS WRONG, NOT WITH HOW BIG THE ESTATE IS. "214 vendors"
 * is the first number every competitor's dashboard shows and the least useful
 * one in the room. The four tiles across the top are Critical engagements past
 * their assessment date, vendors that require an exit plan and have none,
 * evidence that has already expired, and findings past their remediation date.
 *
 * EVERY TILE DRILLS THROUGH WITH ITS FILTER INTACT. A counter a user cannot
 * open is a number they have to take on trust.
 *
 * "NOT TIERED" IS ITS OWN ROW IN THE DISTRIBUTION, never folded into Low. An
 * engagement nobody has tiered has not been found to be low risk; it has not
 * been looked at.
 */
export default function Overview({
    portfolio = {},
    attention = [],
    findings = {},
    exit = {},
    concentration = {},
    incidents = {},
    tiers = [],
    alerts = [],
    kris = [],
    can = {},
}) {
    const unpublished = kris.filter((kri) => !kri.published).length;

    return (
        <AppLayout title="Third-party risk">
            <Head title="Third-party risk" />

            <PageHeader
                title="Third-party risk"
                subtitle="What needs attention first, and the shape of the portfolio behind it."
            />

            {/* --------------------------------------------------- attention */}
            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {attention.map((tile) => (
                    <Link key={tile.label} href={tile.href} className="card block p-4 transition hover:border-indigo-300">
                        <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</div>
                        <div
                            className={`mt-1 text-3xl font-bold ${
                                tile.tone === 'critical'
                                    ? 'text-red-600'
                                    : tile.tone === 'warn'
                                      ? 'text-amber-600'
                                      : 'text-emerald-700'
                            }`}
                        >
                            {tile.value}
                        </div>
                        <div className="mt-1 text-xs text-gray-500">{tile.hint}</div>
                    </Link>
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                {/* ------------------------------------------------ portfolio */}
                <section className="card p-4">
                    <h2 className="mb-3 text-sm font-semibold text-gray-800">Portfolio</h2>

                    <dl className="mb-4 space-y-1 text-sm">
                        <Row label="Live engagements" value={portfolio.total ?? 0} />
                        <Row label="Support a critical function" value={portfolio.supports_critical_function ?? 0} />
                        <Row label="Material outsourcing" value={portfolio.material_outsourcing ?? 0} />
                        <Row
                            label="Mean residual"
                            value={
                                portfolio.mean_residual === null || portfolio.mean_residual === undefined
                                    ? 'Not computed'
                                    : portfolio.mean_residual
                            }
                            hint={`across ${portfolio.scored ?? 0} scored — ${portfolio.unscored ?? 0} excluded`}
                        />
                    </dl>

                    <table className="min-w-full text-sm">
                        <tbody className="divide-y divide-gray-100">
                            {tiers.map((tier) => (
                                <tr key={tier.label}>
                                    <td className="py-1">
                                        <Link className="text-indigo-600" href={tier.href}>{tier.label}</Link>
                                    </td>
                                    <td className="py-1 text-right tabular-nums">{tier.count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                {/* ---------------------------------------------------- risk */}
                <section className="card p-4">
                    <h2 className="mb-3 text-sm font-semibold text-gray-800">Risk position</h2>

                    <dl className="space-y-1 text-sm">
                        <Row label="Open findings" value={findings.open ?? 0} hint={`${findings.overdue ?? 0} overdue`} />
                        <Row
                            label="Mean finding age"
                            value={
                                findings.mean_age_days === null || findings.mean_age_days === undefined
                                    ? 'No findings open'
                                    : `${findings.mean_age_days} days`
                            }
                        />
                        <Row
                            label="Concentration (HHI)"
                            value={
                                concentration.hhi === null || concentration.hhi === undefined
                                    ? 'Not computed'
                                    : Math.round(concentration.hhi)
                            }
                            hint={concentration.band}
                        />
                        <Row
                            label="Exit plans never tested"
                            value={exit.never_tested ?? 0}
                            hint="a document, not a capability"
                        />
                        <Row
                            label="Incidents, 12 months"
                            value={incidents.count ?? 0}
                            hint={`${incidents.personal_data ?? 0} involved personal data`}
                        />
                    </dl>
                </section>

                {/* -------------------------------------------------- alerts */}
                <section className="card p-4">
                    <h2 className="mb-3 text-sm font-semibold text-gray-800">Open alerts</h2>

                    {alerts.length === 0 ? (
                        <p className="text-sm text-gray-500">
                            No open monitoring alerts, or you do not hold the monitoring permission.
                        </p>
                    ) : (
                        <ul className="space-y-3 text-sm">
                            {alerts.map((alert) => (
                                <li key={alert.id}>
                                    <div className="font-medium text-gray-800">{alert.title}</div>
                                    <div className="text-xs text-gray-500">
                                        {alert.severity}
                                        {alert.third_party ? ` · ${alert.third_party}` : ''} · {alert.raised}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            {/* ------------------------------------------------------ the KRIs */}
            {can.view_kris && (
                <section className="mt-6">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold text-gray-800">Third-party KRIs</h2>
                            <p className="text-xs text-gray-500">
                                Published into the KRI Collection module so they sit in the same board pack, on
                                the same bands and through the same breach detection as every other indicator.
                            </p>
                        </div>
                        {can.adopt_kris && unpublished > 0 && (
                            <button
                                type="button"
                                className="btn-primary"
                                onClick={() => router.post(route('tprm.kris.adopt'), {}, { preserveScroll: true })}
                            >
                                Add {unpublished} to the KRI register
                            </button>
                        )}
                    </div>

                    <div className="card overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <caption className="px-4 py-2 text-left text-xs text-gray-500">
                                A metric with no computable value shows its reason rather than a number. Nothing
                                is published for those — a zero on an empty denominator would open a breach and
                                reach a board pack from a division by nothing.
                            </caption>
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">KRI</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Indicator</th>
                                    <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Now</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Basis</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">In the register</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {kris.map((kri) => (
                                    <tr key={kri.code}>
                                        <td className="px-4 py-2 font-medium">{kri.kri_code}</td>
                                        <td className="px-4 py-2">
                                            {kri.name}
                                            <div className="text-xs text-gray-500">{kri.description}</div>
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {kri.value === null || kri.value === undefined ? (
                                                <span className="text-gray-400">Not computable</span>
                                            ) : (
                                                <>
                                                    {kri.value}
                                                    <span className="ml-1 text-xs text-gray-400">{kri.unit}</span>
                                                </>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-gray-600">{kri.note}</td>
                                        <td className="px-4 py-2 text-xs">
                                            {kri.published ? (
                                                <span className="text-emerald-700">
                                                    Published
                                                    {kri.last_published_at ? ` · ${kri.last_published_at}` : ' · never sent a reading'}
                                                </span>
                                            ) : (
                                                <span className="text-gray-400">Not adopted</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <p className="mt-2 text-xs text-gray-500">
                        Adoption creates each indicator with default thresholds, which are yours to retune. A
                        republish only ever writes a measurement — it never overwrites a threshold you have
                        changed.
                    </p>
                </section>
            )}
        </AppLayout>
    );
}

function Row({ label, value, hint }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="text-gray-600">
                {label}
                {hint && <span className="block text-xs text-gray-400">{hint}</span>}
            </dt>
            <dd className="font-semibold tabular-nums text-gray-900">{value}</dd>
        </div>
    );
}
