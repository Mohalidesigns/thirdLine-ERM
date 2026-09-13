import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';

function StartForm({ definition, url, onClose }) {
    const form = useForm({ definition_id: definition.id, entity_type: definition.entity_type, entity_id: definition.start_options[0]?.id ?? '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(url, { preserveScroll: true, onSuccess: onClose }); }} className="flex items-end gap-3 p-3">
            <div className="flex-1">
                <label className="block text-xs font-semibold text-gray-700 mb-1">Select {definition.entity_type_label}</label>
                <select value={form.data.entity_id} onChange={(e) => form.setData('entity_id', e.target.value)} required className="form-select w-full text-sm rounded-lg border-gray-200">
                    {definition.start_options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                </select>
                <InputError message={form.errors.entity_id || form.errors.definition_id} className="mt-1" />
            </div>
            <button type="submit" disabled={form.processing} className="px-4 py-2 bg-[#2D7D46] text-white text-xs font-semibold rounded-lg hover:bg-[#236B38]">Start workflow</button>
            <button type="button" onClick={onClose} className="px-3 py-2 text-xs text-gray-500">Cancel</button>
        </form>
    );
}

/** Workflow definitions (migration Phase 3.7: risk/workflows/definitions.blade.php). The designer stays Blade. */
export default function Definitions({ definitions, canManage, urls }) {
    const { flash } = usePage().props;
    const [starting, setStarting] = useState(null);
    const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;

    return (
        <AuthenticatedLayout title="Workflows">
            <Head title="Workflow definitions" />
            <PageHeader
                title="Workflow definitions"
                subtitle="Publishing writes a new version. Anything already running stays on the version it started with, so a change here cannot alter a decision already in progress."
                actions={canManage && <a href={urls.newDefinition} className="btn-primary text-sm inline-flex items-center gap-2">New definition</a>}
            />

            {flash?.error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm whitespace-pre-line text-red-800">{flash.error}</div>}

            <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <table className="data-table">
                    <thead><tr><th>Name</th><th>Runs over</th><th>Steps</th><th>Version</th><th>State</th><th>Actions</th></tr></thead>
                    <tbody>
                        {definitions.data.length === 0 && <tr><td colSpan={6} className="text-center py-8 text-gray-400">No workflow definitions</td></tr>}
                        {definitions.data.map((d) => (
                            <>
                                <tr key={d.id}>
                                    <td className="font-medium">
                                        {d.name}
                                        {d.is_system && <span className="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">shipped</span>}
                                        <span className="block font-mono text-[11px] text-gray-400">{d.code}</span>
                                    </td>
                                    <td>{d.entity_type_label}</td>
                                    <td>{plural(d.steps, 'step')}</td>
                                    <td>v{d.version}</td>
                                    <td>{d.is_published ? <span className="badge bg-green-100 text-green-700">Published</span> : <span className="badge bg-amber-100 text-amber-700">Draft</span>}</td>
                                    <td className="whitespace-nowrap">
                                        {canManage && (
                                            <>
                                                {/* Designer: Blade until Phase 6, so a plain anchor. */}
                                                <a href={d.urls.edit} className="inline-flex items-center gap-1 px-3 py-1.5 border border-gray-300 text-gray-700 text-xs font-semibold rounded-lg hover:bg-gray-50">
                                                    <span className="material-symbols-outlined text-sm">edit</span> {d.is_published ? 'New version' : 'Edit'}
                                                </a>
                                                {d.urls.publish && (
                                                    <button type="button" onClick={() => router.post(d.urls.publish, {}, { preserveScroll: true })} className="ml-1 inline-flex items-center gap-1 px-3 py-1.5 bg-[#2D7D46] text-white text-xs font-semibold rounded-lg hover:bg-[#236B38]">Publish</button>
                                                )}
                                                {d.start_options.length > 0 && (
                                                    <button type="button" onClick={() => setStarting(starting === d.id ? null : d.id)} className="ml-1 inline-flex items-center gap-1 px-3 py-1.5 bg-[var(--color-primary)] text-white text-xs font-semibold rounded-lg hover:opacity-90">
                                                        <span className="material-symbols-outlined text-sm">play_arrow</span> Start
                                                    </button>
                                                )}
                                            </>
                                        )}
                                    </td>
                                </tr>
                                {starting === d.id && (
                                    <tr key={`start-${d.id}`}><td colSpan={6} className="bg-gray-50"><StartForm definition={d} url={urls.start} onClose={() => setStarting(null)} /></td></tr>
                                )}
                            </>
                        ))}
                    </tbody>
                </table>
                <Pagination links={definitions.links} meta={definitions.meta} />
            </div>
        </AuthenticatedLayout>
    );
}
