import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import Figure from '@/Components/Quantification/Figure';
import { NOT_ASSESSED, number, percent } from '@/Components/Quantification/figures';

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

/**
 * The observed line, the projection, and the projection's band.
 *
 * Three series on one label axis. A month with no assessment stays NULL and is
 * drawn as a gap — never interpolated — which is the property
 * docs/ai-number-provenance.md commits to and the reason this is hand-drawn
 * SVG rather than a chart library's default line, which would join across the
 * hole.
 */
function ForecastChart({ chart, height = 300 }) {
    const { labels, observed, projected, rangeHigh, rangeLow } = chart;

    if (labels.length === 0) return null;

    const width = 760;
    const pad = { top: 16, right: 16, bottom: 40, left: 46 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    const present = [...observed, ...projected, ...rangeHigh, ...rangeLow].filter((v) => v !== null && v !== undefined);

    if (present.length === 0) return null;

    const max = Math.max(...present);
    const min = Math.min(...present);
    const span = max - min || 1;

    const xFor = (i) => pad.left + (labels.length === 1 ? plotW / 2 : (i / (labels.length - 1)) * plotW);
    const yFor = (v) => pad.top + plotH - ((v - min) / span) * plotH;

    // Contiguous runs only, so a null leaves a real gap.
    const runs = (series) => {
        const out = [];
        let current = [];

        series.forEach((value, i) => {
            if (value === null || value === undefined) {
                if (current.length > 1) out.push(current);
                current = [];
            } else {
                current.push([xFor(i), yFor(value)]);
            }
        });

        if (current.length > 1) out.push(current);

        return out;
    };

    const band = [];
    rangeHigh.forEach((v, i) => {
        if (v !== null && rangeLow[i] !== null && v !== undefined) band.push([xFor(i), yFor(v), yFor(rangeLow[i])]);
    });

    const labelEvery = Math.max(1, Math.ceil(labels.length / 10));

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full" role="img" aria-label="Observed and projected mean residual score">
            {[0, 0.25, 0.5, 0.75, 1].map((t) => {
                const value = min + span * t;

                return (
                    <g key={t}>
                        <line x1={pad.left} x2={width - pad.right} y1={yFor(value)} y2={yFor(value)} stroke="#E5E7EB" />
                        <text x={pad.left - 8} y={yFor(value) + 3.5} textAnchor="end" fontSize="10" fill="#9CA3AF">
                            {value.toFixed(1)}
                        </text>
                    </g>
                );
            })}

            {band.length > 1 && (
                <polygon
                    fill="rgba(26,54,93,0.12)"
                    points={[
                        ...band.map(([x, high]) => `${x},${high}`),
                        ...[...band].reverse().map(([x, , low]) => `${x},${low}`),
                    ].join(' ')}
                />
            )}

            {runs(observed).map((run, i) => (
                <polyline key={`o-${i}`} fill="none" stroke="#1A365D" strokeWidth="2" points={run.map(([x, y]) => `${x},${y}`).join(' ')} />
            ))}

            {runs(projected).map((run, i) => (
                <polyline
                    key={`p-${i}`}
                    fill="none"
                    stroke="#1A365D"
                    strokeWidth="2"
                    strokeDasharray="5 4"
                    points={run.map(([x, y]) => `${x},${y}`).join(' ')}
                />
            ))}

            {labels.map((label, i) =>
                i % labelEvery === 0 ? (
                    <text key={i} x={xFor(i)} y={height - 18} textAnchor="middle" fontSize="9" fill="#9CA3AF">
                        {label}
                    </text>
                ) : null,
            )}
        </svg>
    );
}

/**
 * Risk Forecast (migration Phase 5.6).
 *
 * Every figure comes from RiskForecastService, over the tenant's own
 * assessment history; docs/ai-number-provenance.md maps each one to its query
 * and the prop names here are unchanged from the Blade view's, so that document
 * still holds.
 *
 * The screen was a trend line and three months of "predictions" generated with
 * `mt_rand()`, escalation probabilities from a hardcoded array, and a model
 * accuracy panel — 87.3 / 84.1 / 89.7 / 86.8 / AUC 0.912, version "2.4.1" — all
 * constants. There is no model, so there are no model metrics, and the
 * projection carries a RANGE with its basis stated rather than a confidence
 * percentage, which would assert distributional properties nobody has verified.
 */
