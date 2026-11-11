import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import FormField from '@thirdline/ui/Components/FormField';
import Icon from '@thirdline/ui/Components/Icon';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import OpEventTypePicker from '@/Components/Sm/OpEventTypePicker';
import { groupDigits } from '@/Components/Sm/format';

/**
 * New scenario (spec 04) — a minimal form, deliberately.
 *
 * FOUR FIELDS AND THEN OUT OF THE WAY. Everything else the module collects lives
 * on the Overview & Governance tab, and a create form that duplicated it would be
 * a second copy of fifteen fields with its own requiredness rules, option lists
 * and messages: the two would drift, and the drift would be invisible until a
 * user's answers disagreed depending on which door they came through (spec 04 §1).
 *
 * NO REFERENCE, PROVISIONAL OR OTHERWISE (spec 04 §4). The sequence is
 * `max + 1` read at write time, so any number shown BEFORE the write is a guess —
 * two designers creating at once are both shown a number one of them never gets,
 * and the screen would have displayed a reference that never existed. That is
 * standard §5's defect class in the one field a user is most likely to copy into
 * an email before saving. The row reads "Assigned when you save" and that is the
 * only text it ever carries before the save; the server's flash speaks the
 * reference for the first time after the redirect.
 *
 * `nature` ARRIVES PRE-SET TO `local`, NOT NULL (§A1.2: NOT NULL default 'local',
 * "the narrowest claim"), so the form opens on a real value rather than a
 * "Choose…" the user has to clear.
 */
const NARRATIVE_MAX = 250;

