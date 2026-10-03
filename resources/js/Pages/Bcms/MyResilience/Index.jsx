import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const CONSENT_LABEL = {
    not_requested: 'Not yet requested',
    pending: 'Requested, awaiting your response',
    granted: 'Granted',
    withdrawn: 'Withdrawn',
};

/**
 * My Resilience — the employee's own page, `docs/bcms/screens/
 * my-resilience.md`.
 *
 * MOBILE-FIRST, READ-MOSTLY. Single column at every breakpoint, plain
 * language, no BCMS vocabulary in the headline text. The one write action is
 * acknowledging a plan, reusing `bcms.plans.acknowledge` unchanged.
 *
 * NO EDIT CONTROL FOR CONTACT DETAILS, CONSENT OR NEXT-OF-KIN (ADR 0018 §1 —
 * self-service capture is Phase 2D's). The state is shown honestly; there is
 * nothing to click to change it here.
 */
export default function Index({ training = [], plans = [], call_tree_role: callTreeRole, contact, next_exercise: nextExercise }) {
    return (
        <AppLayout title="My resilience">
            <Head title="My resilience" />

            <div className="mx-auto max-w-xl">
                <PageHeader title="My resilience" />

                <Card title="My training">
                    {training.length === 0 ? (
                        <p className="text-sm text-gray-500">You have no training currently assigned.</p>
                    ) : (
                        <ul className="space-y-3">
                            {training.map((t, i) => {
                                const overdue = t.overdue;

                                return (
                                    <li key={i} className="border-b border-gray-100 pb-2 last:border-0">
                                        <p className="text-sm font-medium text-gray-900">{t.curriculum_name}</p>
                                        <p className="text-xs text-gray-600">
                                            {t.completed_at ? `Attended ${t.completed_at}` : 'Not yet attended'}
                                        </p>
                                        <p className={`text-xs ${t.requires_assessment && t.failed ? 'text-red-700' : 'text-gray-600'}`}>
                                            {/*
                                                `competency_assessed` alone means "passed" — a FAILED
                                                assessment (a real record, just below the pass mark)
                                                read as `false`, identical to "not yet assessed", and
                                                a person could not tell the two apart (code review).
                                                `assessed`/`failed` are additive booleans
                                                (`MyResilienceService`) read alongside it, never a
                                                silent rename.
                                            */}
                                            {!t.requires_assessment
                                                ? 'Awareness only — no assessment needed'
                                                : t.competency_assessed
                                                    ? `Confirmed you can do this, ${t.completed_at}`
                                                    : t.failed
                                                        ? 'Assessment not passed — speak to your training lead'
                                                        : t.completed_at
                                                            ? 'Attended — assessment outstanding'
                                                            : 'Not yet assessed'}
                                        </p>
                                        {t.next_due_date && (
                                            <p className={`text-xs ${overdue ? 'text-red-700' : 'text-gray-500'}`}>
                                                {overdue ? `Overdue since ${t.next_due_date}` : `Due ${t.next_due_date}`}
                                            </p>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Card>

                <Card title="My plan">
                    {plans.length === 0 ? (
                        <p className="text-sm text-gray-500">No continuity plan currently applies to you.</p>
                    ) : (
                        <ul className="space-y-4">
                            {plans.map((p) => (
                                <PlanRow key={p.id} plan={p} />
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="My role in an emergency">
                    {callTreeRole === null ? (
                        <p className="text-sm text-gray-500">You do not currently have a role in any call tree.</p>
                    ) : (
                        <div className="text-sm text-gray-700">
                            <p>{callTreeRole.tree_name} — {callTreeRole.role_label} (Tier {callTreeRole.tier})</p>
                            {callTreeRole.is_must_reach && (
                                <p className="mt-1 text-xs text-amber-700">You must be reached for this cascade to count as complete.</p>
                            )}
                            <p className="mt-1 text-xs text-gray-500">
                                {callTreeRole.has_deputy ? 'A deputy is recorded for you.' : 'No deputy is currently recorded for you.'}
                            </p>
                        </div>
                    )}
                </Card>

                <Card title="My emergency contact details">
                    {contact === null ? (
                        <p className="text-sm text-gray-500">
                            No emergency contact record exists for you yet. Ask your BC coordinator to add one.
                        </p>
                    ) : (
                        <dl className="space-y-2 text-sm text-gray-700">
                            <div><dt className="text-xs text-gray-500">Mobile</dt><dd>{contact.mobile_primary ?? '—'}</dd></div>
                            <div><dt className="text-xs text-gray-500">WhatsApp</dt><dd>{contact.whatsapp ?? '—'}</dd></div>
                            <div><dt className="text-xs text-gray-500">Preferred language</dt><dd>{contact.preferred_language ?? '—'}</dd></div>
                            <div>
                                <dt className="text-xs text-gray-500">Personal-phone alerts (SMS/voice/WhatsApp)</dt>
                                <dd>{CONSENT_LABEL[contact.consent_status] ?? contact.consent_status}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Number on file</dt>
                                <dd className={['bounced', 'invalid'].includes(contact.verification_status) ? 'text-red-700' : ''}>
                                    {contact.verification_status === 'verified' && `Verified ${contact.last_verified_at ?? ''}`}
                                    {contact.verification_status === 'unverified' && 'Not yet verified'}
                                    {contact.verification_status === 'bounced' && 'Bounced — please tell your BC coordinator'}
                                    {contact.verification_status === 'invalid' && 'Could not be verified'}
                                </dd>
                            </div>
                        </dl>
                    )}
                    <p className="mt-3 text-xs text-gray-500">
                        Updating your own contact details and consent here is not available in this build. Contact
                        your BC coordinator to update your details in the meantime.
                    </p>
                </Card>

                <Card title="My next exercise">
                    {nextExercise === null ? (
                        <p className="text-sm text-gray-500">Nothing scheduled that involves you in the next 90 days.</p>
                    ) : (
                        <div className="text-sm text-gray-700">
                            <p>Scheduled {nextExercise.scheduled_date}{nextExercise.role ? ` — your role: ${nextExercise.role}` : ''}</p>
                            <Link href={tryRoute('bcms.readiness.mine')} className="mt-1 inline-block text-xs underline">
                                See your readiness tasks
                            </Link>
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

function Card({ title, children }) {
    return (
        <section className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-gray-900">{title}</h2>
            <div className="mt-2">{children}</div>
        </section>
    );
}

function PlanRow({ plan }) {
    const form = useForm({});

    const acknowledge = () => {
        form.post(plan.acknowledge_url, { preserveScroll: true });
    };

    return (
        <li className="border-b border-gray-100 pb-3 last:border-0">
            <p className="text-sm font-medium text-gray-900">{plan.title}</p>
            <p className="text-xs text-gray-500">v{plan.version} · effective {plan.effective_from}</p>
            {plan.acknowledged_at ? (
                <p className="mt-1 text-xs text-emerald-700">You confirmed you'd read this on {plan.acknowledged_at}</p>
            ) : (
                <button type="button" className="btn-primary mt-2 text-xs" onClick={acknowledge} disabled={form.processing}
                    aria-label={`Confirm you have read ${plan.title}`}>
                    I've read this
                </button>
            )}
            <Link href={plan.show_url} className="mt-1 block text-xs underline">Open the full plan</Link>
        </li>
    );
}