export default function Forecast({ chart, fit, projection, signals, watchlist, deteriorating, improving, inputs, asAt }) {
    return (
        <AuthenticatedLayout title="Risk Forecast">
            <Head title="Risk Forecast" />

            <div className="flex items-start justify-between mb-6">
                <div>
                    <h1 className="text-xl font-bold text-[#1A365D]">Risk Forecast</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Projected from this organisation&apos;s own assessment history — {inputs.history_months} months
                        observed, {inputs.horizon_months} projected.
                    </p>
                </div>
                <p className="text-right text-xs text-gray-500">
                    As at <span className="font-semibold text-[#1A365D]">{shortDate(asAt)}</span>
                    <span className="block">
                        {shortDate(inputs.window_start)} — {shortDate(inputs.window_end)}
                    </span>
                </p>
            </div>

            <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 flex items-start gap-3">
                <span className="material-symbols-outlined text-blue-600 text-lg">info</span>
                <p className="text-xs text-blue-900 leading-relaxed">
                    This is an ordinary least-squares fit over your recorded assessment scores, not a model. Months
                    with no assessment are left as gaps rather than filled in, and the projection is shown as a range
                    with its basis stated — there is no confidence percentage, because nothing here has verified the
                    distribution that would justify one.
                </p>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    title="Trend direction"
                    value={fit.available ? fit.direction : null}
                    unavailable={!fit.available}
                    unavailableLabel="Not enough history"
                    icon="trending_up"
                    color="primary"
                    subtitle={fit.available ? `${fit.slope_per_month > 0 ? '+' : ''}${fit.slope_per_month} per month` : fit.reason}
                />
                <KpiCard
                    title="Months fitted"
                    value={fit.available ? number(fit.n) : null}
                    unavailable={!fit.available}
                    unavailableLabel="—"
                    icon="calendar_month"
                    color="info"
                    subtitle="Months with an assessment"
                />
                <KpiCard title="Risks deteriorating" value={number(deteriorating.length)} icon="arrow_upward" color="danger" />
                <KpiCard title="Risks improving" value={number(improving.length)} icon="arrow_downward" color="success" />
            </div>

            <section className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Mean residual score</h3>
                <p className="text-xs text-gray-500 mb-4">
                    Solid is observed; dashed is projected, with the shaded band its range.
                </p>

                {fit.available ? (
                    <ForecastChart chart={chart} />
                ) : (
                    <p className="text-sm text-gray-400 italic py-12 text-center">
                        {fit.reason ?? 'Not enough assessment history to fit a trend.'}
                    </p>
                )}
            </section>

            {projection.length > 0 && (
                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Projection</h3>
                        <p className="text-xs text-gray-500 mt-1">
                            Residual standard error of the fit: {fit.residual_std_error ?? '—'}
                        </p>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th className="text-right">Projected mean</th>
                                    <th className="text-right">Range</th>
                                    <th>Basis</th>
                                </tr>
                            </thead>
                            <tbody>
                                {projection.map((row, index) => (
                                    <tr key={index}>
                                        <td className="text-sm font-medium text-[#1A365D]">{row.label}</td>
                                        <td className="text-right text-sm">{row.projected_mean_residual_score}</td>
                                        <td className="text-right text-sm">
                                            {row.range_low} — {row.range_high}
                                        </td>
                                        <td className="text-xs text-gray-500">{row.range_basis}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Signals</h3>
                    <dl className="space-y-3 text-sm">
                        <div className="flex justify-between border-b border-gray-100 pb-2">
                            <dt className="text-xs text-gray-500">Treatment velocity</dt>
                            <dd className="text-xs font-medium">
                                <Figure
                                    value={signals.treatment_velocity}
                                    formatted={signals.treatment_velocity}
                                    absent={NOT_ASSESSED}
                                />
                            </dd>
                        </div>
                        <div className="flex justify-between border-b border-gray-100 pb-2">
                            <dt className="text-xs text-gray-500">KRI breach frequency</dt>
                            <dd className="text-xs font-medium">
                                {/* Null, not zero, when nothing was measured. */}
                                <Figure
                                    value={signals.kri_breach_frequency}
                                    formatted={signals.kri_breach_frequency}
                                    absent="Nothing measured"
                                />
                            </dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-xs text-gray-500">Control test failure rate</dt>
                            <dd className="text-xs font-medium">
                                <Figure
                                    value={signals.control_test_failure_rate}
                                    formatted={percent(signals.control_test_failure_rate)}
                                    absent="Nothing tested"
                                />
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Inputs</h3>
                    <dl className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <dt className="text-xs text-gray-500">Active risks</dt>
                            <dd className="font-semibold text-[#1A365D]">{inputs.active_risks}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Assessments in window</dt>
                            <dd className="font-semibold text-[#1A365D]">{inputs.assessments_in_window}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">History</dt>
                            <dd className="font-semibold text-[#1A365D]">{inputs.history_months} months</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Horizon</dt>
                            <dd className="font-semibold text-[#1A365D]">{inputs.horizon_months} months</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {[
                    ['Deteriorating', deteriorating, 'text-red-600'],
                    ['Improving', improving, 'text-green-600'],
                ].map(([title, rows, deltaClass]) => (
                    <section key={title} className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                            <p className="text-xs text-gray-500 mt-1">
                                The two most recent dated assessments per risk. Both scores are shown so the
                                arithmetic can be checked.
                            </p>
                        </header>
                        {rows.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-gray-500 text-center">
                                No risk has moved in this direction.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Risk</th>
                                            <th className="text-right">Previous</th>
                                            <th className="text-right">Current</th>
                                            <th className="text-right">Delta</th>
                                            <th className="text-right">Overdue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row, index) => (
                                            <tr key={index}>
                                                <td className="text-sm">
                                                    <span className="font-medium text-[#1A365D]">{row.risk_code}</span>
                                                    <span className="block text-xs text-gray-500">{row.title}</span>
                                                </td>
                                                <td className="text-right text-xs">
                                                    {row.previous_score}
                                                    <span className="block text-gray-400">{shortDate(row.previous_date)}</span>
                                                </td>
                                                <td className="text-right text-xs">
                                                    {row.current_score}
                                                    <span className="block text-gray-400">{shortDate(row.current_date)}</span>
                                                </td>
                                                <td className={`text-right text-sm font-semibold ${deltaClass}`}>
                                                    {row.delta > 0 ? '+' : ''}
                                                    {row.delta}
                                                </td>
                                                <td className="text-right text-xs">{row.overdue_treatments}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
