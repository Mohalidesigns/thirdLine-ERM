import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';

function DecisionForm({ task, urls }) {
    const form = useForm({ outcome: '', comments: '' });
    const decide = (outcome) => {
        form.transform((data) => ({ ...data, outcome }));
        form.post(urls.act, { preserveScroll: true });
    };

    return (
        <form onSubmit={(e) => e.preventDefault()} className="mt-5 space-y-3">
            <label className="block">
                <span className="text-[11px] font-medium uppercase tracking-wide text-gray-500">Comments {task.comments_required && <span className="text-red-600">(required)</span>}</span>
                <textarea value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} rows={3} required={task.comments_required} className="form-textarea mt-1 w-full rounded-lg border-gray-300 text-sm" />
                <InputError message={form.errors.comments || form.errors.outcome} className="mt-1" />
            </label>
            <div className="flex flex-wrap gap-2">
                <button type="button" disabled={form.processing} onClick={() => decide('approve')} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Approve</button>
                <button type="button" disabled={form.processing} onClick={() => decide('reject')} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">Reject</button>
            </div>
        </form>
    );
}

function DelegateForm({ delegates, url }) {
    const form = useForm({ delegate_to: '', reason: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(url, { preserveScroll: true }); }} className="space-y-2">
            <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500">Delegate</p>
            <select value={form.data.delegate_to} onChange={(e) => form.setData('delegate_to', e.target.value)} required className="form-select w-full rounded-lg border-gray-300 text-sm">
                <option value="">Choose a colleague…</option>
                {delegates.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            <InputError message={form.errors.delegate_to} />
            <input type="text" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required placeholder="Why" className="form-input w-full rounded-lg border-gray-300 text-sm" />
            <InputError message={form.errors.reason} />
            <button type="submit" disabled={form.processing} className="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Hand it over</button>
            <p className="text-[11px] text-gray-400">It moves onto their list and off yours.</p>
        </form>
    );
}

function ReturnForm({ url }) {
    const form = useForm({ reason: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(url, { preserveScroll: true }); }} className="space-y-2">
            <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500">Return for rework</p>
            <textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={2} required placeholder="What needs to change" className="form-textarea w-full rounded-lg border-gray-300 text-sm" />
            <InputError message={form.errors.reason} />
            <button type="submit" disabled={form.processing} className="w-full rounded-lg border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50">Send back</button>
            <p className="text-[11px] text-gray-400">The process moves back to the previous step; any parallel review is abandoned.</p>
        </form>
    );
}

/** One decision (migration Phase 3.7: risk/my-tasks/show.blade.php). */
export default function Show({ task, canAct, delegates, steps, history, urls }) {
    const { flash } = usePage().props;
    const header = (
        <nav className="flex items-center gap-1 text-xs text-gray-500">
            <Link href={urls.index} className="hover:text-gray-800">My tasks</Link>
            <span className="text-gray-300">›</span>
            <span className="font-medium text-gray-800">{task.name}</span>
        </nav>
    );

    return (
        <AuthenticatedLayout title="My work" header={header}>
            <Head title={`Task — ${task.name}`} />
            {flash?.error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{flash.error}</div>}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <div className="rounded-xl border border-gray-200 bg-white p-5">
                        <div className="flex items-start justify-between">
                            <div>
                                <h2 className="text-lg font-bold text-gray-900">{task.name}</h2>
                                <p className="text-sm text-gray-500">{task.process}</p>
                            </div>
                            {task.due_full && (
                                <div className="text-right">
                                    <p className="text-[11px] uppercase tracking-wide text-gray-500">Due</p>
                                    <p className={`text-sm font-semibold ${task.overdue ? 'text-red-600' : 'text-gray-900'}`}>{task.due_full}</p>
                                    {task.overdue && <p className="text-[11px] text-red-500">{task.hours_overdue} hours over</p>}
                                </div>
                            )}
                        </div>

                        {task.instructions && <p className="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">{task.instructions}</p>}

                        {task.subject && (
                            <div className="mt-4 rounded-lg border border-gray-200 p-3">
                                <p className="text-[11px] uppercase tracking-wide text-gray-500">The record</p>
                                <p className="text-sm font-medium text-gray-900">{task.subject.reference}</p>
                                <p className="text-sm text-gray-600">{task.subject.title}</p>
                            </div>
                        )}

                        {canAct ? (
                            <>
                                <DecisionForm task={task} urls={urls} />
                                <div className="mt-4 grid grid-cols-1 gap-3 border-t border-gray-100 pt-4 md:grid-cols-2">
                                    {task.allow_delegate && <DelegateForm delegates={delegates} url={urls.delegate} />}
                                    {task.allow_return && <ReturnForm url={urls.return} />}
                                </div>
                            </>
                        ) : (
                            <p className="mt-5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">This decision is not yours to make.</p>
                        )}
                    </div>
                </div>

                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Steps</h3>
                        <ol className="mt-3 space-y-2">
                            {steps.map((s) => (
                                <li key={s.id} className="flex items-start gap-2 text-xs">
                                    <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${s.open ? 'bg-amber-500' : 'bg-green-500'}`} />
                                    <div>
                                        <p className="font-medium text-gray-800">{s.name}</p>
                                        <p className="text-gray-500">{s.status.label}{s.outcome ? ` — ${s.outcome}` : ''}{s.who ? ` · ${s.who}` : ''}</p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">History</h3>
                        <ul className="mt-3 space-y-2 text-xs">
                            {history.map((h) => (
                                <li key={h.id}>
                                    <span className="font-medium text-gray-800">{h.action}</span>
                                    <span className="text-gray-500"> — {h.actor}, {h.at_human}</span>
                                    {h.comments && <p className="text-gray-500">{h.comments}</p>}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
