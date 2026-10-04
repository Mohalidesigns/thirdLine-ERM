import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import SeverityMatrix from '@/Components/Bcms/SeverityMatrix';
import ReportabilityQuestion from '@/Components/Bcms/ReportabilityQuestion';
import { toLocalInput, localInputToIso } from '@/Components/Bcms/dateInput';

/**
 * `docs/bcms/screens/incident-declaration.md` — get a real event captured and
 * a plan proposed for activation in under two minutes.
 *
 * THE TWO REPORTABILITY QUESTIONS ARE SHOWN HERE, PER SPEC §3, BUT
 * `DeclareBcmsIncidentRequest` HAS NO FIELD FOR THEM — classifying an
 * obligation is `bcms.incidents.classify`, an incident-scoped route that
 * cannot exist until the incident does. This screen therefore composes two
 * EXISTING endpoints rather than inventing a third: it declares first, and
 * — only when the answer is `yes`/`no` (an `unknown` answer needs no call,
 * it is the absence of a row) and only when the newly-created incident's own
 * page reports `can.manage` — chains one `classify` POST per answered
 * question once the crisis room redirect lands. A declaring officer who
 * holds only `bcms.incident.declare` cannot classify from here either way
 * (the route requires `.manage`), so the chain is skipped rather than
 * silently failing with a 403 the officer never sees. Flagged in this
 * phase's handoff as the one gap a backend pass should close by accepting
 * the two answers directly on `declare()`.
 */