export default function Create({ businessUnits, opEventTypes, natures, values, urls }) {
    const units = businessUnits || [];
    const singleUnit = units.length === 1 ? units[0] : null;

    const { data, setData, post, processing, errors, isDirty } = useForm({
        name: values?.name ?? '',
        // A single-unit tenant is pre-selected: "a select with one option is a
        // control that teaches nothing" (spec 04 §5, spec 02 §5.3).
        business_unit_id: singleUnit ? singleUnit.id : (values?.business_unit_id ?? null),
        nature: values?.nature ?? 'local',
        risk_taxonomy_node_id: values?.risk_taxonomy_node_id ?? null,
    });

    const errorKeys = Object.keys(errors);
    const summaryRef = useRef(null);
    const [leaveOpen, setLeaveOpen] = useState(false);
    const pendingVisit = useRef(null);
    const allowLeave = useRef(false);
    // Spec 04 §6.3: on a link bad enough to lose the RESPONSE, the POST may have
    // committed. A blind retry is how a tenant acquires two copies of the same
    // scenario, so the page stops offering "Try again" and offers "Check whether
    // it was created" instead.
    const [lostResponse, setLostResponse] = useState(false);

    // The guard fires ONLY when `name` is non-empty. Leaving a pristine create
    // form is not a loss and must not produce a dialog — a confirmation on an
    // empty form trains users to click through confirmations, which is how the
    // ones that matter get missed (spec 04 §6.2).
    const guarded = isDirty && data.name.trim() !== '';

    useEffect(() => {
        if (!guarded) {
            return undefined;
        }

        const handler = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [guarded]);

    useEffect(() => {
        const cancel = router.on('before', (event) => {
            // Only NAVIGATIONS are guarded. `router.on('before')` also fires for
            // this form's own `POST`, and intercepting that would answer a user
            // who pressed "Create scenario" with "Leave without saving?".
            const method = String(event.detail?.visit?.method || 'get').toLowerCase();

            if (method !== 'get' || allowLeave.current || !guarded || processing) {
                return;
            }

            event.preventDefault();
            pendingVisit.current = event.detail.visit;
            setLeaveOpen(true);
        });

        return cancel;
    }, [guarded, processing]);

    useEffect(() => {
        if (errorKeys.length > 0) {
            summaryRef.current?.focus();
            summaryRef.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [errors]);

    // NO `sessionStorage` BACKUP ON THIS PAGE (spec 04 §6.2). Four fields, one of
    // them a name, is a form a user re-fills faster than they read a restore
    // prompt — and a backup that survives a tab close on a CREATE page risks
    // resurrecting a scenario that was in fact created.
    const submit = (event) => {
        event.preventDefault();
        setLostResponse(false);
        allowLeave.current = true;

        post(urls.store, {
            preserveScroll: true,
            onError: () => {
                allowLeave.current = false;
            },
            onException: () => {
                allowLeave.current = false;
                setLostResponse(true);
            },
        });
    };

    const leave = () => {
        setLeaveOpen(false);
        allowLeave.current = true;
        const visit = pendingVisit.current;
        pendingVisit.current = null;

        if (visit) {
            router.visit(visit.url.href, { method: visit.method, data: visit.data, replace: visit.replace });
        }
    };

    return (
        <AppLayout title="New scenario">
            <Head title="New scenario" />

            <PageHeader
                title="New scenario"
                subtitle="Name it, place it, and the workspace does the rest."
                breadcrumbs={[
                    { label: 'Scenario modelling' },
                    { label: 'Scenarios', href: urls.cancel },
                    { label: 'New scenario' },
                ]}
            />

            <div className="mx-auto max-w-2xl space-y-4">
                {lostResponse && (
                    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <p>
                            We couldn't reach the server. Your scenario may or may not have been created — check the
                            list before trying again, so you don't create it twice. Your entries are still on this
                            page.
                        </p>
                        <Link href={urls.cancel} className="btn-secondary mt-3 text-sm">
                            Check whether it was created
                        </Link>
                    </div>
                )}

                <div className="card p-5">
                    {errorKeys.length > 0 && (
                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
                            <h2
                                ref={summaryRef}
                                tabIndex={-1}
                                className="text-sm font-semibold text-red-800 focus:outline-none"
                            >
                                Fix {errorKeys.length} {errorKeys.length === 1 ? 'item' : 'items'}
                            </h2>
                            <ul className="mt-1 list-inside list-disc text-sm text-red-700">
                                {errorKeys.map((key) => (
                                    <li key={key}>
                                        <a href={`#field-${key}`} className="underline">
                                            {errors[key]}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-2 text-xs text-red-700">Nothing was created.</p>
                        </div>
                    )}

                    {/* The Reference row is FIRST, above `name`, and is read-only
                        text (spec 04 §3, §4). */}
                    <div className="mb-4 border-b border-gray-100 pb-4">
                        <p className="form-label mb-0">Reference</p>
                        <p className="text-sm text-gray-600">Assigned when you save</p>
                    </div>

                    <form onSubmit={submit} className="space-y-4" noValidate>
                        <div id="field-name" className="scroll-mt-24">
                            <FormField
                                label="Scenario name"
                                htmlFor="name"
                                required
                                error={errors.name}
                                hint="Describe the event, not the risk. 'Loss of key staff — succession planning failure' rather than 'People risk'."
                            >
                                <input
                                    id="name"
                                    type="text"
                                    className="form-input"
                                    maxLength={NARRATIVE_MAX}
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                    aria-invalid={errors.name ? 'true' : undefined}
                                />
                            </FormField>
                            <NameCounter length={data.name.length} max={NARRATIVE_MAX} />
                        </div>

                        <div id="field-business_unit_id" className="scroll-mt-24">
                            {singleUnit ? (
                                <ReadOnlyUnit unit={singleUnit} />
                            ) : (
                                <FormField
                                    label="Business unit"
                                    htmlFor="business_unit_id"
                                    error={errors.business_unit_id}
                                    hint={
                                        units.length === 0
                                            ? undefined
                                            : 'The unit that owns this scenario. Whether it is a group, standard or local scenario is set by Nature below.'
                                    }
                                >
                                    <select
                                        id="business_unit_id"
                                        className="form-input"
                                        value={data.business_unit_id ?? ''}
                                        onChange={(event) =>
                                            setData('business_unit_id', event.target.value === '' ? null : Number(event.target.value))
                                        }
                                        aria-invalid={errors.business_unit_id ? 'true' : undefined}
                                    >
                                        <option value="">Choose a business unit…</option>
                                        {units.map((unit) => (
                                            <option key={unit.id} value={unit.id}>
                                                {unit.name}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                            )}

                            {units.length === 0 && (
                                // CREATE IS NOT BLOCKED (spec 04 §5). §A1.2 row 10
                                // makes the column nullable and §A1.8's frozen
                                // guard does not list it, so blocking would
                                // enforce a rule the server does not have.
                                <div
                                    role="note"
                                    className="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                                >
                                    <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>
                                        No business unit is configured for your organisation, so this scenario will be
                                        unassigned. Add units under Scoping to give it an owner.
                                    </span>
                                </div>
                            )}
                        </div>

                        <div id="field-nature" className="scroll-mt-24">
                            <FormField
                                label="Nature of scenario"
                                htmlFor="nature"
                                error={errors.nature}
                                hint="Defaults to Local — the narrowest claim. Change it if this is a group or standard scenario."
                            >
                                <select
                                    id="nature"
                                    className="form-input"
                                    value={data.nature ?? ''}
                                    onChange={(event) => setData('nature', event.target.value)}
                                    aria-invalid={errors.nature ? 'true' : undefined}
                                >
                                    {(natures || []).map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                        </div>

                        {/* Required at NO lifecycle gate on this form, because
                            there is no lifecycle yet. `risk_taxonomy_node_id`
                            becomes required at the `in_design → designed`
                            transition (§A1.8), and its help text says so in the
                            same words on both surfaces. `business_unit_id` and
                            `nature` are NOT in that guard, so their help text
                            must not claim a requirement the server does not
                            enforce — a field that warns of a rule the server
                            lacks teaches users to ignore the warnings that are
                            real (spec 04 §3). */}
                        <div id="field-risk_taxonomy_node_id" className="scroll-mt-24">
                            <span className="form-label">OP event type</span>
                            <OpEventTypePicker
                                options={opEventTypes}
                                value={data.risk_taxonomy_node_id}
                                onChange={(id) => setData('risk_taxonomy_node_id', id)}
                                error={errors.risk_taxonomy_node_id}
                            />
                            {(opEventTypes || []).length > 0 && (
                                <p className="mt-1 text-xs text-gray-600">
                                    Optional now — required before the design can be completed.
                                </p>
                            )}
                        </div>

                        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:items-center">
                            <button
                                type="submit"
                                className="btn-primary text-sm"
                                disabled={processing}
                                aria-busy={processing || undefined}
                            >
                                {processing ? 'Creating…' : 'Create scenario'}
                            </button>
                            {/* Cancel sits AFTER Create, because the primary
                                action is the one a user came here for. It is a
                                plain link, intercepted by the same guard. */}
                            <Link href={urls.cancel} className="btn-secondary text-sm">
                                Cancel
                            </Link>
                        </div>
                    </form>
                </div>
            </div>

            <ConfirmDialog
                show={leaveOpen}
                title="Leave without saving?"
                message="You've started a scenario that isn't saved. Leave without saving?"
                confirmLabel="Leave"
                cancelLabel="Stay"
                variant="warning"
                onConfirm={leave}
                onCancel={() => {
                    setLeaveOpen(false);
                    pendingVisit.current = null;
                }}
            />
        </AppLayout>
    );
}

function ReadOnlyUnit({ unit }) {
    return (
        <div>
            <p className="form-label mb-0">Business unit</p>
            <p className="text-sm text-gray-900">{unit.name}</p>
            <p className="mt-1 text-xs text-gray-600">Your organisation has one business unit.</p>
        </div>
    );
}

/** A counter on a name field is noise until it is close to the cap. */
function NameCounter({ length, max }) {
    if (length < max - 50) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-gray-600">
            {groupDigits(length)} / {groupDigits(max)}
        </p>
    );
}
