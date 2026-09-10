import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import DonutChart from '@thirdline/ui/Components/DonutChart';
import DynamicDetail from '@thirdline/ui/Components/DynamicDetail';
import RichTextRenderer from '@thirdline/ui/Components/RichTextRenderer';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import { formatDate } from '@thirdline/ui/utils';

const PALETTE = ['#1A365D', '#2D7D46', '#DD6B20', '#3182CE', '#D4AF37', '#ED8936', '#48BB78', '#9F7AEA'];

const TABS = [
    { key: 'overview', label: 'Overview' },
    { key: 'risks', label: 'Risks' },
    { key: 'kris', label: 'KRIs' },
    { key: 'issues', label: 'Issues' },
    { key: 'sub_entities', label: 'Sub-Entities' },
];

const ucfirst = (value) => (value ? String(value).charAt(0).toUpperCase() + String(value).slice(1) : '—');
const lower = (value) => (value ? String(value).toLowerCase() : value);

function scoreClass(score) {
    if (score >= 4) return 'text-red-600';
    if (score >= 3) return 'text-orange-600';
    return 'text-green-600';
}

function CountPill({ value, tone }) {
    if (!value) return <span className="text-gray-400">0</span>;

    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}>{value}</span>;
}

function Table({ head, children, empty, colSpan }) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>{head.map((h) => <th key={h} className="px-4 py-2">{h}</th>)}</tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {children.length === 0 ? (
                        <tr><td colSpan={colSpan} className="px-4 py-8 text-center text-sm text-gray-500">{empty}</td></tr>
                    ) : children}
                </tbody>
            </table>
        </div>
    );
}

function Card({ title, children, actions }) {
    return (
        <div className="card">
            <div className="card-header flex items-center justify-between">
                <h2 className="text-base font-bold text-[#1A365D]">{title}</h2>
                {actions}
            </div>
            {children}
        </div>
    );
}

/**
 * Entity detail (migration Phase 3.1: risk/scoping/show.blade.php). Risks,
 * issues and KRIs still open in Blade pages, so those rows are plain anchors.
 */
