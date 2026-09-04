import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { badgeClass } from '@/Pages/MyTasks/Index';

const ICON = { approve: ['bg-green-100', 'text-green-600', 'check'], reject: ['bg-red-100', 'text-red-600', 'close'] };

function ActionForm({ url, canCancel }) {
    const form = useForm({ action: '', comments: '' });
    const act = (action) => {
        if (action === 'cancel' && !window.confirm('Cancel this workflow? Open steps are abandoned.')) return;
        form.transform((data) => ({ ...data, action }));
        form.post(url, { preserveScroll: true });
    };
    const btn = (action, label, cls) => (
        <button type="button" disabled={form.processing} onClick={() => act(action)} className={`px-4 py-2 rounded-lg text-sm font-medium ${cls}`}>{label}</button>
    );

    return (
        <form onSubmit={(e) => e.preventDefault()} className="space-y-3">
            <textarea value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} rows={2} className="form-textarea w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Comments..." />
            <InputError message={form.errors.comments || form.errors.action} />
            <div className="flex flex-wrap gap-2">
                {btn('approve', 'Approve', 'bg-green-600 text-white')}
                {btn('reject', 'Reject', 'bg-red-600 text-white')}
                {btn('return', 'Return for rework', 'bg-amber-500 text-white')}
                {btn('escalate', 'Escalate', 'bg-orange-600 text-white')}
                {btn('comment', 'Comment only', 'bg-gray-200 text-gray-700')}
                {canCancel && btn('cancel', 'Cancel workflow', 'border border-gray-300 text-gray-600')}
            </div>
        </form>
    );
}

/** One running or finished workflow (migration Phase 3.7: risk/workflows/show-instance.blade.php). */
export default function ShowInstance({ instance, steps, history, actionable, canAct, canCancel, urls }) {
    const { flash } = usePage().props;

    return (
        <AuthenticatedLayout title="Workflows">
            <Head title="Workflow instance" />
            <div className="max-w-4xl mx-auto space-y-6">
                <div>
                    <h1 className="text-xl font-bold text-gray-900">{instance.name}</h1>
                    <p className="text-sm text-gray-500">
                        {instance.entity_type_label} #{instance.entity_id}{instance.subject.title ? ` — ${instance.subject.title}` : ''} · version {instance.version} · started {instance.started_human}
                    </p>
                </div>

                {flash?.error && <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{flash.error}</div>}

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-gray-900">Steps</h3>
                        <span className={`badge ${badgeClass(instance.status.color)}`}>{instance.status.label}</span>
                    </div>
                    <ol className="mt-4 space-y-2">
                        {steps.map((s) => (
                            <li key={s.code} className="flex items-start gap-3 text-sm">
                                <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${s.done ? 'bg-green-500' : s.current ? 'bg-blue-500' : 'bg-gray-300'}`} />
                                <div>
                                    <p className="font-medium text-gray-800">{s.name}</p>
                                    {s.task && (
                                        <p className="text-xs text-gray-500">
                                            {s.task.status.label}{s.task.outcome ? ` — ${s.task.outcome}` : ''}{s.task.who ? ` · ${s.task.who}` : ''}
                                            {s.task.overdue && <span className="text-red-600 font-medium"> · {s.task.hours_overdue}h overdue</span>}
                                            {s.task.delegated_by && <span className="text-blue-600"> · delegated by {s.task.delegated_by}</span>}
                                        </p>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                </div>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h3 className="text-sm font-semibold text-gray-900 mb-4">Action history</h3>
                    {history.length === 0 && <p className="text-sm text-gray-400">Nothing has happened yet.</p>}
                    {history.map((h, idx) => {
                        const [bg, tone, icon] = ICON[h.action] || ['bg-gray-100', 'text-gray-500', 'comment'];
                        return (
                            <div key={h.id} className={`flex gap-4 py-3 ${idx < history.length - 1 ? 'border-b border-gray-50' : ''}`}>
                                <div className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${bg}`}><span className={`material-symbols-outlined text-sm ${tone}`}>{icon}</span></div>
                                <div>
                                    <p className="text-sm"><span className="font-medium">{h.actor}</span> <span className="text-gray-500">{h.action}</span> <span className="text-gray-400">{h.stage}</span></p>
                                    {h.comments && <p className="text-xs text-gray-600 mt-1">{h.comments}</p>}
                                    <p className="text-xs text-gray-400 mt-1">{h.at_human}</p>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {instance.open && canAct ? (
                    <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <h3 className="text-sm font-semibold text-gray-900 mb-1">Take action</h3>
                        <p className="mb-4 text-xs text-gray-500">{actionable.join(', ')}</p>
                        <ActionForm url={urls.act} canCancel={canCancel} />
                    </div>
                ) : instance.open ? (
                    <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">There is no open step here that you can act on.</div>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
