import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The due diligence checklist — FR-DDL-02, FR-DDL-08, FR-DDL-09.
 *
 * THE BLOCKERS ARE NAMED ON THE PAGE, not discovered on submit. A disabled
 * button with a tooltip teaches a user to look for whoever can override it; a
 * panel listing the mandatory items still open, each with its own close-or-
 * waive action, teaches them what to do.
 *
 * A LAPSED WAIVER LOOKS OPEN AGAIN, because it is. A waiver that outlived its
 * expiry and still read as settled would be worse than no waiver at all — it
 * would look like a decision somebody was still standing behind.
 */
export default function Show({ engagement, checklist, can = {} }) {
    const [waiving, setWaiving] = useState(null);

    if (!checklist) {
        return (
            <AppLayout title="Due diligence">
                <Head title={`Due diligence — ${engagement.reference}`} />
                <PageHeader
                    title="Due diligence"
                    subtitle={<Link className="underline" href={engagement.url}>{engagement.reference} — {engagement.name}</Link>}
                />
                <div className="card p-8 text-center">
                    <p className="text-sm text-gray-600">
                        No checklist has been generated for this engagement yet. It is scoped by tier — a
                        {' '}{engagement.tier_label ?? 'not yet tiered'} engagement gets the items that tier requires.
                    </p>
                    {can.manage && (
                        <button type="button" className="btn btn-primary mt-4"
                            onClick={() => router.post(route('tprm.due-diligence.generate', engagement.id))}>
                            Generate the checklist
                        </button>
                    )}
                </div>
            </AppLayout>
        );
    }

    const progress = checklist.progress ?? {};
    const blockers = (checklist.items ?? []).filter((item) => item.is_mandatory && !item.settled);
    const categories = [...new Set((checklist.items ?? []).map((item) => item.category))];

    return (
        <AppLayout title="Due diligence">
            <Head title={`Due diligence — ${engagement.reference}`} />

            <PageHeader
                title="Due diligence"
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <Link className="underline" href={engagement.url}>{engagement.reference}</Link>
                        <span className="text-gray-300">·</span>
                        <span>
                            scoped for a {checklist.tier_at_generation ?? 'untiered'} engagement
                            {checklist.tier_at_generation !== engagement.tier && engagement.tier && (
                                <span className="text-amber-700">
                                    {' '}(now {engagement.tier})
                                </span>
                            )}
                        </span>
                    </span>
                }
                actions={can.manage && checklist.status === 'open' ? (
                    <button type="button" className="btn btn-primary"
                        disabled={!checklist.can_complete}
                        onClick={() => router.post(route('tprm.due-diligence.complete', checklist.id))}>
                        Complete due diligence
                    </button>
                ) : null}
            />

            {blockers.length > 0 && (
                <div className="mb-6 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-medium">
                        {blockers.length} mandatory item(s) are still open, so due diligence cannot be
                        completed.
                    </p>
                    <ul className="mt-2 list-disc space-y-0.5 pl-5">
                        {blockers.map((item) => (
                            <li key={item.id}>
                                {item.code} — {item.title}
                                {item.waiver?.lapsed && (
                                    <span className="text-red-700"> (its waiver expired on {item.waiver.expires_at})</span>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 text-xs">
                        Each closes with evidence, or is waived by an approver with a reason and an expiry.
                    </p>
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Settled" value={`${progress.settled ?? 0} of ${progress.total ?? 0}`} />
                <Tile label="Mandatory" value={progress.mandatory ?? 0} hint="at this tier" />
                <Tile label="Still blocking" value={progress.blocking ?? 0}
                    tone={progress.blocking ? 'warn' : null} />
                <Tile label="Waived" value={progress.waived ?? 0} hint="with an approver and an expiry" />
            </div>

            {categories.map((category) => (
                <div key={category} className="card mb-4">
                    <div className="border-b border-gray-100 px-5 py-3">
                        <h3 className="text-sm font-semibold capitalize text-gray-900">
                            {String(category).replace(/_/g, ' ')}
                        </h3>
                    </div>
                    <div className="divide-y divide-gray-100">
                        {checklist.items.filter((item) => item.category === category).map((item) => (
                            <Item key={item.id} item={item} can={can} onWaive={() => setWaiving(item)} />
                        ))}
                    </div>
                </div>
            ))}

            {waiving && <WaiverDialog item={waiving} onClose={() => setWaiving(null)} />}
        </AppLayout>
    );
}

function Item({ item, can, onWaive }) {
    return (
        <div className="p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm text-gray-900">
                        <span className="font-mono text-xs text-gray-500">{item.code}</span>{' '}
                        {item.title}
                        {item.is_mandatory && (
                            <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700">
                                mandatory
                            </span>
                        )}
                    </p>
                    <p className="mt-0.5 text-xs text-gray-500">
                        {item.owner ?? 'Unassigned'}
                        {item.evidence ? ` · evidence: ${item.evidence.title}` : ''}
                    </p>
                    {item.waiver && (
                        <p className={`mt-1 text-xs ${item.waiver.lapsed ? 'text-red-700' : 'text-gray-600'}`}>
                            {item.waiver.lapsed ? 'Waiver expired' : 'Waived'} until {item.waiver.expires_at}
                            {item.waiver.approver ? ` by ${item.waiver.approver}` : ''} — {item.waiver.reason}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    <StatusChip item={item} />
                    {can.manage && !item.settled && (
                        <button type="button" className="btn btn-secondary text-xs"
                            onClick={() => router.post(route('tprm.due-diligence.items.complete', item.id))}>
                            Mark done
                        </button>
                    )}
                    {can.waive && !item.settled && (
                        <button type="button" className="btn btn-secondary text-xs" onClick={onWaive}>
                            Waive
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

function StatusChip({ item }) {
    const [label, tone] = item.waiver?.lapsed
        ? ['Waiver lapsed', 'bg-red-100 text-red-800']
        : {
            complete: ['Done', 'bg-green-100 text-green-800'],
            waived: ['Waived', 'bg-amber-100 text-amber-800'],
            not_applicable: ['Not applicable', 'bg-gray-100 text-gray-600'],
            in_progress: ['In progress', 'bg-blue-100 text-blue-800'],
        }[item.status] ?? ['Open', 'bg-gray-100 text-gray-600'];

    return <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-xl font-semibold ${tone === 'warn' ? 'text-amber-700' : 'text-gray-900'}`}>
                {value}
            </p>
            {hint && <p className="mt-0.5 text-xs text-gray-500">{hint}</p>}
        </div>
    );
}

function WaiverDialog({ item, onClose }) {
    const form = useForm({ reason: '', expires_at: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.due-diligence.items.waive', item.id), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-xl p-6">
                <h2 className="text-base font-semibold text-gray-900">Waive {item.code}</h2>
                <p className="mt-1 text-sm text-gray-600">{item.title}</p>
                <p className="mt-2 text-xs text-gray-600">
                    A waiver needs a reason and an expiry. Without an expiry it is a skip with paperwork
                    attached — nobody revisits it, and the item never comes back.
                </p>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Why does this not apply here?</span>
                    <textarea rows={4} className="input mt-1" value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)} />
                    {form.errors.reason && <p className="mt-1 text-xs text-red-600">{form.errors.reason}</p>}
                </label>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Expires</span>
                    <input type="date" className="input mt-1" value={form.data.expires_at}
                        onChange={(event) => form.setData('expires_at', event.target.value)} />
                    {form.errors.expires_at && <p className="mt-1 text-xs text-red-600">{form.errors.expires_at}</p>}
                </label>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>Waive</button>
                </div>
            </form>
        </div>
    );
}
