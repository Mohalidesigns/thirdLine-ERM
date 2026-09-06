import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';

const STATUS_DOT = {
    red: 'bg-red-500',
    amber: 'bg-yellow-500',
    yellow: 'bg-yellow-400',
    green: 'bg-green-500',
};

/**
 * Bulk threshold editor (migration Phase 4.1: risk/kri/thresholds.blade.php).
 *
 * THE SCREEN THIS REPLACES SAVED NOTHING. Its form posted
 * `kris[{id}][green_threshold]`; the controller read
 * `$request->input('thresholds')` and looked for `green_min` / `green_max` /
 * `amber_min` / … — a shape nothing produced. The loop ran zero times and the
 * page redirected saying "Thresholds updated." Every bulk edit of a bank's
 * risk tolerances was discarded silently. The payload and the server now agree,
 * and a test pins which columns move.
 *
 * TWO COLUMNS OF INPUTS, NOT THREE: amber is the band between green and red and
 * has no boundary of its own. The old screen's amber input redisplayed red's
 * number, because the model's amber accessor returns red's edge.
 */
export default function Thresholds({ kris = {}, canManage = false }) {
    const rows = kris.data ?? [];

    const { data, setData, put, processing, errors } = useForm({
        kris: rows.map((kri) => ({
            id: kri.id,
            green_threshold: kri.green_threshold ?? '',
            red_threshold: kri.red_threshold ?? '',
        })),
    });

    const [dirty, setDirty] = useState(false);

    const change = (index, key, value) => {
        setData('kris', data.kris.map((row, i) => (i === index ? { ...row, [key]: value } : row)));
        setDirty(true);
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.kri.thresholds.update'), { preserveScroll: true, onSuccess: () => setDirty(false) });
    };

    const cell = 'w-full px-2 py-1 border rounded text-sm text-center';

    return (
        <AuthenticatedLayout title="KRI Thresholds">
            <Head title="KRI Thresholds" />

            <form onSubmit={submit}>
                <PageHeader
                    title="KRI Threshold Management"
                    subtitle="Set the green and red boundaries every indicator is read against"
                    breadcrumbs={[{ label: 'KRI Monitoring', href: route('risk.kri.index') }, { label: 'Thresholds' }]}
                    actions={
                        canManage && (
                            <button type="submit" disabled={processing || !dirty} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                                <span className="material-symbols-outlined text-lg">save</span>
                                {dirty ? 'Save Changes' : 'No Changes'}
                            </button>
                        )
                    }
                />

                <p className="text-xs text-gray-500 mb-4 max-w-3xl">
                    Amber is the band between the two boundaries, so it has no number of its own. Which side of each
                    boundary is bad depends on the indicator's direction, shown per row. Saving writes a new
                    effective-dated band set, so a breach recorded last month keeps reading against last month's limits.
                </p>

                <InputError message={errors.kris} className="mb-3" />

                <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>KRI</th>
                                    <th>Linked Risk</th>
                                    <th>Direction</th>
                                    <th>Current</th>
                                    <th className="bg-green-50">
                                        <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-full bg-green-500" /> Green boundary</span>
                                    </th>
                                    <th className="bg-red-50">
                                        <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-full bg-red-500" /> Red boundary</span>
                                    </th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-center py-12">
                                            <span className="material-symbols-outlined text-4xl text-gray-300 mb-2 block">tune</span>
                                            <p className="text-sm text-gray-500">
                                                No KRIs configured.{' '}
                                                <Link href={route('risk.kri.create')} className="text-[#1A365D] hover:underline">Create your first KRI</Link>
                                            </p>
                                        </td>
                                    </tr>
                                )}
                                {rows.map((kri, index) => (
                                    <tr key={kri.id} className="hover:bg-blue-50/50">
                                        <td>
                                            <Link href={route('risk.kri.show', kri.id)} className="font-medium text-[#1A365D] hover:underline">
                                                {kri.name}
                                            </Link>
                                            <div className="text-[10px] text-gray-400">{kri.code}</div>
                                        </td>
                                        <td className="text-xs">{kri.riskCode ?? '—'}</td>
                                        <td className="text-xs text-gray-600">
                                            {kri.direction === 'lower_is_worse' ? 'Lower is worse' : 'Higher is worse'}
                                        </td>
                                        <td className="text-sm font-semibold">
                                            {kri.currentValue === null ? '—' : `${kri.currentValue}${kri.unit ?? ''}`}
                                        </td>
                                        <td className="bg-green-50/50">
                                            <input
                                                type="number"
                                                step="0.01"
                                                disabled={!canManage}
                                                value={data.kris[index]?.green_threshold ?? ''}
                                                onChange={(e) => change(index, 'green_threshold', e.target.value)}
                                                className={`${cell} border-green-300 focus:ring-1 focus:ring-green-400 disabled:bg-gray-50`}
                                            />
                                            <InputError message={errors[`kris.${index}.green_threshold`]} className="mt-1" />
                                        </td>
                                        <td className="bg-red-50/50">
                                            <input
                                                type="number"
                                                step="0.01"
                                                disabled={!canManage}
                                                value={data.kris[index]?.red_threshold ?? ''}
                                                onChange={(e) => change(index, 'red_threshold', e.target.value)}
                                                className={`${cell} border-red-300 focus:ring-1 focus:ring-red-400 disabled:bg-gray-50`}
                                            />
                                            <InputError message={errors[`kris.${index}.red_threshold`]} className="mt-1" />
                                        </td>
                                        <td>
                                            <span className="flex items-center gap-1.5 text-xs">
                                                <span className={`w-2.5 h-2.5 rounded-full ${STATUS_DOT[kri.status] ?? 'bg-gray-300'}`} />
                                                {kri.status ?? '—'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination links={kris.links} meta={kris.meta} />
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