export default function Declare({
    severity_bands: severityBands = [], activation_levels: activationLevels = [],
    processes = [], business_units: businessUnits = [], sites = [], plans = [], store_url: storeUrl,
}) {
    const nowLocal = useMemo(() => toLocalInput(new Date()), []);

    const form = useForm({
        title: '',
        incident_type: '',
        detected_at: nowLocal,
        business_unit_id: '',
        site_id: '',
        impacted_processes: [],
        severity: 'sev3',
        severity_override_reason: '',
        activate_plan_id: '',
        activation_reason: '',
    });

    const [cbnAnswer, setCbnAnswer] = useState('unknown');
    const [personalDataAnswer, setPersonalDataAnswer] = useState('unknown');
    const [personalDataReason, setPersonalDataReason] = useState('');
    const [cbnReason, setCbnReason] = useState('');
    const [processSearch, setProcessSearch] = useState('');

    const suggested = 'sev3';

    const matchedPlan = plans.find((p) => String(p.id) === String(form.data.activate_plan_id))
        ?? plans.find((p) => String(p.business_unit_id ?? '') === String(form.data.business_unit_id) && form.data.business_unit_id);

    const [activatePlan, setActivatePlan] = useState(true);

    const filteredProcesses = processes.filter((p) => !processSearch
        || p.name.toLowerCase().includes(processSearch.toLowerCase())
        || (p.code ?? '').toLowerCase().includes(processSearch.toLowerCase()));

    const toggleProcess = (id) => {
        const set = new Set(form.data.impacted_processes);
        if (set.has(id)) set.delete(id); else set.add(id);
        form.setData('impacted_processes', Array.from(set));
    };

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({
            ...data,
            // `datetime-local` carries no timezone; the browser's own local
            // time must become an absolute instant before the server's
            // `before_or_equal:now` check compares it against UTC.
            detected_at: localInputToIso(data.detected_at),
            activate_plan_id: activatePlan ? (data.activate_plan_id || matchedPlan?.id || '') : '',
        }));

        form.post(storeUrl, {
            onSuccess: (page) => {
                const props = page?.props ?? {};
                const canManage = props.can?.manage === true;
                const classifyUrl = props.urls?.classify;

                if (!canManage || !classifyUrl) return;

                // Two Inertia visits fired together cancel one another
                // (`net::ERR_ABORTED` on the first) — chained strictly one
                // after the other via `onFinish`, not fired concurrently.
                const queue = [];
                if (cbnAnswer !== 'unknown') {
                    queue.push({ question: 'cbn', answer: cbnAnswer, reason: cbnAnswer === 'no' ? cbnReason : undefined });
                }
                if (personalDataAnswer !== 'unknown') {
                    queue.push({ question: 'personal_data', answer: personalDataAnswer, reason: personalDataAnswer === 'no' ? personalDataReason : undefined });
                }

                const runNext = () => {
                    const next = queue.shift();
                    if (!next) return;
                    router.post(classifyUrl, next, { preserveScroll: true, preserveState: true, onFinish: runNext });
                };
                runNext();
            },
        });
    };

    return (
        <AppLayout title="Declare an incident">
            <Head title="Declare an incident" />

            <PageHeader title="Declare an incident" />

            <p className="mb-4 rounded bg-slate-50 p-3 text-sm text-slate-700">
                You do not need to know everything yet. Detected and Declared times and an initial
                severity are enough to start; everything else can be added from the crisis room.
            </p>

            <form onSubmit={submit} className="space-y-6">
                {/* Section 1 — what and when */}
                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">What and when</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label htmlFor="title" className="block text-sm font-medium text-slate-700">Title</label>
                            <input id="title" required className="form-input mt-1 w-full" value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)} />
                            {form.errors.title && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.title}</p>}
                        </div>

                        <div>
                            <label htmlFor="incident_type" className="block text-sm font-medium text-slate-700">Incident type</label>
                            <select id="incident_type" className="form-select mt-1 w-full" value={form.data.incident_type}
                                onChange={(e) => form.setData('incident_type', e.target.value)}>
                                <option value="">Not yet known</option>
                                {['cyber', 'power', 'flood', 'fire', 'civil_unrest', 'supplier', 'pandemic', 'system', 'other'].map((t) => (
                                    <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="detected_at" className="block text-sm font-medium text-slate-700">Detected at</label>
                            <input id="detected_at" type="datetime-local" required className="form-input mt-1 w-full"
                                value={form.data.detected_at} max={nowLocal}
                                onChange={(e) => form.setData('detected_at', e.target.value)} />
                            {form.errors.detected_at && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.detected_at}</p>}
                        </div>

                        <div>
                            <span className="block text-sm font-medium text-slate-700">Declared at</span>
                            <p className="form-input mt-1 w-full bg-slate-50 text-slate-500">{nowLocal.replace('T', ' ')} (now)</p>
                        </div>

                        <div>
                            <label htmlFor="business_unit_id" className="block text-sm font-medium text-slate-700">Business unit</label>
                            <select id="business_unit_id" className="form-select mt-1 w-full" value={form.data.business_unit_id}
                                onChange={(e) => form.setData('business_unit_id', e.target.value)}>
                                <option value="">Not yet known</option>
                                {businessUnits.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </div>

                        <div>
                            <label htmlFor="site_id" className="block text-sm font-medium text-slate-700">Site</label>
                            <select id="site_id" className="form-select mt-1 w-full" value={form.data.site_id}
                                onChange={(e) => form.setData('site_id', e.target.value)}>
                                <option value="">Not yet known</option>
                                {sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </div>

                        <div className="sm:col-span-2">
                            <label htmlFor="process_search" className="block text-sm font-medium text-slate-700">Impacted processes</label>
                            <input id="process_search" type="text" placeholder="Search processes"
                                className="form-input mt-1 w-full" value={processSearch}
                                onChange={(e) => setProcessSearch(e.target.value)} />
                            <div className="mt-2 max-h-40 overflow-y-auto rounded border border-slate-200 p-2">
                                {filteredProcesses.length === 0 && <p className="text-xs text-slate-500">No processes match.</p>}
                                {filteredProcesses.map((p) => (
                                    <label key={p.id} className="flex items-center gap-2 py-1 text-sm">
                                        <input type="checkbox" className="form-checkbox"
                                            checked={form.data.impacted_processes.includes(p.id)}
                                            onChange={() => toggleProcess(p.id)} />
                                        {p.name} <span className="text-xs text-slate-400">({p.code})</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>

                {/* Section 2 — severity matrix */}
                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 id="severity-matrix-legend" className="mb-3 text-sm font-semibold text-slate-700">Severity</h2>
                    <SeverityMatrix
                        bands={severityBands}
                        suggested={suggested}
                        value={form.data.severity}
                        onChange={(v) => form.setData('severity', v)}
                        reason={form.data.severity_override_reason}
                        onReasonChange={(v) => form.setData('severity_override_reason', v)}
                        reasonError={form.errors.severity_override_reason}
                        legendId="severity-matrix-legend"
                    />
                </section>

                {/* Section 3 — reportability */}
                <section className="rounded border border-slate-200 bg-white p-4 space-y-4">
                    <h2 className="text-sm font-semibold text-slate-700">Reportability</h2>
                    <ReportabilityQuestion
                        name="cbn_reportable"
                        legend="Is this a cyber/operational incident that may be reportable to the CBN?"
                        hint="Reported within 24 hours of detection where yes."
                        value={cbnAnswer}
                        onChange={setCbnAnswer}
                    />
                    {cbnAnswer === 'no' && (
                        <input type="text" required placeholder="Why is this not CBN-reportable?"
                            aria-label="Reason: not CBN-reportable" className="form-input w-full text-sm"
                            value={cbnReason} onChange={(e) => setCbnReason(e.target.value)} />
                    )}
                    <ReportabilityQuestion
                        name="personal_data_involved"
                        legend="Does this involve personal data?"
                        hint="Answering yes starts the NDPC's 72-hour clock from now — you can change this later without losing time, because the clock runs from when you first became aware, not from when you confirm it."
                        value={personalDataAnswer}
                        onChange={setPersonalDataAnswer}
                    />
                    {personalDataAnswer === 'no' && (
                        <input type="text" required placeholder="Why does this not involve personal data?"
                            aria-label="Reason: no personal data involved" className="form-input w-full text-sm"
                            value={personalDataReason} onChange={(e) => setPersonalDataReason(e.target.value)} />
                    )}
                </section>

                {/* Section 4 — activation */}
                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-2 text-sm font-semibold text-slate-700">Plan activation</h2>
                    {matchedPlan ? (
                        <>
                            <p className="mb-2 whitespace-pre-wrap rounded bg-slate-50 p-3 text-sm text-slate-700">
                                {matchedPlan.activation_criteria || 'No activation criteria text recorded on this plan.'}
                            </p>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" className="form-checkbox" checked={activatePlan}
                                    onChange={(e) => setActivatePlan(e.target.checked)} />
                                Activate this plan on declaration
                            </label>
                            {activatePlan && (
                                <input type="text" placeholder="Why is this being activated? (optional)"
                                    aria-label="Activation reason" className="form-input mt-2 w-full text-sm"
                                    value={form.data.activation_reason}
                                    onChange={(e) => form.setData('activation_reason', e.target.value)} />
                            )}
                        </>
                    ) : (
                        <p className="text-sm text-slate-500">
                            No continuity plan is linked to this process yet. Declare the incident anyway — a
                            plan can be activated from the crisis room once one exists.
                        </p>
                    )}
                </section>

                <button type="submit" disabled={form.processing}
                    className="w-full rounded bg-slate-800 py-4 text-base font-semibold text-white hover:bg-slate-700 disabled:opacity-60">
                    {form.processing ? 'Declaring…' : form.wasSuccessful === false && form.hasErrors ? 'Retry — nothing was lost' : 'Declare incident'}
                </button>
            </form>
        </AppLayout>
    );
}
