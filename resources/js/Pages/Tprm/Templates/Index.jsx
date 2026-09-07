import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The questionnaire library.
 *
 * Two columns carry the phase's rules. `unmapped_count` is what stands between
 * a template and publish (FR-ASM-05), shown here so an author sees it before
 * opening the builder. And a shipped pack reports how much of its Appendix B
 * specification it contains — "16 of ~55" rather than "16".
 */
export default function Index({ templates = [], can = {} }) {
    return (
        <AppLayout title="Questionnaires">
            <Head title="Questionnaires" />

            <PageHeader
                title="Questionnaires"
                subtitle="Shipped packs and your own. Every question maps to a control it tests — that is what keeps them short enough to be answered carefully."
            />

            <div className="card overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3 font-medium">Questionnaire</th>
                            <th className="px-4 py-3 font-medium">Owner</th>
                            <th className="px-4 py-3 font-medium">Status</th>
                            <th className="px-4 py-3 text-right font-medium">Questions</th>
                            <th className="px-4 py-3 text-right font-medium">Unmapped</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {templates.map((template) => (
                            <tr key={template.id} className="hover:bg-gray-50">
                                <td className="px-4 py-3">
                                    <a href={template.url} className="font-medium text-blue-700 hover:underline">
                                        {template.name}
                                    </a>
                                    <p className="font-mono text-xs text-gray-500">
                                        {template.code} v{template.version}
                                    </p>
                                    {template.catalogue_status === 'partial' && (
                                        <p className="mt-1 text-[11px] text-amber-700">
                                            Ships {template.question_count} of ~{template.declared_question_count}{' '}
                                            questions specified for this pack
                                        </p>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-xs">
                                    {template.is_system_pack
                                        ? <span className="rounded bg-gray-100 px-1.5 py-0.5 font-medium text-gray-600">Shipped</span>
                                        : <span className="text-gray-600">Your organisation</span>}
                                </td>
                                <td className="px-4 py-3 text-xs capitalize">{template.status}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{template.question_count}</td>
                                <td className="px-4 py-3 text-right tabular-nums">
                                    {template.unmapped_count > 0
                                        ? <span className="font-medium text-red-700">{template.unmapped_count}</span>
                                        : <span className="text-gray-400">0</span>}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {can.manage && (
                                        <button
                                            type="button"
                                            onClick={() => router.post(tryRoute('tprm.templates.clone', template.id))}
                                            className="text-xs font-medium text-blue-700 hover:underline"
                                        >
                                            {template.is_system_pack ? 'Customise a copy' : 'Duplicate'}
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
