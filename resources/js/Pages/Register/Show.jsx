import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DynamicDetail from '@thirdline/ui/Components/DynamicDetail';
import DynamicForm from '@thirdline/ui/Components/DynamicForm';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import InputError from '@thirdline/ui/Components/InputError';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import { seedConfigured, ucfirst } from './RiskForm';

const TABS = [
    ['overview', 'Overview'],
    ['controls', 'Controls'],
    ['assessment', 'Assessment'],
    ['treatment', 'Treatment'],
    ['kris', 'KRIs'],
    ['attributes', 'Attributes'],
    ['history', 'History'],
];

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

const dateTime = (value) =>
    value
        ? new Date(value).toLocaleString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
        })
        : '-';

const initials = (name) =>
    (name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

function Detail({ label, children }) {
    return (
        <div>
            <p className="text-xs text-gray-500 font-medium">{label}</p>
            {children}
        </div>
    );
}

function Table({ head, children }) {
    return (
        <div className="overflow-x-auto">
            <table className="data-table w-full">
                <thead>
                    <tr>{head.map((label) => <th key={label}>{label}</th>)}</tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

/** Migration Phase 3.2: risk/register/show.blade.php. */
export default function Show({
    risk,
    metrics,
    assessments = [],
    controls = [],
    treatmentPlans = [],
    kris = [],
    auditTrail = [],
    availableControls = [],
    configured = null,
    configuredDetail = null,
    can = {},
}) {
    const [tab, setTab] = useState('overview');
    const [mapping, setMapping] = useState(false);

    const mapForm = useForm({ control_id: '', control_weight: '', mapping_rationale: '', is_key_control: false });
    const attributesForm = useForm({ configured_attributes: seedConfigured(configured) });

    const submitMapping = (e) => {
        e.preventDefault();
        mapForm.post(route('risk.register.map-control', risk.id), {
            onSuccess: () => { mapForm.reset(); setMapping(false); },
        });
    };

    const submitAttributes = (e) => {
        e.preventDefault();
        attributesForm.patch(route('risk.register.attributes', risk.id));
    };

    const destroy = () => {
        if (window.confirm(`Delete ${risk.risk_code}? This cannot be undone from the register.`)) {
            router.delete(route('risk.register.destroy', risk.id));
        }
    };

    const newAssessment = tryRoute('risk.assessments.create', { risk_id: risk.id });
    const newControl = tryRoute('risk.controls.create', { risk_id: risk.id });
    const newTreatment = tryRoute('risk.treatments.create', { risk_id: risk.id });
    const newKri = tryRoute('risk.kri.create', { risk_id: risk.id });

    return (
        <AuthenticatedLayout title={risk.risk_code}>
            <Head title={`${risk.risk_code} - Risk Detail`} />

            <PageHeader
                title={risk.risk_code}
                subtitle={risk.title}
                breadcrumbs={[
                    { label: 'Risk Register', href: route('risk.register.index') },
                    { label: risk.risk_code },
                ]}
                actions={
                    <>
                        {can.update && (
                            <Link href={route('risk.register.edit', risk.id)} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                        {can.delete && (
                            <button type="button" onClick={destroy} className="btn-secondary text-sm inline-flex items-center gap-2 text-red-600">
                                <span className="material-symbols-outlined text-lg">delete</span> Delete
                            </button>
                        )}
                        <Link href={route('risk.register.index')} className="btn-secondary text-sm">Back to Register</Link>
                    </>
                }
            />

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    icon="error"
                    title="Inherent Score"
                    value={`${metrics.inherentScore}/25`}
                    subtitle={metrics.inherentRating}
                    unavailable={metrics.inherentScore === null}
                />
                <KpiCard
                    icon="warning"
                    title="Residual Score"
                    value={`${metrics.residualScore}/25`}
                    subtitle={metrics.residualRating ? `${metrics.residualRating}${metrics.pendingApproval ? ' (pending approval)' : ''}` : null}
                    unavailable={metrics.residualScore === null}
                />
                <KpiCard
                    icon="trending_down"
                    title="Control Effectiveness"
                    value={`${metrics.controlEffectivenessPct}%`}
                    subtitle={metrics.controlEffectivenessSubtitle}
                    unavailable={metrics.controlEffectivenessPct === null}
                    unavailableLabel="Not measured"
                />
                <KpiCard
                    icon="build_circle"
                    title="Treatment"
                    value={ucfirst(metrics.treatmentStrategy) || 'None'}
                    subtitle={ucfirst(metrics.status)}
                />
            </div>

            <div className="bg-white rounded-t-xl border-b border-gray-200">
                <div className="flex gap-8 px-6 overflow-x-auto">
                    {TABS.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={`border-b-2 py-4 text-sm whitespace-nowrap transition-colors ${
                                tab === key
                                    ? 'border-[#1A365D] text-[#1A365D] font-semibold'
                                    : 'border-transparent text-gray-600 hover:text-[#1A365D]'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="bg-white rounded-b-xl border border-t-0 border-gray-200 p-6">
                {tab === 'overview' && (
                    <>
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div className="lg:col-span-2">
                                <h3 className="text-sm font-semibold text-gray-700 mb-4">Risk Summary</h3>
                                <div className="space-y-4">
                                    <Detail label="RISK ID">
                                        <p className="text-sm font-semibold text-[#1A365D]">{risk.risk_code}</p>
                                    </Detail>
                                    <Detail label="RISK DESCRIPTION">
                                        <p className="text-sm text-gray-700 whitespace-pre-line">{risk.description}</p>
                                    </Detail>
                                    <Detail label="RISK OWNER">
                                        {risk.risk_owner ? (
                                            <div className="flex items-center gap-2 mt-1">
                                                <div className="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-[10px] font-bold">
                                                    {initials(risk.risk_owner)}
                                                </div>
                                                <span className="text-sm">{risk.risk_owner}</span>
                                            </div>
                                        ) : (
                                            <p className="text-sm text-gray-400 mt-1">Unassigned</p>
                                        )}
                                    </Detail>
                                    <Detail label="CATEGORY & BUSINESS UNIT">
                                        <div className="flex gap-2 mt-1 flex-wrap">
                                            {risk.category && (
                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">
                                                    {risk.category}
                                                </span>
                                            )}
                                            {risk.business_unit && (
                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                                    {risk.business_unit}
                                                </span>
                                            )}
                                        </div>
                                    </Detail>
                                    {risk.date_identified && (
                                        <Detail label="DATE IDENTIFIED">
                                            <p className="text-sm text-gray-700">{shortDate(risk.date_identified)}</p>
                                        </Detail>
                                    )}
                                    {risk.entity && (
                                        <Detail label="ENTITY">
                                            <p className="text-sm text-gray-700">{risk.entity}</p>
                                        </Detail>
                                    )}
                                </div>
                            </div>

                            <div>
                                <div className="bg-gradient-to-br from-[#1A365D] to-[#2D4A7A] rounded-xl p-5 text-white mb-4">
                                    <h4 className="text-xs font-semibold text-white/60 uppercase tracking-wide mb-4">Key Metrics</h4>
                                    <div className="space-y-4">
                                        <div>
                                            <p className="text-xs text-white/60">Inherent Score</p>
                                            <p className="text-xl font-bold">
                                                {metrics.inherentScore === null ? (
                                                    <span className="text-sm font-normal text-white/60">Not assessed</span>
                                                ) : (
                                                    <>
                                                        {metrics.inherentScore}{' '}
                                                        <span className="text-sm font-normal text-white/60">({metrics.inherentRating})</span>
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-white/60">Residual Score</p>
                                            <p className="text-xl font-bold">
                                                {metrics.residualScore === null ? (
                                                    <span className="text-sm font-normal text-white/60">Not assessed</span>
                                                ) : (
                                                    <>
                                                        {metrics.residualScore}{' '}
                                                        <span className="text-sm font-normal text-white/60">
                                                            ({metrics.residualRating}{metrics.pendingApproval ? ' · pending' : ''})
                                                        </span>
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-white/60">Control Effectiveness</p>
                                            <p className="text-xl font-bold">
                                                {metrics.controlEffectivenessPct === null ? (
                                                    <span className="text-sm font-normal text-white/60">Not measured</span>
                                                ) : (
                                                    `${metrics.controlEffectivenessPct}%`
                                                )}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-white/60">Treatment Strategy</p>
                                            <p className="text-xl font-bold">{ucfirst(metrics.treatmentStrategy) || 'None'}</p>
                                        </div>
                                    </div>
                                </div>

                                <div className="bg-gray-50 rounded-xl p-4">
                                    <h4 className="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-3">Impact Dimensions</h4>
                                    <div className="space-y-2">
                                        {Object.entries(metrics.impactDimensions ?? {}).map(([label, value]) => {
                                            const score = value ?? 0;
                                            const bar = score >= 4 ? 'bg-red-500' : score >= 3 ? 'bg-yellow-500' : 'bg-green-500';

                                            return (
                                                <div key={label}>
                                                    <div className="flex justify-between text-xs mb-1">
                                                        <span className="text-gray-600">{label}</span>
                                                        <span className="font-semibold">{score}/5</span>
                                                    </div>
                                                    <div className="w-full bg-gray-200 rounded-full h-1.5">
                                                        <div className={`h-1.5 rounded-full ${bar}`} style={{ width: `${(score / 5) * 100}%` }} />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6 pt-6 border-t border-gray-200">
                            {risk.risk_source && <Detail label="RISK SOURCE"><p className="text-sm text-gray-700">{risk.risk_source}</p></Detail>}
                            {risk.risk_velocity && <Detail label="RISK VELOCITY"><p className="text-sm text-gray-700">{ucfirst(risk.risk_velocity)}</p></Detail>}
                            {risk.review_frequency && <Detail label="REVIEW FREQUENCY"><p className="text-sm text-gray-700">{ucfirst(risk.review_frequency)}</p></Detail>}
                            {risk.financial_exposure && (
                                <Detail label="FINANCIAL EXPOSURE">
                                    <p className="text-sm text-gray-700">
                                        NGN {Number(risk.financial_exposure).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                    </p>
                                </Detail>
                            )}
                            {(risk.regulatory_tags ?? []).length > 0 && (
                                <Detail label="REGULATORY ALIGNMENT">
                                    <div className="flex flex-wrap gap-1 mt-1">
                                        {risk.regulatory_tags.map((framework) => (
                                            <span key={framework} className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                                {framework}
                                            </span>
                                        ))}
                                    </div>
                                </Detail>
                            )}
                        </div>

                        {(configuredDetail?.sections?.length ?? 0) > 0 && (
                            <div className="mt-6 pt-6 border-t border-gray-200">
                                <h3 className="text-sm font-semibold text-gray-700 mb-4">Additional Information</h3>
                                <DynamicDetail detail={configuredDetail} />
                            </div>
                        )}
                    </>
                )}

                {tab === 'controls' && (
                    <>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-sm font-semibold text-gray-700">Mapped Controls</h3>
                            <div className="flex gap-2">
                                {can.mapControl && (
                                    <button type="button" onClick={() => setMapping(!mapping)} className="btn-secondary text-xs inline-flex items-center gap-1">
                                        <span className="material-symbols-outlined text-sm">link</span>
                                        {mapping ? 'Cancel' : 'Map Existing'}
                                    </button>
                                )}
                                {newControl && (
                                    <a href={newControl} className="btn-primary text-xs inline-flex items-center gap-1">
                                        <span className="material-symbols-outlined text-sm">add</span> Create New
                                    </a>
                                )}
                            </div>
                        </div>

                        {mapping && can.mapControl && (
                            <form onSubmit={submitMapping} className="mb-4 p-4 border border-gray-200 rounded-lg bg-gray-50 space-y-3">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-600 mb-1">Control <span className="text-red-500">*</span></label>
                                        <select
                                            value={mapForm.data.control_id}
                                            onChange={(e) => mapForm.setData('control_id', e.target.value)}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white"
                                        >
                                            <option value="">Select a control…</option>
                                            {availableControls.map((control) => (
                                                <option key={control.id} value={control.id}>
                                                    {control.control_code} — {(control.name ?? '').slice(0, 60)}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={mapForm.errors.control_id} className="mt-1" />
                                        {availableControls.length === 0 && (
                                            <p className="text-xs text-gray-500 mt-1">
                                                No unmapped controls in the library.{' '}
                                                {newControl && <a href={newControl} className="text-[#1A365D] underline">Create one</a>}.
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-600 mb-1">Control Weight (%)</label>
                                        <input
                                            type="number" min="0" max="100" step="0.01" placeholder="e.g. 25"
                                            value={mapForm.data.control_weight}
                                            onChange={(e) => mapForm.setData('control_weight', e.target.value)}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                        />
                                        <InputError message={mapForm.errors.control_weight} className="mt-1" />
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-gray-600 mb-1">Mapping Rationale</label>
                                    <textarea
                                        rows={2} maxLength={1000} placeholder="Why this control mitigates this risk…"
                                        value={mapForm.data.mapping_rationale}
                                        onChange={(e) => mapForm.setData('mapping_rationale', e.target.value)}
                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                    />
                                </div>
                                <label className="flex items-center gap-2 text-xs text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={mapForm.data.is_key_control}
                                        onChange={(e) => mapForm.setData('is_key_control', e.target.checked)}
                                        className="rounded border-gray-300"
                                    />
                                    Mark as Key Control
                                </label>
                                <div className="flex justify-end">
                                    <button type="submit" disabled={mapForm.processing} className="btn-primary text-xs inline-flex items-center gap-1 disabled:opacity-50">
                                        <span className="material-symbols-outlined text-sm">link</span> Map Control
                                    </button>
                                </div>
                            </form>
                        )}

                        {controls.length > 0 ? (
                            <Table head={['Control Code', 'Control Name', 'Type', 'Effectiveness', 'Key Control', 'Status']}>
                                {controls.map((control) => (
                                    <tr key={control.id} className="hover:bg-blue-50/50">
                                        <td className="font-medium text-[#1A365D]">{control.control_code ?? '-'}</td>
                                        <td className="text-sm">{control.name ?? '-'}</td>
                                        <td className="text-sm">{ucfirst(control.control_type) || '-'}</td>
                                        <td>
                                            {control.effectiveness_percent === null ? (
                                                <span className="text-xs text-gray-400">N/A</span>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    <div className="w-16 bg-gray-200 rounded-full h-1.5">
                                                        <div
                                                            className={`h-1.5 rounded-full ${control.effectiveness_percent >= 75 ? 'bg-green-500' : control.effectiveness_percent >= 40 ? 'bg-yellow-500' : 'bg-red-500'}`}
                                                            style={{ width: `${control.effectiveness_percent}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-xs">{control.effectiveness_percent}% &middot; {control.effectiveness_label}</span>
                                                </div>
                                            )}
                                        </td>
                                        <td>
                                            {control.is_key_control ? (
                                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-100 text-green-700">Key</span>
                                            ) : (
                                                <span className="text-xs text-gray-400">No</span>
                                            )}
                                        </td>
                                        <td><StatusBadge status={control.status} /></td>
                                    </tr>
                                ))}
                            </Table>
                        ) : (
                            <EmptyState
                                icon="shield"
                                title="No controls mapped to this risk."
                                actionLabel={newControl ? 'Create Control' : null}
                                actionHref={newControl}
                            />
                        )}
                    </>
                )}

                {tab === 'assessment' && (
                    <>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-sm font-semibold text-gray-700">Assessment History</h3>
                            {newAssessment && (
                                <a href={newAssessment} className="btn-primary text-sm inline-flex items-center gap-2">
                                    <span className="material-symbols-outlined text-lg">add</span> New Assessment
                                </a>
                            )}
                        </div>
                        {assessments.length > 0 ? (
                            <Table head={['Date', 'Inherent Score', 'Residual Score', 'Assessor', 'Notes']}>
                                {assessments.map((assessment) => (
                                    <tr key={assessment.id} className="hover:bg-blue-50/50">
                                        <td className="text-sm">{shortDate(assessment.assessment_date)}</td>
                                        <td>
                                            <span className="text-sm font-semibold">{assessment.inherent_score ?? '-'}</span>
                                            {assessment.inherent_rating && <RatingBadge rating={assessment.inherent_rating} />}
                                        </td>
                                        <td>
                                            <span className="text-sm font-semibold">{assessment.residual_score ?? '-'}</span>
                                            {assessment.residual_rating && <RatingBadge rating={assessment.residual_rating} />}
                                        </td>
                                        <td className="text-sm">{assessment.assessor ?? '-'}</td>
                                        <td className="text-sm max-w-[300px]"><div className="truncate">{assessment.notes ?? '-'}</div></td>
                                    </tr>
                                ))}
                            </Table>
                        ) : (
                            <EmptyState
                                icon="fact_check"
                                title="No assessments recorded yet."
                                actionLabel={newAssessment ? 'Conduct First Assessment' : null}
                                actionHref={newAssessment}
                            />
                        )}
                    </>
                )}

                {tab === 'treatment' && (
                    <>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-sm font-semibold text-gray-700">Treatment Plans</h3>
                            {newTreatment && (
                                <a href={newTreatment} className="btn-primary text-xs inline-flex items-center gap-1">
                                    <span className="material-symbols-outlined text-sm">add</span> New Treatment Plan
                                </a>
                            )}
                        </div>
                        {treatmentPlans.length > 0 ? (
                            treatmentPlans.map((plan) => (
                                <div key={plan.id} className="bg-gray-50 rounded-xl p-4 mb-4">
                                    <div className="flex items-center justify-between mb-3">
                                        <div>
                                            <p className="text-sm font-semibold text-[#1A365D]">{plan.plan_code ?? 'Treatment Plan'}</p>
                                            <p className="text-xs text-gray-500">Strategy: {ucfirst(plan.strategy) || '-'}</p>
                                        </div>
                                        <StatusBadge status={plan.status} />
                                    </div>
                                    {plan.description && <p className="text-sm text-gray-600">{plan.description}</p>}
                                </div>
                            ))
                        ) : (
                            <EmptyState
                                icon="healing"
                                title="No treatment plans created yet."
                                actionLabel={newTreatment ? 'Create First Treatment Plan' : null}
                                actionHref={newTreatment}
                            />
                        )}
                    </>
                )}

                {tab === 'kris' && (
                    <>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-sm font-semibold text-gray-700">Key Risk Indicators</h3>
                            {newKri && (
                                <a href={newKri} className="btn-primary text-xs inline-flex items-center gap-1">
                                    <span className="material-symbols-outlined text-sm">add</span> New KRI
                                </a>
                            )}
                        </div>
                        {kris.length > 0 ? (
                            <Table head={['KRI Code', 'KRI Name', 'Current Value', 'Threshold', 'Status']}>
                                {kris.map((kri) => (
                                    <tr key={kri.id} className="hover:bg-blue-50/50">
                                        <td className="font-medium text-[#1A365D]">{kri.kri_code ?? '-'}</td>
                                        <td className="text-sm">{kri.kri_name ?? '-'}</td>
                                        <td className="text-sm font-semibold">{kri.current_value ?? '-'}</td>
                                        <td className="text-sm">
                                            {kri.amber_threshold || kri.red_threshold ? (
                                                <>
                                                    <span className="text-yellow-600">{kri.amber_threshold ?? '-'}</span> /{' '}
                                                    <span className="text-red-600">{kri.red_threshold ?? '-'}</span>
                                                </>
                                            ) : (
                                                <span className="text-gray-400">N/A</span>
                                            )}
                                        </td>
                                        <td><StatusBadge status={kri.status} /></td>
                                    </tr>
                                ))}
                            </Table>
                        ) : (
                            <EmptyState
                                icon="speed"
                                title="No KRIs linked to this risk."
                                actionLabel={newKri ? 'Create First KRI' : null}
                                actionHref={newKri}
                            />
                        )}
                    </>
                )}

                {tab === 'attributes' && (
                    <>
                        <h3 className="text-sm font-semibold text-gray-700 mb-4">Configured attributes</h3>
                        {(configured?.sections?.length ?? 0) === 0 ? (
                            <p className="text-sm text-gray-500">
                                No additional fields are configured for the Risk object type.
                            </p>
                        ) : (
                            <form onSubmit={submitAttributes} className="max-w-3xl">
                                <DynamicForm
                                    schema={configured}
                                    values={attributesForm.data.configured_attributes}
                                    errors={attributesForm.errors}
                                    onChange={(code, value) =>
                                        attributesForm.setData('configured_attributes', {
                                            ...attributesForm.data.configured_attributes,
                                            [code]: value,
                                        })
                                    }
                                />
                                {can.update && (
                                    <div className="flex justify-end mt-4">
                                        <button type="submit" disabled={attributesForm.processing} className="btn-primary text-sm inline-flex items-center gap-2 disabled:opacity-50">
                                            <span className="material-symbols-outlined text-lg">save</span> Save Attributes
                                        </button>
                                    </div>
                                )}
                            </form>
                        )}
                    </>
                )}

                {tab === 'history' && (
                    <>
                        <h3 className="text-sm font-semibold text-gray-700 mb-4">Audit Trail</h3>
                        {auditTrail.length > 0 ? (
                            <div className="space-y-4">
                                {auditTrail.map((trail) => {
                                    const tone = trail.action_type === 'created'
                                        ? 'bg-green-100 text-green-700'
                                        : trail.action_type === 'deleted'
                                            ? 'bg-red-100 text-red-700'
                                            : 'bg-blue-100 text-blue-700';
                                    const icon = trail.action_type === 'created'
                                        ? 'add_circle'
                                        : trail.action_type === 'deleted' ? 'delete' : 'edit';

                                    return (
                                        <div key={trail.id} className="flex gap-4 items-start">
                                            <div className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${tone}`}>
                                                <span className="material-symbols-outlined text-sm">{icon}</span>
                                            </div>
                                            <div className="flex-1">
                                                <p className="text-sm font-medium text-gray-700">
                                                    {ucfirst(trail.action_type)} &mdash; {trail.field_changed}
                                                </p>
                                                <p className="text-xs text-gray-500">{dateTime(trail.changed_at)}</p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <EmptyState icon="history" title="No audit trail records." />
                        )}
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
