import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The findings board — FR-FND-01.
 *
 * SWIMLANES BY SEVERITY, COLUMNS BY STATUS. The other way round reads better
 * on a whiteboard and worse here: a board grouped by status puts a Critical
 * finding nobody has assigned in the same column as a Low one, and the eye
 * goes to the fullest column rather than to the row that matters.
 *
 * THE SLA BREACH IS THE HIGHLIGHT, NOT THE STATUS. A finding two days from its
 * target and one three months past it are both "in remediation", and only one
 * of them needs a phone call today. So the card is coloured by its clock and
 * labelled by its status, rather than the reverse.
 */
export default function Index({
    findings = [], columns = [], severities = [], summary = {}, acceptanceRegister = {},
    filters = {}, can = {},
}) {
    const openColumns = columns.filter((column) => column.is_open);

    const tiles = [
        { label: 'Open', value: summary.open ?? 0, hint: 'across every vendor' },
        {
            label: 'Overdue',
            value: summary.overdue ?? 0,
            hint: 'past their target date',
            tone: summary.overdue ? 'critical' : null,
        },
        {
            label: 'Critical open',
            value: summary.critical_open ?? 0,
            hint: 'the ones a board asks about',
            tone: summary.critical_open ? 'critical' : null,
        },
        {
            label: 'Nobody owns it',
            value: summary.unowned ?? 0,
            hint: 'open and unassigned',
            tone: summary.unowned ? 'warn' : null,
        },
    ];

    return (
        <AppLayout title="Findings">
            <Head title="Findings" />

            <PageHeader
                title="Findings"
                subtitle="Control gaps in third parties, from assessments, assurance reports, contracts and monitoring. Each one moves the vendor's residual score until it is closed."
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="card p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className={`mt-1 text-2xl font-semibold ${
                            tile.tone === 'critical' ? 'text-red-700' : tile.tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
                        }`}>
                            {tile.value}
                        </p>
                        <p className="mt-0.5 text-xs text-gray-500">{tile.hint}</p>
                    </div>
                ))}
            </div>

            <AcceptanceBanner register={acceptanceRegister} />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <FilterChip
                    label="Open only"
                    active={filters.open_only}
                    onClick={() => router.get(route('tprm.findings.index'), {
                        ...filters, open_only: !filters.open_only,
                    }, { preserveState: true })}
                />
                {severities.map((severity) => (
                    <FilterChip
                        key={severity.value}
                        label={severity.label}
                        active={filters.severity === severity.value}
                        onClick={() => router.get(route('tprm.findings.index'), {
                            ...filters,
                            severity: filters.severity === severity.value ? null : severity.value,
                        }, { preserveState: true })}
                    />
                ))}
            </div>

            {findings.length === 0 ? (
                <div className="card p-8 text-center text-sm text-gray-500">
                    No findings match this view. Findings are raised from assessment answers, SOC 2 exceptions,
                    contract clause gaps and monitoring signals — or by hand from an engagement.
                </div>
            ) : (
                <div className="space-y-6">
                    {severities.map((severity) => {
                        const lane = findings.filter((finding) => finding.severity === severity.value);

                        if (lane.length === 0) {
                            return null;
                        }

                        return (
                            <Swimlane
                                key={severity.value}
                                severity={severity}
                                columns={openColumns}
                                findings={lane}
                            />
                        );
                    })}
                </div>
            )}
        </AppLayout>
    );
}

function AcceptanceBanner({ register }) {
    if (!register || (register.in_force ?? 0) === 0) {
        return null;
    }

    return (
        <div className="mb-6 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
            <span className="font-medium">{register.in_force} risk acceptance(s) in force</span>
            {register.critical_in_force > 0 && (
                <span className="text-red-700">, {register.critical_in_force} of them against Critical findings</span>
            )}
            {register.expiring_30 > 0 && <>, {register.expiring_30} expiring within 30 days</>}
            {register.lapsed > 0 && (
                <span className="text-amber-800">, {register.lapsed} already lapsed and reopened</span>
            )}.
            {' '}An accepted finding still counts at half weight in the residual score — accepting a risk
            decides whether to remediate it, not whether it exists.
        </div>
    );
}

function Swimlane({ severity, columns, findings }) {
    const tone = {
        critical: 'border-red-300',
        high: 'border-orange-300',
        medium: 'border-amber-300',
        low: 'border-gray-300',
    }[severity.value] ?? 'border-gray-300';

    return (
        <div className={`card border-l-4 ${tone} p-4`}>
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-gray-900">
                    {severity.label} <span className="font-normal text-gray-500">({findings.length})</span>
                </h3>
            </div>

            <div className="grid gap-3 md:grid-cols-3 lg:grid-cols-5">
                {columns.map((column) => {
                    const cards = findings.filter((finding) => finding.status === column.value);

                    return (
                        <div key={column.value} className="min-w-0">
                            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">
                                {column.label} {cards.length > 0 && <span className="text-gray-400">({cards.length})</span>}
                            </p>
                            <div className="space-y-2">
                                {cards.map((finding) => <Card key={finding.id} finding={finding} />)}
                            </div>
                        </div>
                    );
                })}
            </div>

            {findings.some((finding) => !columns.some((column) => column.value === finding.status)) && (
                <div className="mt-3 border-t border-gray-100 pt-3">
                    <p className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">
                        Closed or accepted
                    </p>
                    <div className="grid gap-2 md:grid-cols-3 lg:grid-cols-5">
                        {findings
                            .filter((finding) => !columns.some((column) => column.value === finding.status))
                            .map((finding) => <Card key={finding.id} finding={finding} />)}
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * Coloured by its clock, labelled by its status.
 */
function Card({ finding }) {
    const tone = finding.risk_accepted
        ? 'border-gray-200 bg-gray-50'
        : finding.is_overdue_beyond
            ? 'border-red-300 bg-red-50'
            : finding.is_overdue
                ? 'border-red-200 bg-red-50/50'
                : finding.within_sla_with_plan
                    ? 'border-green-200 bg-green-50/50'
                    : 'border-gray-200 bg-white';

    return (
        <Link href={finding.url} className={`block rounded border p-2.5 text-xs hover:shadow-sm ${tone}`}>
            <p className="font-mono text-[10px] text-gray-500">{finding.reference}</p>
            <p className="mt-0.5 line-clamp-3 font-medium text-gray-900">{finding.title}</p>
            <p className="mt-1 text-gray-500">{finding.third_party}</p>

            <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]">
                <span className={finding.owner ? 'text-gray-600' : 'text-amber-700'}>
                    {finding.owner ?? 'Unassigned'}
                </span>

                {finding.risk_accepted ? (
                    <span className="text-gray-600">
                        Accepted to {finding.acceptance_expires}
                    </span>
                ) : finding.days_until_target !== null && (
                    <span className={
                        finding.is_overdue_beyond ? 'font-medium text-red-800'
                            : finding.is_overdue ? 'text-red-700'
                                : finding.days_until_target <= 7 ? 'text-amber-700' : 'text-gray-600'
                    }>
                        {finding.days_until_target < 0
                            ? `${Math.abs(finding.days_until_target)} days overdue`
                            : `${finding.days_until_target} days left`}
                        {/* The state the residual score multiplies by 1.5 — a
                            finding nobody is working, as against one that is
                            merely late. */}
                        {finding.is_overdue_beyond && ' · past 2× SLA'}
                    </span>
                )}

                {finding.escalation_level > 0 && !finding.risk_accepted && (
                    <span className="text-red-700">Escalation {finding.escalation_level}</span>
                )}
            </div>
        </Link>
    );
}

function FilterChip({ label, active, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-full border px-3 py-1 text-xs font-medium ${
                active
                    ? 'border-blue-300 bg-blue-50 text-blue-800'
                    : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
            }`}
        >
            {label}
        </button>
    );
}
