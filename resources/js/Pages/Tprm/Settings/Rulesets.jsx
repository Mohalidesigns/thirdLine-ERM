import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Tiering rulesets — FR-TIER-09.
 *
 * A published ruleset is immutable and every score cites its version, so this
 * list is the history of what "Critical" has meant, not a settings page.
 */
export default function Rulesets({ rulesets = [], current }) {
    return (
        <AppLayout title="Tiering rulesets">
            <Head title="Tiering rulesets" />

            <PageHeader
                title="Tiering rulesets"
                subtitle="The factor weights and knockout rules that decide every vendor's tier."
                actions={
                    <button type="button"
                        onClick={() => router.post(tryRoute('tprm.rulesets.draft'))}
                        className="btn-primary text-sm inline-flex items-center gap-2">
                        <span className="material-symbols-outlined text-lg">add</span> New draft
                    </button>
                }
            />

            <div className="card mb-6 p-5">
                <p className="text-xs text-gray-600">
                    A published ruleset cannot be edited. Every score records the version that produced it, so
                    changing the rules means publishing a new version — which leaves last quarter's numbers
                    explainable by the rules that produced them. Run the simulator on a draft before publishing:
                    changing one weight moves tiers across the whole portfolio, and the simulator is the only place
                    that is visible before it happens.
                </p>
            </div>

            <div className="card overflow-hidden">
                <table className="data-table">
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th className="text-right">Factors</th>
                            <th className="text-right">Knockouts</th>
                            <th>Published</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rulesets.map((ruleset) => (
                            <tr key={ruleset.id} className="hover:bg-gray-50">
                                <td>
                                    <a href={ruleset.url} className="font-mono text-xs text-blue-700 hover:underline">
                                        {ruleset.version}
                                    </a>
                                    {ruleset.version === current && (
                                        <span className="ml-2 rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-800">
                                            In force
                                        </span>
                                    )}
                                </td>
                                <td>{ruleset.name}</td>
                                <td className="text-xs capitalize">{ruleset.status}</td>
                                <td className="text-right tabular-nums">{ruleset.factor_count}</td>
                                <td className="text-right tabular-nums">{ruleset.knockout_count}</td>
                                <td className="text-xs text-gray-500">
                                    {ruleset.published_at ?? '—'}
                                    {ruleset.published_by && <p>{ruleset.published_by}</p>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
