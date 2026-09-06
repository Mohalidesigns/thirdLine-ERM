import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import LossEventForm, { STEPS, errorsInStep } from './LossEventForm';
import StepNav from './StepNav';

/**
 * Migration Phase 4.3: risk/loss-events/edit.blade.php.
 *
 * Who reported the event and whether it was a near miss are not editable, and
 * were not before — update() has never accepted either. They are facts about
 * what happened, not fields to revise.
 */
export default function Edit({ event, risks = [], businessUnits = [], users = [], options = {} }) {
    const [step, setStep] = useState(1);

    const { data, setData, put, processing, errors } = useForm({
        event_title: event.event_title ?? '',
        event_description: event.event_description ?? '',
        date_of_loss: event.date_of_loss ?? '',
        date_discovered: event.date_discovered ?? '',
        business_unit_id: event.business_unit_id ?? '',
        risk_id: event.risk_id ?? '',
        basel_event_type: event.basel_event_type ?? '',
        cbn_loss_category: event.cbn_loss_category ?? '',
        event_type: event.event_type ?? 'actual_loss',
        severity: event.severity ?? '',
        gross_loss_amount: event.gross_loss_amount ?? '',
        recovery_amount: event.recovery_amount ?? '',
        insurance_recovery: event.insurance_recovery ?? '',
        currency: event.currency ?? 'NGN',
        root_cause_summary: event.root_cause_summary ?? '',
        corrective_action_summary: event.corrective_action_summary ?? '',
        is_regulatory_reportable: Boolean(event.is_regulatory_reportable),
        regulatory_body: event.regulatory_body ?? '',
        reporting_deadline: event.reporting_deadline ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.loss-events.update', event.id), {
            onError: (bag) => {
                const failing = STEPS.find((s) => errorsInStep(bag, s.step) > 0);
                if (failing) setStep(failing.step);
            },
        });
    };

    return (
        <AuthenticatedLayout title="Amend Loss Event">
            <Head title="Amend Loss Event" />

            <PageHeader
                title="Amend Loss Event"
                subtitle={event.reference}
                breadcrumbs={[
                    { label: 'Loss Events', href: route('risk.loss-events.index') },
                    { label: event.reference, href: route('risk.loss-events.show', event.id) },
                    { label: 'Amend' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <StepNav steps={STEPS} step={step} onSelect={setStep} errorsInStep={(s) => errorsInStep(errors, s)} />

                <LossEventForm {...{ step, data, setData, errors, risks, businessUnits, users, options }} />

                <div className="flex items-center justify-between">
                    {step > 1 ? (
                        <button type="button" onClick={() => setStep(step - 1)} className="btn-secondary text-sm">Back</button>
                    ) : (
                        <Link href={route('risk.loss-events.show', event.id)} className="btn-secondary text-sm">Cancel</Link>
                    )}

                    <div className="flex gap-2">
                        {step < STEPS.length && (
                            <button type="button" onClick={() => setStep(step + 1)} className="btn-secondary text-sm">Continue</button>
                        )}
                        <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                            <span className="material-symbols-outlined text-lg">save</span> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
