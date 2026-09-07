import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import TierBadge from '@/Components/Tprm/TierBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Third-Party Profile (TRD §11).
 *
 * The header carries the aggregate residual, a tier chip per engagement and
 * the data-confidence badge. Where a figure has not been computed it says so
 * — "Not scored" rather than 0.0 — because an unscored vendor does not have a
 * residual risk of nought and the product's standard §5 forbids showing one.
 */
export default function Show({ thirdParty, engagements = [], can = {} }) {
    const [tab, setTab] = useState('overview');
    const editUrl = tryRoute('tprm.third-parties.edit', thirdParty.uuid);
    const intakeUrl = tryRoute('tprm.intake.create');

    const tabs = [
        ['overview', 'Overview'],
        ['engagements', `Engagements (${engagements.length})`],
        ['contacts', `Contacts & Ownership (${(thirdParty.contacts?.length ?? 0) + (thirdParty.ownership?.length ?? 0)})`],
        ['locations', `Locations (${thirdParty.locations?.length ?? 0})`],
    ];

    return (
        <AppLayout title={thirdParty.legal_name}>
            <Head title={thirdParty.legal_name} />

            <PageHeader
                title={thirdParty.legal_name}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        {thirdParty.registration_number && <span className="font-mono text-xs">RC {thirdParty.registration_number}</span>}
                        {thirdParty.category && <><span className="text-gray-300">·</span><span>{thirdParty.category}</span></>}
                        {thirdParty.country_of_incorporation && <><span className="text-gray-300">·</span><span>{thirdParty.country_of_incorporation}</span></>}
                    </span>
                }
                actions={
                    <div className="flex items-center gap-2">
                        <StatusBadge status={thirdParty.status} />
                        {can.raiseIntake && intakeUrl && (
                            <a href={`${intakeUrl}?third_party=${thirdParty.id}`} className="btn-secondary text-sm">
                                Raise an intake
                            </a>
                        )}
                        {can.edit && editUrl && <a href={editUrl} className="btn-primary text-sm">Edit</a>}
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Aggregate residual" value={thirdParty.aggregate_residual ?? 'Not scored'} />
                <Tile label="Data confidence" value={thirdParty.data_confidence ?? 'Not assessed'} />
                <Tile label="Live engagements" value={engagements.length} />
                <Tile label="Last screened" value={thirdParty.last_screened_at ?? 'Never'} />
            </div>

            <div className="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
                {tabs.map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setTab(key)}
                        className={`px-3 py-2 text-sm font-medium ${
                            tab === key ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'overview' && (
                <div className="card p-5">
                    <dl className="grid grid-cols-2 gap-x-8 gap-y-4 text-sm lg:grid-cols-3">
                        <Field label="Legal name" value={thirdParty.legal_name} />
                        <Field label="Trading name" value={thirdParty.trading_name} />
                        <Field label="Entity type" value={thirdParty.entity_type} />
                        <Field label="RC number" value={thirdParty.registration_number} />
                        <Field label="TIN" value={thirdParty.tax_id} />
                        <Field label="LEI" value={thirdParty.lei} />
                        <Field label="Country of incorporation" value={thirdParty.country_of_incorporation} />
                        <Field label="Country of HQ" value={thirdParty.country_of_hq} />
                        <Field label="Website" value={thirdParty.website} />
                        <Field label="Ultimate parent" value={thirdParty.ultimate_parent} />
                        <Field label="Intra-group" value={thirdParty.is_intra_group ? 'Yes' : 'No'} />
                        <Field label="Category" value={thirdParty.category} />
                        {/* FR-TPR-06: neither owner may be vacant while the entity is
                            active, so an empty one is called out rather than dashed. */}
                        <Field label="Relationship owner" value={thirdParty.relationship_owner} warnIfEmpty />
                        <Field label="Oversight owner" value={thirdParty.oversight_owner} warnIfEmpty />
                    </dl>
                    {thirdParty.notes && (
                        <div className="mt-6 border-t border-gray-100 pt-4">
                            <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Notes</h4>
                            <p className="mt-1 whitespace-pre-line text-sm text-gray-700">{thirdParty.notes}</p>
                        </div>
                    )}
                </div>
            )}

            {tab === 'engagements' && (
                <div className="card p-5">
                    {engagements.length ? (
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="pb-2 font-medium">Reference</th>
                                    <th className="pb-2 font-medium">Engagement</th>
                                    <th className="pb-2 font-medium">Tier</th>
                                    <th className="pb-2 font-medium">Status</th>
                                    <th className="pb-2 text-right font-medium">Inherent</th>
                                    <th className="pb-2 text-right font-medium">Residual</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {engagements.map((e) => (
                                    <tr key={e.id}>
                                        <td className="py-2">
                                            <a href={e.url} className="font-mono text-xs text-blue-700 hover:underline">{e.reference}</a>
                                        </td>
                                        <td className="py-2">{e.name}</td>
                                        <td className="py-2"><TierBadge tier={e.tier} label={e.tier_label} size="sm" /></td>
                                        <td className="py-2"><StatusBadge status={e.status} /></td>
                                        <td className="py-2 text-right tabular-nums">{e.inherent_score ?? '—'}</td>
                                        <td className="py-2 text-right tabular-nums text-gray-500">
                                            {e.residual_score ?? 'Not yet scored'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <p className="text-sm text-gray-500">
                            No engagements yet. Risk is assessed per engagement, so this third party has no
                            tier until one is raised.
                        </p>
                    )}
                </div>
            )}

            {tab === 'contacts' && (
                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-gray-900">Contacts</h3>
                        {thirdParty.contacts?.length ? (
                            <ul className="divide-y divide-gray-100 text-sm">
                                {thirdParty.contacts.map((c) => (
                                    <li key={c.id} className="py-2">
                                        <p className="font-medium text-gray-900">{c.name}</p>
                                        <p className="text-xs capitalize text-gray-500">{c.role_type?.replace('_', ' ')}</p>
                                        {c.email && <p className="text-xs text-gray-600">{c.email}</p>}
                                    </li>
                                ))}
                            </ul>
                        ) : <p className="text-sm text-gray-500">No contacts recorded.</p>}
                    </div>

                    <div className="card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-gray-900">Ownership and control</h3>
                        <p className="mb-3 text-xs text-gray-500">
                            Directors and beneficial owners are screened individually — CBN AML/CFT Reg. 29.
                        </p>
                        {thirdParty.ownership?.length ? (
                            <ul className="divide-y divide-gray-100 text-sm">
                                {thirdParty.ownership.map((o) => (
                                    <li key={o.id} className="flex items-center justify-between py-2">
                                        <span>
                                            <span className="font-medium text-gray-900">{o.holder_name}</span>
                                            <span className="ml-2 text-xs capitalize text-gray-500">{o.relationship}</span>
                                        </span>
                                        <span className="flex items-center gap-2 text-xs">
                                            {o.percentage && <span className="tabular-nums text-gray-600">{o.percentage}%</span>}
                                            {o.is_pep && (
                                                <span className="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">PEP</span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : <p className="text-sm text-gray-500">No ownership recorded.</p>}
                    </div>
                </div>
            )}

            {tab === 'locations' && (
                <div className="card p-5">
                    {thirdParty.locations?.length ? (
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="pb-2 font-medium">Role</th>
                                    <th className="pb-2 font-medium">City</th>
                                    <th className="pb-2 font-medium">Country</th>
                                    <th className="pb-2 font-medium">Data processing</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {thirdParty.locations.map((l) => (
                                    <tr key={l.id}>
                                        <td className="py-2 capitalize">{l.role?.replace('_', ' ')}</td>
                                        <td className="py-2">{l.city ?? '—'}</td>
                                        <td className="py-2">{l.country ?? '—'}</td>
                                        <td className="py-2">{l.is_data_processing_location ? 'Yes' : 'No'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : <p className="text-sm text-gray-500">No locations recorded.</p>}
                </div>
            )}
        </AppLayout>
    );
}

function Tile({ label, value }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">{value}</p>
        </div>
    );
}

function Field({ label, value, warnIfEmpty = false }) {
    const empty = value === null || value === undefined || value === '';

    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className={`mt-0.5 ${empty && warnIfEmpty ? 'font-medium text-amber-700' : 'text-gray-900'}`}>
                {empty ? (warnIfEmpty ? 'Not assigned' : '—') : value}
            </dd>
        </div>
    );
}
