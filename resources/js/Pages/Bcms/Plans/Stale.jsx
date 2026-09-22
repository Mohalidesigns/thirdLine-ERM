import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Plans past their review date — the "plans current" KRI's own list.
 *
 * A KRI THAT CANNOT BE DRILLED INTO IS A NUMBER NOBODY ACTS ON. This is the
 * list behind the percentage, ordered by how long each has been overdue, with
 * the owner beside it so the next step is obvious.
 */
export default function Stale({ plans = [], currency = {}, can = {} }) {
    return (
        <AppLayout title="Plans past review">
            <Head title="Plans past review" />

            <PageHeader
                title="Plans past their review date"
                subtitle={currency.percentage == null
                    ? 'There are no approved plans to measure.'
                    : `${currency.percentage}% of approved plans are within their review cycle.`}
                actions={<Link href={tryRoute('bcms.plans.index')} className="btn-secondary text-sm">All plans</Link>}
            />

            {plans.length === 0 ? (
                <div className="rounded-lg border border-green-200 bg-green-50 p-8 text-center text-sm text-green-800">
                    Every approved plan with a review date is within its cycle.
                    {currency.undated > 0 && (
                        <span className="mt-2 block text-amber-800">
                            {currency.undated} approved plans have no review date at all, so they cannot be overdue and
                            cannot be shown to be current either.
                        </span>
                    )}
                </div>
            ) : (
                <div className="card">
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Plan</th>
                                    <th>Type</th>
                                    <th>Owner</th>
                                    <th>Due</th>
                                    <th className="text-right">Days overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                {plans.map((plan) => (
                                    <tr key={plan.id}>
                                        <td>
                                            <Link href={tryRoute('bcms.plans.show', plan.uuid)} className="cell-title">
                                                {plan.title}
                                            </Link>
                                            <p className="cell-subtitle">
                                                v{plan.version} · {plan.business_unit ?? 'Organisation-wide'}
                                            </p>
                                        </td>
                                        <td className="cell-muted">{plan.plan_type_label}</td>
                                        <td className="cell-muted">{plan.owner ?? '—'}</td>
                                        <td className="text-xs">{plan.next_review_date}</td>
                                        <td className="text-right font-mono text-red-700">{plan.days_overdue}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
