import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import { anyFilled, emptyRedBand, redBandPayload, useRedBandHost, validateRedBand } from '@/Components/Kri/RedBandSection';
import { bandFor, ruleFromBoundaries } from '@/lib/kri-band';
import { lagosToday } from '@/lib/kri-format';
import KriForm from './KriForm';

/** Compare two decimals as the column stores them, not as the browser typed them. */
function sameDecimal(a, b) {
    const x = a === '' || a === null || a === undefined ? null : Number(a);
    const y = b === '' || b === null || b === undefined ? null : Number(b);

    if (x === null || y === null) return x === y;

    return x.toFixed(6) === y.toFixed(6);
}

/**
 * Migration Phase 4.1: risk/kri/edit.blade.php, plus ADR 0026: a Department
 * select and the red-band explanation for a Target Value that falls in red.
 *
 * The risk is not editable, exactly as it was: update() never accepted
 * `risk_id`, so offering it would be a field that never saves.
 *
 * THE RE-ASK RULE (A1.11). With newBand from the posted boundaries and target,
 * and oldBand from the stored ones, the explanation is REQUIRED only when
 * newBand is red AND (a) oldBand was not red, or (b) the target changed while
 * red, or (c) the KRI has no open KRI Issue. Otherwise it is optional. The
 * server is the authority; this mirrors it so a typo fix on an already-explained
 * red KRI does not ask again.
 */
export default function Edit({
    kri,
    risks = [],
    users = [],
    departments = [],
    frequencies = [],
    directions = [],
    schema = null,
    bandRule = null,
    openKriIssue = null,
    openRedBreach = null,
    redBandDefaults = {},
}) {
    const { data, setData, put, processing, errors, transform } = useForm({
        ...initialValues(schema),
        kri_name: kri.kri_name ?? '',
        description: kri.description ?? '',
        measurement_unit: kri.measurement_unit ?? '',
        measurement_frequency: kri.measurement_frequency ?? 'monthly',
        data_source: kri.data_source ?? '',
        kri_owner_id: kri.kri_owner_id ?? '',
        business_unit_id: kri.business_unit_id ?? '',
        formula: kri.formula ?? '',
        direction: kri.direction ?? 'higher_is_worse',
        green_threshold: kri.green_threshold ?? '',
        red_threshold: kri.red_threshold ?? '',
        target_value: kri.target_value ?? '',
        is_active: Boolean(kri.is_active),
    });

    const today = lagosToday();
    const formRef = useRef(null);
    const [redValues, setRedValues] = useState(() => emptyRedBand({ owner_id: openKriIssue ? '' : (redBandDefaults.owner_id ?? '') }));
    const [clientErrors, setClientErrors] = useState({});

    const liveRule = useMemo(
        () => ruleFromBoundaries(data.direction, data.green_threshold, data.red_threshold),
        [data.direction, data.green_threshold, data.red_threshold],
    );
    const newBand = bandFor(liveRule, data.target_value);
    const oldBand = bandFor(bandRule, kri.target_value);
    const isRed = newBand === 'red';
    const required = isRed && (oldBand !== 'red' || !sameDecimal(data.target_value, kri.target_value) || openKriIssue === null);

    const serverRedErrors = {
        root_cause: errors['red_band.root_cause'],
        action_point: errors['red_band.action_point'],
        timeline: errors['red_band.timeline'],
        owner_id: errors['red_band.owner_id'],
        timeline_change_reason: errors['red_band.timeline_change_reason'],
    };
    const serverForced = !isRed && Object.values(serverRedErrors).some(Boolean);
    const redErrors = { ...clientErrors, ...Object.fromEntries(Object.entries(serverRedErrors).filter(([, v]) => v)) };

    const host = useRedBandHost({
        isRed,
        serverForced,
        messages: {
            enter: required
                ? 'The target value is in the red band. Root cause, action point and timeline are now required.'
                : 'The target value is in the red band. An explanation is not required for this save.',
            leave: 'The target value is no longer in the red band. The explanation fields are hidden. What you typed is kept.',
            forced: 'The server reads this value as red. Explanation required.',
        },
    });

    const filled = anyFilled(redValues);
    const sendRedBand = (isRed || serverForced) && (required || filled);

    transform((form) => ({
        ...form,
        ...formDataFor(schema, form),
        business_unit_id: form.business_unit_id === '' ? null : form.business_unit_id,
        ...(sendRedBand ? { red_band: redBandPayload(redValues, openKriIssue) } : {}),
    }));

    const focusFirstInvalid = () => {
        requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.focus());
    };

    useEffect(() => {
        if (Object.keys(errors).length > 0) focusFirstInvalid();
    }, [errors]);

    const onRedChange = (field, value) => {
        setRedValues((v) => ({ ...v, [field]: value }));
        setClientErrors((e) => {
            if (!e[field]) return e;
            const { [field]: _gone, ...rest } = e;

            return rest;
        });
    };

    const submit = (e) => {
        e.preventDefault();

        if (sendRedBand) {
            const problems = validateRedBand(redValues, { openIssue: openKriIssue, today });

            if (Object.keys(problems).length > 0) {
                setClientErrors(problems);
                focusFirstInvalid();

                return;
            }
        }

        setClientErrors({});
        put(route('risk.kri.update', kri.id), { preserveScroll: true });
    };

    const incomplete = required || filled ? Object.keys(validateRedBand(redValues, { openIssue: openKriIssue, today })).length > 0 : false;

    const redBand = {
        show: isRed || serverForced,
        required: required || serverForced,
        values: redValues,
        onChange: onRedChange,
        errors: redErrors,
        openIssue: openKriIssue,
        openBreach: openRedBreach,
        ownerOptions: users.map((u) => ({ id: u.id, name: u.name })),
        lagosToday: today,
        sectionRef: host.sectionRef,
        liveRegion: host.liveRegion,
        flush: host.flush,
        onValueKeyDown: host.onValueKeyDown,
        incomplete: (required || filled) && incomplete,
    };

    return (
        <AuthenticatedLayout title="Edit KRI">
            <Head title="Edit KRI" />

            <PageHeader
                title="Edit Key Risk Indicator"
                subtitle={kri.kri_name}
                breadcrumbs={[
                    { label: 'KRI Monitoring', href: route('risk.kri.index') },
                    { label: kri.code ?? kri.kri_name, href: route('risk.kri.show', kri.id) },
                    { label: 'Edit' },
                ]}
            />

            <form ref={formRef} onSubmit={submit} className="max-w-5xl" noValidate>
                <KriForm
                    {...{ data, setData, errors, schema, risks, users, departments, frequencies, directions, redBand }}
                    showActive
                    isEdit
                />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.kri.show', kri.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} aria-busy={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span aria-hidden="true" className="material-symbols-outlined text-lg">save</span> {processing ? 'Saving…' : 'Update KRI'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
