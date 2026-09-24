import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { formatIncidentDateTime } from '@/Components/Bcms/dateDisplay';

const FIX_LINKS = {
    tasks: (urls) => urls.crisis_room_url,
    notifications: (urls) => urls.notifications_url,
    reportability: (urls) => urls.crisis_room_url,
    plans: (urls) => urls.crisis_room_url,
};

/**
 * `docs/bcms/screens/incident-stand-down.md` — make it structurally
 * impossible to close an incident with an unresolved obligation, an open
 * task nobody decided to drop, or no communication telling anyone it is over.
 */
export default function StandDown({
    incident = {}, checklist = [], plan_activations: planActivations = [], audience_groups: audienceGroups = [],
    submit_url: submitUrl, crisis_room_url: crisisRoomUrl, notifications_url: notificationsUrl,
}) {
    const alreadyClosed = incident.status === 'closed';

    const blockingUnmet = checklist.filter((c) => !c.met && c.key !== 'all_clear');
    const readyToSubmit = blockingUnmet.length === 0;
    const firstUnmet = blockingUnmet[0];

    const form = useForm({
        all_clear_message: '', reason: '', dispatch_all_clear: audienceGroups.length > 0,
        plans_remaining_active: {},
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(submitUrl);
    };

    if (alreadyClosed) {
        return (
            <AppLayout title={`Stand down — ${incident.reference}`}>
                <Head title={`Stand down — ${incident.reference}`} />
                <PageHeader title={`Stand down — ${incident.reference}`} />
                <p className="rounded border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    This incident was already closed at {formatIncidentDateTime(incident.closed_at) ?? 'an unknown time'}.
                </p>
                <Link href={crisisRoomUrl} className="mt-3 inline-block text-sm text-blue-700 underline">Back to the crisis room</Link>
            </AppLayout>
        );
    }

    return (
        <AppLayout title={`Stand down — ${incident.reference}`}>
            <Head title={`Stand down — ${incident.reference}`} />

            <PageHeader title={`Stand down — ${incident.reference}`} subtitle={incident.title} />

            <ol className="mb-6 space-y-2">
                {checklist.map((item) => (
                    <li key={item.key}
                        className={`flex items-start gap-2 rounded border p-3 text-sm ${item.met ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'}`}>
                        <span aria-hidden="true" className="mt-0.5 font-semibold">{item.met ? '✓' : '✗'}</span>
                        <span className="flex-1">
                            <span className="font-medium">{item.label}</span>
                            {!item.met && (
                                <span className="block text-xs">
                                    {item.message}
                                    {FIX_LINKS[item.key] && item.key !== 'all_clear' && (
                                        <>
                                            {' '}
                                            <Link href={FIX_LINKS[item.key]({ crisis_room_url: crisisRoomUrl, notifications_url: notificationsUrl })} className="underline">
                                                Fix it
                                            </Link>
                                        </>
                                    )}
                                </span>
                            )}
                        </span>
                    </li>
                ))}
            </ol>

            <form onSubmit={submit} className="space-y-4 rounded border border-slate-200 bg-white p-4">
                <div>
                    <label htmlFor="all_clear_message" className="block text-sm font-medium text-slate-700">All-clear message</label>
                    <textarea id="all_clear_message" required rows={4} className="form-textarea mt-1 w-full"
                        value={form.data.all_clear_message} onChange={(e) => form.setData('all_clear_message', e.target.value)} />
                    {form.errors.all_clear_message && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.all_clear_message}</p>}
                </div>
                <div>
                    <label htmlFor="reason" className="block text-sm font-medium text-slate-700">Reason / summary of resolution</label>
                    <textarea id="reason" required rows={3} className="form-textarea mt-1 w-full"
                        value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    {form.errors.reason && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.reason}</p>}
                </div>

                {planActivations.length > 0 && (
                    <div>
                        <p className="text-sm font-medium text-slate-700">Plan activations remaining open</p>
                        {planActivations.map((a) => (
                            <div key={a.id} className="mt-1">
                                <label htmlFor={`plan-${a.id}`} className="block text-xs text-slate-600">{a.plan} — remains active?</label>
                                <input id={`plan-${a.id}`} type="text" placeholder="Leave blank to deactivate, or state why it remains active"
                                    className="form-input mt-1 w-full text-sm"
                                    value={form.data.plans_remaining_active[a.id] ?? ''}
                                    onChange={(e) => form.setData('plans_remaining_active', { ...form.data.plans_remaining_active, [a.id]: e.target.value })} />
                            </div>
                        ))}
                    </div>
                )}

                {audienceGroups.length > 0 && (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="form-checkbox" checked={form.data.dispatch_all_clear}
                            onChange={(e) => form.setData('dispatch_all_clear', e.target.checked)} />
                        Dispatch the all-clear as a communication
                    </label>
                )}

                <div className="flex items-center gap-3">
                    <button type="submit" disabled={!readyToSubmit || form.processing}
                        title={!readyToSubmit ? firstUnmet?.message : undefined}
                        className="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
                        Stand down and close
                    </button>
                    <Link href={crisisRoomUrl} className="text-sm text-slate-600 underline">Back to crisis room</Link>
                </div>
            </form>
        </AppLayout>
    );
}
