import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import LossEventForm, { STEPS, errorsInStep } from './LossEventForm';
import StepNav from './StepNav';

/** Migration Phase 4.3: risk/loss-events/create.blade.php. */
export default function Create({ risks = [], businessUnits = [], users = [], options = {} }) {
    const [step, setStep] = useState(1);

    const { data, setData, post, processing, errors } = useForm({
        event_title: '',
        event_description: '',
        date_of_loss: '',
        date_discovered: new Date().toISOString().slice(0, 10),
        business_unit_id: '',
        reported_by: '',
        risk_id: '',
        basel_event_type: '',
        cbn_loss_category: '',
        event_type: 'actual_loss',
        severity: '',
        gross_loss_amount: '',
        recovery_amount: '',
        insurance_recovery: '',
        is_near_miss: false,
        currency: 'NGN',
        root_cause_summary: '',
        corrective_action_summary: '',
        is_regulatory_reportable: false,
        regulatory_body: '',
        reporting_deadline: '',
    });

    const submit = (e) => {
        e.preventDefault();
        // A validation error can belong to any step, so land the reporter on
        // the first one that has a problem rather than leaving them on step 3
        // wondering what failed.
        post(route('risk.loss-events.store'), {
            onError: (bag) => {
                const failing = STEPS.find((s) => errorsInStep(bag, s.step) > 0);
                if (failing) setStep(failing.step);
            },
        });
    };

    return (
        <AuthenticatedLayout title="Report Loss Event">
            <Head title="Report Loss Event" />

            <PageHeader
                title="Report a Loss Event"
                subtitle="What happened, how it is classified, and what it cost"
                breadcrumbs={[{ label: 'Loss Events', href: route('risk.loss-events.index') }, { label: 'Report' }]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <StepNav steps={STEPS} step={step} onSelect={setStep} errorsInStep={(s) => errorsInStep(errors, s)} />

                <LossEventForm
                    {...{ step, data, setData, errors, risks, businessUnits, users, options }}
                    showReporter
                    showNearMiss
                />

                <div className="flex items-center justify-between">
                    {step > 1 ? (
                        <button type="button" onClick={() => setStep(step - 1)} className="btn-secondary text-sm">Back</button>
                    ) : (
                        <Link href={route('risk.loss-events.index')} className="btn-secondary text-sm">Cancel</Link>
                    )}

                    {step < STEPS.length ? (
                        <button type="button" onClick={() => setStep(step + 1)} className="btn-primary text-sm">Continue</button>
                    ) : (
                        <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                            <span className="material-symbols-outlined text-lg">save</span> Report Event
                        </button>
                    )}
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
