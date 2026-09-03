import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import Widget from '@/Components/Widget';

const fmt = (v, digits = 1) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en', { minimumFractionDigits: digits, maximumFractionDigits: digits }));
const title = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : '');

const STATUS = {
    within: { dot: 'bg-green-500', text: 'text-green-600', row: '' },
    near_limit: { dot: 'bg-yellow-500', text: 'text-yellow-600', row: 'border-l-4 border-l-yellow-500' },
    breach: { dot: 'bg-red-500', text: 'text-red-600', row: 'border-l-4 border-l-red-500 bg-red-50/50' },
};
const TREND = { up: ['trending_up', 'text-red-500'], down: ['trending_down', 'text-green-500'], flat: ['trending_flat', 'text-gray-400'] };

const EMPTY_FORM = {
    risk_category_id: '', appetite_level: '', appetite_statement: '', tolerance_metric: '', unit_of_measure: 'percentage',
    target_min: '0', target_max: '', max_tolerance: '', capacity: '', current_position: '',
    effective_date: new Date().toISOString().slice(0, 10), expiry_date: '',
};

function Field({ label, required, error, children, className = '' }) {
    return (
        <div className={className}>
            <InputLabel value={label} className="text-xs font-semibold text-gray-700 mb-1">
                {required && <span className="text-red-500"> *</span>}
            </InputLabel>
            {children}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * The create/edit form. `mode` decides the route; the category selector
 * only exists on create (a statement never changes category).
 */
function StatementForm({ mode, initial, categories, levels, onClose, updateUrl }) {
    const form = useForm(initial);
    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (mode === 'create') form.post(route('risk.appetite.store'), options);
        else form.put(updateUrl, options);
    };
    const input = (name, type = 'text', extra = {}) => (
        <TextInput type={type} value={form.data[name] ?? ''} onChange={(e) => form.setData(name, e.target.value)} className="w-full text-sm" {...extra} />
    );

    return (
        <form onSubmit={submit} className="p-6">
            <h2 className="text-sm font-bold text-[var(--color-primary)] mb-4 flex items-center gap-2">
                <span className="material-symbols-outlined text-lg">{mode === 'create' ? 'add_circle' : 'edit'}</span>
                {mode === 'create' ? 'Add appetite statement' : `Edit appetite statement — ${initial.risk_category}`}
            </h2>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {mode === 'create' && (
                    <Field label="Risk category" required error={form.errors.risk_category_id}>
                        <select value={form.data.risk_category_id} onChange={(e) => form.setData('risk_category_id', e.target.value)} required className="form-select w-full text-sm rounded-lg border-gray-200">
                            <option value="">Select category…</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </Field>
                )}
                <Field label="Appetite level" required error={form.errors.appetite_level}>
                    <select value={form.data.appetite_level} onChange={(e) => form.setData('appetite_level', e.target.value)} required className="form-select w-full text-sm rounded-lg border-gray-200">
                        <option value="">Select level…</option>
                        {levels.map((l) => <option key={l} value={l}>{title(l)}</option>)}
                    </select>
                </Field>
                <Field label="Tolerance metric" required error={form.errors.tolerance_metric}>{input('tolerance_metric', 'text', { required: true, maxLength: 255, placeholder: 'e.g. NPL Ratio' })}</Field>
                <Field label="Appetite statement" required error={form.errors.appetite_statement} className="md:col-span-3">
                    <textarea value={form.data.appetite_statement} onChange={(e) => form.setData('appetite_statement', e.target.value)} rows={2} required maxLength={2000} placeholder="The Board's stated appetite for this risk category" className="form-textarea w-full text-sm rounded-lg border-gray-200" />
                </Field>
                <Field label="Target min" required error={form.errors.target_min}>{input('target_min', 'number', { required: true, min: 0, step: '0.01' })}</Field>
                <Field label="Target max" required error={form.errors.target_max}>{input('target_max', 'number', { required: true, min: 0, step: '0.01' })}</Field>
                <Field label="Max tolerance (hard limit)" required error={form.errors.max_tolerance}>{input('max_tolerance', 'number', { required: true, min: 0, step: '0.01' })}</Field>
                <Field label="Capacity" error={form.errors.capacity}>{input('capacity', 'number', { min: 0, step: '0.01' })}</Field>
                <Field label="Current position" error={form.errors.current_position}>{input('current_position', 'number', { min: 0, step: '0.01' })}</Field>
                <Field label="Unit of measure" error={form.errors.unit_of_measure}>{input('unit_of_measure', 'text', { maxLength: 100 })}</Field>
                <Field label="Effective date" required error={form.errors.effective_date}>{input('effective_date', 'date', { required: true })}</Field>
                <Field label="Expiry date" error={form.errors.expiry_date}>{input('expiry_date', 'date')}</Field>
            </div>
            <div className="flex justify-end gap-2 mt-5">
                <SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton>
                <PrimaryButton disabled={form.processing}>
                    <span className="material-symbols-outlined text-lg mr-1">save</span>
                    {mode === 'create' ? 'Save appetite statement' : 'Update'}
                </PrimaryButton>
            </div>
        </form>
    );
}

/**
 * Risk appetite framework (migration Phase 3.6: risk/appetite/index.blade.php).
 * Every figure on the page is computed by AppetiteFrameworkService and
 * arrives as a prop; the chart is a widget envelope drawn by chartConfigs.
 */
export default function Index({ metrics, summary, chart, categoriesWithoutStatement, levels, canManage, exportUrl }) {
    const { flash } = usePage().props;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState(null);
    const [history, setHistory] = useState(false);

    const overallDot = summary.overall === 'Within Appetite' ? 'bg-green-400' : summary.overall === 'Near Limit' ? 'bg-yellow-400' : 'bg-red-400';

    return (
        <AuthenticatedLayout title="Risk Appetite">
            <Head title="Risk Appetite Framework" />

            <PageHeader
                title="Risk Appetite Framework"
                subtitle="Board-approved appetite statements, tolerance bands, and current position monitoring"
                actions={
                    <>
                        <a href={exportUrl} className="btn-secondary text-sm inline-flex items-center gap-2"><span className="material-symbols-outlined text-lg">download</span> Export</a>
                        <button type="button" onClick={() => setHistory((h) => !h)} className="btn-secondary text-sm inline-flex items-center gap-2"><span className="material-symbols-outlined text-lg">history</span> Version history</button>
                        {canManage && (
                            <button type="button" onClick={() => setAdding(true)} disabled={categoriesWithoutStatement.length === 0}
                                title={categoriesWithoutStatement.length === 0 ? 'Every category already has a statement' : undefined}
                                className="btn-primary text-sm inline-flex items-center gap-2 disabled:opacity-50">
                                <span className="material-symbols-outlined text-lg">add_circle</span> Add appetite statement
                            </button>
                        )}
                    </>
                }
            />

            {flash?.error && (
                <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3 text-sm text-red-700">
                    <span className="material-symbols-outlined text-red-600">error</span>{flash.error}
                </div>
            )}

            {history && (
                <div className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                    <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-[var(--color-primary)] flex items-center gap-2"><span className="material-symbols-outlined text-lg">history</span> Version history</h3>
                        <button type="button" onClick={() => setHistory(false)} className="text-gray-400 hover:text-gray-600"><span className="material-symbols-outlined text-lg">close</span></button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead><tr><th>Created</th><th>Last updated</th><th>Risk category</th><th>Level</th><th>Metric</th><th>Tolerance band</th><th>Effective</th><th>Expires</th><th>Approved</th></tr></thead>
                            <tbody>
                                {metrics.length === 0 && <tr><td colSpan={9} className="text-center py-8 text-gray-400">No appetite statements recorded yet</td></tr>}
                                {[...metrics].sort((a, b) => (b.history.created_at || '').localeCompare(a.history.created_at || '')).map((m) => (
                                    <tr key={m.id}>
                                        <td className="text-xs text-gray-500">{m.history.created_at ?? '-'}</td>
                                        <td className="text-xs text-gray-500">{m.history.updated_at ?? '-'}</td>
                                        <td className="text-xs font-medium text-[var(--color-primary)]">{m.risk_category}</td>
                                        <td><span className="badge bg-blue-100 text-blue-700">{title(m.appetite_level) || '-'}</span></td>
                                        <td className="text-xs">{m.metric_name}</td>
                                        <td className="text-xs">{fmt(m.lower_limit)} - {fmt(m.upper_limit)} {m.unit_of_measure === 'percentage' ? '%' : m.unit_of_measure}</td>
                                        <td className="text-xs text-gray-500">{m.history.effective_date ?? '-'}</td>
                                        <td className="text-xs text-gray-500">{m.history.expiry_date ?? '-'}</td>
                                        <td className="text-xs text-gray-500">{m.history.approved_date ?? '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="bg-gradient-to-r from-[var(--color-primary)] to-[var(--color-primary-light,#2D4A7A)] rounded-xl p-6 text-white mb-6">
                <div className="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h2 className="text-lg font-bold">Overall risk appetite status</h2>
                        <p className="text-sm text-blue-200 mt-1">
                            Board approved: {summary.approval_date ?? 'not recorded'} · Next review: {summary.next_review_date ?? 'not scheduled'}
                        </p>
                    </div>
                    <div className="text-right">
                        <div className="text-3xl font-bold">{summary.overall}</div>
                        <div className="flex items-center gap-2 mt-1 justify-end">
                            <span className={`w-3 h-3 rounded-full ${overallDot}`} />
                            <span className="text-sm">{summary.breaches} {summary.breaches === 1 ? 'breach' : 'breaches'} active</span>
                        </div>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Appetite metrics" value={summary.total} icon="speed" color="primary" />
                <KpiCard title="Within tolerance" value={summary.within} icon="check_circle" color="success" />
                <KpiCard title="Near limit" value={summary.near_limit} icon="warning" color="warning" />
                <KpiCard title="Breach" value={summary.breaches} icon="error" color="danger" />
            </div>

            <div className="mb-6 h-72">
                <Widget payload={chart} />
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[var(--color-primary)]">Risk appetite metrics</h3>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr><th>Risk category</th><th>Appetite statement</th><th>Metric</th><th>Tolerance band</th><th>Current position</th><th>Status</th><th>Trend</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            {metrics.length === 0 && (
                                <tr><td colSpan={8} className="text-center py-12"><span className="material-symbols-outlined text-4xl text-gray-300 mb-2 block">speed</span><p className="text-sm text-gray-500">No appetite metrics configured</p></td></tr>
                            )}
                            {metrics.map((m) => {
                                const s = STATUS[m.status] || STATUS.within;
                                const [trendIcon, trendTone] = TREND[m.trend] || TREND.flat;
                                return (
                                    <tr key={m.id} className={s.row}>
                                        <td className="font-medium text-[var(--color-primary)] text-xs">{m.risk_category}</td>
                                        <td className="text-xs text-gray-600 max-w-[200px]" title={m.appetite_statement}>{m.appetite_statement.length > 60 ? `${m.appetite_statement.slice(0, 60)}…` : m.appetite_statement}</td>
                                        <td className="text-xs font-medium">{m.metric_name}</td>
                                        <td className="text-xs"><span className="text-green-600">{fmt(m.lower_limit)}</span> <span className="text-gray-400">-</span> <span className="text-red-600">{fmt(m.upper_limit)}</span></td>
                                        <td className={`text-xs font-bold ${s.text}`} title={m.position_source === 'residual_average' ? 'Average residual score of active risks (no position recorded)' : undefined}>
                                            {m.current_value === null ? <span className="text-gray-400 font-normal italic">not recorded</span> : fmt(m.current_value)}
                                        </td>
                                        <td><span className="flex items-center gap-1"><span className={`w-2.5 h-2.5 rounded-full ${s.dot}`} /><span className="text-xs font-medium">{title(m.status)}</span></span></td>
                                        <td><span className={`material-symbols-outlined text-sm ${trendTone}`}>{trendIcon}</span></td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                {canManage && (
                                                    <button type="button" onClick={() => setEditing(m)} className="p-1 hover:bg-gray-100 rounded" title="Edit appetite statement">
                                                        <span className="material-symbols-outlined text-gray-400 text-lg">edit</span>
                                                    </button>
                                                )}
                                                {m.status === 'breach' && <span className="badge bg-red-100 text-red-700">Action required</span>}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal show={adding} onClose={() => setAdding(false)} maxWidth="3xl">
                {adding && <StatementForm mode="create" initial={EMPTY_FORM} categories={categoriesWithoutStatement} levels={levels} onClose={() => setAdding(false)} />}
            </Modal>
            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="3xl">
                {editing && (
                    <StatementForm mode="edit" initial={{ ...editing.form, risk_category: editing.risk_category }} categories={[]} levels={levels}
                        updateUrl={route('risk.appetite.update', editing.id)} onClose={() => setEditing(null)} />
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
