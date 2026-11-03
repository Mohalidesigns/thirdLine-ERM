import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import Glyph from '@/Components/Kri/Glyph';
import InfoStrip from '@/Components/Kri/InfoStrip';
import { ReturnStatusBadge } from '@/Components/Kri/Badges';
import { formatDay } from '@/lib/kri-format';

const ROLES = ['risk_champion', 'team_lead', 'unit_head', 'group_head'];
const ROLE_PLURAL = { risk_champion: 'Risk champions', team_lead: 'Team leads', unit_head: 'Unit heads', group_head: 'Group heads' };

function Card({ title, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-gray-200 bg-white ${className}`}>
            <h2 className="border-b border-gray-100 px-5 py-4 text-sm font-semibold text-[#1A365D]">{title}</h2>
            <div className="p-5">{children}</div>
        </section>
    );
}

function Names({ people }) {
    return (
        <>
            {people.map((p, i) => (
                <span key={p.id}>
                    {i > 0 && ', '}
                    <span title={p.email}>{p.name}</span>
                    {!p.is_active && (
                        <span className="ml-1 inline-flex items-center gap-0.5 text-amber-800"><Glyph name="warning" className="!h-4 !w-4" />(account inactive)</span>
                    )}
                </span>
            ))}
        </>
    );
}

/** One department's KRIs: `department: null` is the Unassigned variant. */
export default function Department({ department, summary, return: ret, openKriIssues = [], contacts = null, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const name = department?.name ?? 'Unassigned KRIs';
    const createUrl = permissions.includes('kri.create') ? tryRoute('risk.kri.create') : null;
    const contactsUrl = permissions.includes('kri.manage_department_contacts') ? tryRoute('risk.kri.contacts.index') : null;
    const noKris = summary.kri_count === 0;
    const tiles = [
        { title: 'KRIs', value: summary.kri_count, icon: 'speed', color: 'primary' },
        { title: 'Red', value: summary.red, icon: 'error', color: 'danger', subtitle: summary.red > 0 ? 'Needs an explanation and an action' : undefined },
        { title: 'Amber', value: summary.amber, icon: 'warning', color: 'warning' },
        { title: 'Green', value: summary.green, icon: 'check_circle', color: 'success' },
    ];

    if (summary.unbanded > 0) tiles.push({ title: 'Not banded', value: summary.unbanded, icon: 'remove', color: 'info', subtitle: 'No thresholds in force' });
    if ((summary.not_read ?? 0) > 0) tiles.push({ title: 'Not yet read', value: summary.not_read, icon: 'hourglass_empty', color: 'info', subtitle: 'No reading recorded' });

    return (
        <AuthenticatedLayout title={name}>
            <Head title={name} />

            <PageHeader
                title={name}
                subtitle={department ? `${department.code ?? ''}${department.parent ? ` · part of ${department.parent.name}` : ''}` : 'KRIs with no department'}
                breadcrumbs={[
                    { label: 'KRI Monitoring', href: route('risk.kri.index') },
                    { label: 'Key Risk Indicators Library', href: route('risk.kri.index') },
                    { label: name },
                ]}
                actions={
                    <>
                        {createUrl && (
                            <a href={createUrl} className="btn-primary inline-flex items-center gap-2 text-sm"><Glyph name="add_circle" /> New KRI</a>
                        )}
                        <Link href={route('risk.kri.index', { layout: 'flat' })} className="inline-flex items-center px-2 py-2 text-sm font-medium text-[#1A365D] underline">
                            View all KRIs in a flat list
                        </Link>
                    </>
                }
            />

            {noKris ? (
                department ? (
                    <div className="mb-6 rounded-xl border border-gray-200 bg-white">
                        <EmptyState
                            title={`No KRIs are assigned to ${department.name}`}
                            description="Assign a KRI to this department from the KRI's Edit form (Department field). Until then this department has no monthly return."
                        />
                    </div>
                ) : (
                    <p className="mb-6 text-sm text-gray-800">No KRIs are assigned to this department.</p>
                )
            ) : (
                <div role="group" aria-label={`KRI summary for ${name}`} className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-5">
                    {tiles.map((t) => <KpiCard key={t.title} {...t} />)}
                </div>
            )}

            {department ? (
                <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card title="Monthly return">
                        {ret ? (
                            <div className="space-y-2 text-sm text-gray-800">
                                <ReturnStatusBadge status={ret.display_status} daysOverdue={ret.days_overdue} />
                                <p>Due {formatDay(ret.due_on)}</p>
                                {ret.days_overdue ? <p className="font-medium text-red-700">{ret.days_overdue} {ret.days_overdue === 1 ? 'day' : 'days'} overdue</p> : null}
                                <Link href={ret.url} className="inline-block font-medium text-[#1A365D] underline">View return</Link>
                            </div>
                        ) : (
                            <p className="text-sm text-gray-800">No return is open for this department. A return opens on the first working day of a month when the department has KRIs due.</p>
                        )}
                    </Card>

                    <Card title="KRI contacts" className="lg:col-span-2">
                        {contacts && (
                            <dl className="space-y-3 text-sm">
                                {ROLES.map((role) => {
                                    const people = contacts[role] ?? [];

                                    if (role === 'risk_champion' && people.length === 0) {
                                        return (
                                            <div key={role} role="note" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900">
                                                <p className="flex items-start gap-2"><Glyph name="warning" className="mt-0.5" /><span>No risk champion is set. The monthly request has nobody to go to.</span></p>
                                                {contactsUrl && <Link href={contactsUrl} className="mt-2 inline-block font-medium underline">Set contacts</Link>}
                                            </div>
                                        );
                                    }

                                    return (
                                        <div key={role} className="grid grid-cols-1 gap-1 sm:grid-cols-3">
                                            <dt className="font-medium text-gray-800">{ROLE_PLURAL[role]}</dt>
                                            <dd className="text-gray-800 sm:col-span-2">
                                                {people.length === 0 ? <span className="text-gray-700">None set</span> : <Names people={people} />}
                                                {people.length > 0 && people.every((p) => !p.is_active) && <span className="block text-amber-800">No active person in this role.</span>}
                                            </dd>
                                        </div>
                                    );
                                })}
                            </dl>
                        )}
                    </Card>
                </div>
            ) : (
                <section className="mb-6 rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-800">
                    <h2 className="mb-1 text-sm font-semibold text-[#1A365D]">About this group</h2>
                    <p>These KRIs have no department. They are not part of any monthly return and are not counted on the submission dashboard. To give one a department, open it and choose <strong>Edit</strong>, then set <strong>Department</strong>.</p>
                </section>
            )}

            <Card title={`Open KRI issues (${openKriIssues.length})`} className="mb-6">
                {openKriIssues.length === 0 ? (
                    <p className="text-sm text-gray-800">No open KRI issues for this {department ? 'department' : 'group'}.</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {openKriIssues.map((issue) => (
                            <li key={issue.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm text-gray-800">
                                {issue.url
                                    ? <Link href={issue.url} className="font-mono font-medium text-[#1A365D] underline">{issue.reference}</Link>
                                    : <span className="font-mono font-medium">{issue.reference}</span>}
                                <span>{issue.title}</span>
                                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{String(issue.status ?? '').replace(/_/g, ' ').toLowerCase()}</span>
                                {issue.due_date && <span>Due {formatDay(issue.due_date)}</span>}
                                {issue.is_overdue && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800"><Glyph name="warning" className="!h-4 !w-4" />Overdue</span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {!noKris && grid && <DataGrid grid={grid} />}
            {summary.kri_count > 0 && summary.not_read > 0 && (
                <InfoStrip className="mt-4">{summary.not_read} {summary.not_read === 1 ? 'KRI has' : 'KRIs have'} no reading yet and {summary.not_read === 1 ? 'is' : 'are'} not counted as green.</InfoStrip>
            )}
        </AuthenticatedLayout>
    );
}