export default function Show({ entity, riskStats, risks = [], issues = [], kris = [], subEntities = [], subEntityHeatmap = [], categoryDistribution = [], configured, can = {} }) {
    const [activeTab, setActiveTab] = useState('overview');

    const distribution = categoryDistribution.map((slice, idx) => ({ name: slice.name, value: slice.value, color: PALETTE[idx % PALETTE.length] }));
    const hasConfigured = (configured?.sections ?? []).length > 0;

    const destroy = () => {
        if (window.confirm(`Delete entity ${entity.entity_code} — ${entity.name}? This cannot be undone.`)) {
            router.delete(route('risk.scoping.destroy', entity.id));
        }
    };

    return (
        <AuthenticatedLayout title="Entity Detail">
            <Head title={`${entity.name} · Entity Detail`} />

            <PageHeader
                title={entity.name}
                subtitle={`Code: ${entity.entity_code} · Type: ${entity.type ?? '—'} (L${entity.level})`}
                breadcrumbs={[
                    { label: 'Scoping', href: route('risk.scoping.dashboard') },
                    { label: 'Entity Register', href: route('risk.scoping.index') },
                    { label: entity.entity_code },
                ]}
                actions={
                    <>
                        {can.update && (
                            <Link href={route('risk.scoping.edit', entity.id)} className="btn-primary inline-flex items-center gap-2 text-sm">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                        <Link href={route('risk.scoping.dashboard')} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">account_tree</span> Hierarchy
                        </Link>
                        {can.delete && (
                            <button type="button" onClick={destroy} className="btn-danger inline-flex items-center gap-2 text-sm">
                                <span className="material-symbols-outlined text-lg">delete</span> Delete
                            </button>
                        )}
                    </>
                }
            />

            <div className="card mb-6">
                <div className="card-body">
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <StatusBadge status={entity.status} />
                        {entity.parent && (
                            <span className="text-sm text-gray-500">
                                Parent:{' '}
                                <Link href={route('risk.scoping.show', entity.parent.id)} className="font-semibold text-[#1A365D] hover:underline">{entity.parent.name}</Link>
                            </span>
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-4 border-t border-gray-200 pt-4 lg:grid-cols-4">
                        <div>
                            <p className="mb-1 text-xs text-gray-500">Owner</p>
                            <p className="font-semibold text-gray-800">{entity.owner ?? '—'}</p>
                        </div>
                        <div>
                            <p className="mb-1 text-xs text-gray-500">Delegate</p>
                            <p className="font-semibold text-gray-800">{entity.delegate ?? '—'}</p>
                        </div>
                        <div>
                            <p className="mb-1 text-xs text-gray-500">Created</p>
                            <p className="font-semibold text-gray-800">{formatDate(entity.created_at)}</p>
                            <p className="text-xs text-gray-500">{entity.created_human}</p>
                        </div>
                        <div>
                            <p className="mb-1 text-xs text-gray-500">Last Updated</p>
                            <p className="font-semibold text-gray-800">{formatDate(entity.updated_at)}</p>
                            <p className="text-xs text-gray-500">{entity.updated_human}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="mb-6 flex gap-1 rounded-t-xl border-b border-gray-200 bg-white px-6">
                {TABS.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => setActiveTab(tab.key)}
                        className={`border-b-2 px-4 py-3 text-sm font-medium transition-colors ${activeTab === tab.key ? 'border-[#1A365D] text-[#1A365D]' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {activeTab === 'overview' && (
                <div className="space-y-6">
                    <Card title="Entity Summary">
                        <div className="card-body">
                            {entity.description && (
                                <div className="mb-4 text-sm leading-relaxed text-gray-600">
                                    <RichTextRenderer value={entity.description} />
                                </div>
                            )}
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                                <div>
                                    <p className="mb-1 text-xs text-gray-500">Regulatory Scope</p>
                                    <p className="text-sm font-medium text-gray-800">{entity.regulatory_frameworks.length > 0 ? entity.regulatory_frameworks.join(', ') : '—'}</p>
                                </div>
                                <div>
                                    <p className="mb-1 text-xs text-gray-500">Risk Appetite</p>
                                    <p className="text-sm font-medium text-gray-800">{entity.risk_appetite_level ? ucfirst(entity.risk_appetite_level) : 'Not set'}</p>
                                </div>
                                <div>
                                    <p className="mb-1 text-xs text-gray-500">Sub-Entities</p>
                                    <p className="text-sm font-medium text-gray-800">{subEntities.length}</p>
                                </div>
                            </div>
                        </div>
                    </Card>

                    {hasConfigured && (
                        <Card title="Additional Fields">
                            <div className="card-body"><DynamicDetail detail={configured} /></div>
                        </Card>
                    )}

                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                        <KpiCard title="Total Risks" value={riskStats.total} icon="assessment" color="primary" />
                        <KpiCard title="Critical" value={riskStats.critical} icon="error" color="danger" />
                        <KpiCard title="High" value={riskStats.high} icon="warning" color="warning" />
                        <KpiCard title="Medium" value={riskStats.medium} icon="info" color="info" />
                        <KpiCard title="Low" value={riskStats.low} icon="check_circle" color="success" />
                    </div>

                    {distribution.length > 0 && (
                        <Card title="Risk Distribution by Category">
                            <div className="card-body"><DonutChart data={distribution} /></div>
                        </Card>
                    )}

                    {subEntityHeatmap.length > 0 && (
                        <Card title="Sub-Entity Risk Heatmap">
                            <Table head={['Sub-Entity', 'Type', 'Total Risks', 'Critical', 'High', 'Risk Score', 'Status']} colSpan={7} empty="">
                                {subEntityHeatmap.map((sub) => (
                                    <tr key={sub.id} className="hover:bg-blue-50/50">
                                        <td className="px-4 py-2 font-medium text-[#1A365D]">
                                            <Link href={route('risk.scoping.show', sub.id)} className="hover:underline">{sub.name}</Link>
                                        </td>
                                        <td className="px-4 py-2"><span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">{sub.type ?? '—'}</span></td>
                                        <td className="px-4 py-2 font-semibold">{sub.risk_total}</td>
                                        <td className="px-4 py-2"><CountPill value={sub.critical_count} tone="bg-red-100 text-red-700" /></td>
                                        <td className="px-4 py-2"><CountPill value={sub.high_count} tone="bg-orange-100 text-orange-700" /></td>
                                        <td className="px-4 py-2"><span className={`font-bold ${scoreClass(sub.risk_score)}`}>{sub.risk_score}/5</span></td>
                                        <td className="px-4 py-2"><StatusBadge status={sub.status} /></td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    )}
                </div>
            )}

            {activeTab === 'risks' && (
                <Card title={`Risks Assigned to ${entity.name}`}>
                    <Table head={['Code', 'Title', 'Category', 'Rating', 'Owner']} colSpan={5} empty="No risks assigned to this entity yet.">
                        {risks.map((risk) => (
                            <tr key={risk.id} className="hover:bg-blue-50/50">
                                <td className="px-4 py-2 font-semibold text-[#1A365D]"><a href={risk.url} className="hover:underline">{risk.code}</a></td>
                                <td className="px-4 py-2">{risk.title}</td>
                                <td className="px-4 py-2 text-xs">{risk.category ?? '—'}</td>
                                <td className="px-4 py-2"><RatingBadge rating={lower(risk.rating) ?? 'unrated'} /></td>
                                <td className="px-4 py-2 text-xs text-gray-600">{risk.owner ?? '—'}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            {activeTab === 'kris' && (
                <Card title="Key Risk Indicators">
                    <Table head={['Code', 'Name', 'Current Value', 'Status', 'Trend']} colSpan={5} empty="No KRIs assigned to this entity.">
                        {kris.map((kri) => (
                            <tr key={kri.id} className="hover:bg-blue-50/50">
                                <td className="px-4 py-2 font-semibold text-[#1A365D]"><a href={kri.url} className="hover:underline">{kri.code}</a></td>
                                <td className="px-4 py-2">{kri.name}</td>
                                <td className="px-4 py-2 font-semibold">{kri.current_value ?? '—'}</td>
                                <td className="px-4 py-2"><StatusBadge status={kri.status ?? 'green'} /></td>
                                <td className="px-4 py-2">{ucfirst(kri.trend)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            {activeTab === 'issues' && (
                <Card title="Issues & Findings">
                    <Table head={['Code', 'Title', 'Severity', 'Status', 'Owner']} colSpan={5} empty="No issues assigned to this entity.">
                        {issues.map((issue) => (
                            <tr key={issue.id} className="hover:bg-blue-50/50">
                                <td className="px-4 py-2 font-semibold text-[#1A365D]"><a href={issue.url} className="hover:underline">{issue.reference}</a></td>
                                <td className="px-4 py-2">{issue.title}</td>
                                <td className="px-4 py-2"><RatingBadge rating={lower(issue.severity) ?? 'medium'} /></td>
                                <td className="px-4 py-2"><StatusBadge status={issue.status ?? 'open'} /></td>
                                <td className="px-4 py-2 text-xs text-gray-600">{issue.owner ?? '—'}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            {activeTab === 'sub_entities' && (
                <Card
                    title="Sub-Entities"
                    actions={can.create && (
                        <Link href={route('risk.scoping.create')} className="btn-secondary inline-flex items-center gap-1 text-xs">
                            <span className="material-symbols-outlined text-base">add</span> New Entity
                        </Link>
                    )}
                >
                    <Table head={['Code', 'Name', 'Type', 'Risks', 'Issues', 'Status']} colSpan={6} empty="No sub-entities under this entity.">
                        {subEntities.map((sub) => (
                            <tr key={sub.id} className="hover:bg-blue-50/50">
                                <td className="px-4 py-2 font-semibold text-[#1A365D]">
                                    <Link href={route('risk.scoping.show', sub.id)} className="hover:underline">{sub.code}</Link>
                                </td>
                                <td className="px-4 py-2 font-medium">{sub.name}</td>
                                <td className="px-4 py-2"><span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">{sub.type ?? '—'}</span></td>
                                <td className="px-4 py-2 font-semibold">{sub.risks_count}</td>
                                <td className="px-4 py-2 font-semibold">{sub.issues_count}</td>
                                <td className="px-4 py-2"><StatusBadge status={sub.status} /></td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
        </AuthenticatedLayout>
    );
}
