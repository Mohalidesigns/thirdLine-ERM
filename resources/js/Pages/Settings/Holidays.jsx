import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FailureBanner from '@/Components/Kri/FailureBanner';
import InfoStrip from '@/Components/Kri/InfoStrip';
import useVisitFailures from '@/hooks/useVisitFailures';
import { formatDay, isWeekend, monthName, yearOf } from '@/lib/kri-format';

/** The organisation's non-working days (docs/ui/kri-cycle/06), and what they do to the cycle. */
export default function Holidays({ year, years = [], holidays = [], firstWorkingDays = [] }) {
    const flash = usePage().props.flash ?? {};
    const { failure, clear } = useVisitFailures();
    const { data, setData, post, processing, errors, reset } = useForm({ holiday_date: '', name: '' });
    const [status, setStatus] = useState('');
    const [removing, setRemoving] = useState(null);
    const [removeBusy, setRemoveBusy] = useState(false);
    const [removeFailed, setRemoveFailed] = useState(false);
    const [leaveTo, setLeaveTo] = useState(null);
    const [slow, setSlow] = useState(false);
    const dateRef = useRef(null);
    const formRef = useRef(null);
    const bypass = useRef(false);
    const slowTimer = useRef(null);

    const dirty = data.holiday_date !== '' || data.name !== '';
    const sorted = [...holidays].sort((a, b) => a.date.localeCompare(b.date));
    const weekend = data.holiday_date ? isWeekend(data.holiday_date) : null;
    const otherYear = data.holiday_date && yearOf(data.holiday_date) !== year ? yearOf(data.holiday_date) : null;
    const emptyList = holidays.length === 0;
    const currentOrFuture = year >= Number(new Date().getFullYear());

    // Following a year link with typed content asks first.
    useEffect(() => {
        if (!dirty) return undefined;

        return router.on('before', (event) => {
            const visit = event.detail.visit;

            if (bypass.current || visit.method !== 'get' || (visit.only ?? []).length > 0 || visit.preserveState === true) return;

            event.preventDefault();
            setLeaveTo(visit.url.href);
        });
    }, [dirty]);

    useEffect(() => {
        if (Object.keys(errors).length > 0) {
            requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.focus());
        }
    }, [errors]);

    const submit = (e) => {
        e.preventDefault();
        setStatus('');
        clear();
        setSlow(false);
        slowTimer.current = setTimeout(() => setSlow(true), 15000);

        const entry = { date: data.holiday_date, name: data.name };

        post(route('risk.holidays.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setStatus(`Added ${entry.name}, ${formatDay(entry.date)}, under ${yearOf(entry.date)}.`);
                reset();
                requestAnimationFrame(() => dateRef.current?.focus());
            },
            onFinish: () => {
                clearTimeout(slowTimer.current);
                setSlow(false);
            },
        });
    };

    // After a lost response the save may have landed: look before offering a retry (06 section 4).
    const checkAfterNetworkError = () => {
        clear();
        router.reload({
            only: ['holidays', 'firstWorkingDays'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if ((page.props.holidays ?? []).some((h) => h.date === data.holiday_date && h.name === data.name)) {
                    setStatus(`Added ${data.name}, ${formatDay(data.holiday_date)}, under ${yearOf(data.holiday_date)}.`);
                    reset();
                }
            },
        });
    };

    const remove = () => {
        const target = removing;

        setRemoveBusy(true);
        setRemoveFailed(false);
        router.delete(route('risk.holidays.destroy', target.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setRemoving(null);
                setStatus(`Removed ${target.name}.`);
                requestAnimationFrame(() => dateRef.current?.focus());
            },
            onError: () => { setRemoving(null); setRemoveFailed(true); },
            onFinish: () => setRemoveBusy(false),
        });
    };

    useEffect(() => {
        if (failure?.kind === 'notfound') {
            setRemoving(null);
            setStatus('That holiday was already removed.');
            router.reload({ only: ['holidays', 'firstWorkingDays'], preserveScroll: true, preserveState: true });
        }
    }, [failure]);

    const star = <><span aria-hidden="true" className="text-red-600"> *</span><span className="sr-only"> (required)</span></>;

    return (
        <AuthenticatedLayout title="Organisation Holidays">
            <Head title="Organisation Holidays" />

            <PageHeader
                title="Organisation Holidays"
                subtitle="Days that are not working days for KRI returns"
                breadcrumbs={[{ label: 'KRI Monitoring', href: route('risk.kri.index') }, { label: 'Holidays' }]}
            />

            <div className="max-w-6xl">
                <div role="status" className="mb-4 min-h-[1.25rem] text-sm font-medium text-green-800">{status || flash.success}</div>

                <FailureBanner
                    failure={failure && failure.kind !== 'notfound' ? (failure.kind === 'network' ? { ...failure, message: "Couldn't save. Nothing was added. Try again." } : failure) : null}
                    onRetry={failure?.kind === 'network' ? checkAfterNetworkError : undefined}
                    retryLabel="Check and try again"
                    onDismiss={clear}
                />
                {removeFailed && <InfoStrip tone="error" role="alert" className="mb-4">Couldn&apos;t remove it. Nothing was changed. Try again.</InfoStrip>}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        <InfoStrip>Saturdays and Sundays are never working days, so you don&apos;t need to add them. Add public holidays and any other days the bank is closed. Dates move each year (Eid, for example), so enter each year&apos;s dates when they&apos;re announced.</InfoStrip>

                        <nav aria-label="Year" className="flex flex-wrap items-center gap-2">
                            <h2 className="mr-2 text-lg font-semibold text-[#1A365D]">Holidays in {year}</h2>
                            {years.map((y) => (
                                y === year
                                    ? <span key={y} aria-current="page" className="rounded-md bg-[#1A365D] px-3 py-2 text-sm font-medium text-white">{y}</span>
                                    : <Link key={y} href={route('risk.holidays.index', { year: y })} className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-[#1A365D] focus:outline-none focus:ring-2 focus:ring-[#1A365D]">{y}</Link>
                            ))}
                        </nav>

                        <form ref={formRef} onSubmit={submit} noValidate className="rounded-xl border border-gray-200 bg-white p-4">
                            <h3 className="mb-3 text-sm font-semibold text-[#1A365D]">Add a holiday</h3>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label htmlFor="holiday-date" className="mb-1 block text-xs font-medium text-gray-700">Date{star}</label>
                                    <input
                                        id="holiday-date"
                                        ref={dateRef}
                                        type="date"
                                        required
                                        aria-required="true"
                                        placeholder="YYYY-MM-DD"
                                        value={data.holiday_date}
                                        onChange={(e) => setData('holiday_date', e.target.value)}
                                        aria-invalid={errors.holiday_date ? 'true' : undefined}
                                        aria-describedby={['holiday-date-note', errors.holiday_date ? 'holiday-date-error' : null].filter(Boolean).join(' ')}
                                        className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D] sm:text-sm"
                                    />
                                    <div id="holiday-date-note" className="mt-1 text-xs text-gray-700">
                                        {weekend && <p>That&apos;s a weekend. Adding it changes nothing.</p>}
                                        {otherYear && <p>This falls in {otherYear}; it will appear under that year.</p>}
                                    </div>
                                    <InputError message={errors.holiday_date} id="holiday-date-error" className="mt-1" />
                                </div>
                                <div className="sm:col-span-2">
                                    <label htmlFor="holiday-name" className="mb-1 block text-xs font-medium text-gray-700">Name{star}</label>
                                    <input
                                        id="holiday-name"
                                        type="text"
                                        maxLength={150}
                                        required
                                        aria-required="true"
                                        placeholder="Eid al-Fitr"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        aria-invalid={errors.name ? 'true' : undefined}
                                        aria-describedby={['holiday-name-help', errors.name ? 'holiday-name-error' : null].filter(Boolean).join(' ')}
                                        className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D] sm:text-sm"
                                    />
                                    <p id="holiday-name-help" className="mt-1 text-xs text-gray-700">Shown in this list only</p>
                                    <InputError message={errors.name} id="holiday-name-error" className="mt-1" />
                                </div>
                            </div>
                            <div className="mt-4">
                                <button type="submit" disabled={processing} aria-busy={processing} className="btn-primary min-h-11 w-full text-sm sm:w-auto">{processing ? 'Saving…' : 'Add holiday'}</button>
                                {slow && <p role="status" className="mt-2 text-sm text-gray-800">Still working. Your connection is slow. Don&apos;t close this page.</p>}
                            </div>
                        </form>

                        {emptyList ? (
                            <div className="rounded-xl border border-gray-200 bg-white">
                                <EmptyState title={`No holidays recorded for ${year}`} description="Every weekday is being treated as a working day. Add the bank's holidays so the monthly request opens on the right day." />
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                                <table className="data-table w-full">
                                    <caption className="sr-only">Holidays in {year}</caption>
                                    <thead>
                                        <tr><th scope="col">Date</th><th scope="col">Day</th><th scope="col">Name</th><th scope="col"><span className="sr-only">Remove</span></th></tr>
                                    </thead>
                                    <tbody>
                                        {sorted.map((h) => (
                                            <tr key={h.id}>
                                                <th scope="row" className="text-left font-medium">{formatDay(h.date)}</th>
                                                <td>{h.weekday}</td>
                                                <td>{h.name}</td>
                                                <td>
                                                    <button type="button" onClick={() => setRemoving(h)} aria-label={`Remove ${h.name}, ${formatDay(h.date)}`} className="btn-secondary min-h-11 text-sm sm:min-h-0">Remove</button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    <div className="space-y-4">
                        {emptyList && currentOrFuture && (
                            <InfoStrip tone="warning">With no holidays listed, the first working day of each month is simply the first weekday.</InfoStrip>
                        )}
                        {firstWorkingDays.length > 0 && (
                            <section className="rounded-xl border border-gray-200 bg-white p-4">
                                <h2 className="text-sm font-semibold text-[#1A365D]">First working day of each month</h2>
                                <p className="mt-1 text-sm text-gray-800">These are the days the monthly KRI request opens, given the holidays listed.</p>
                                <table className="mt-3 w-full text-left text-sm">
                                    <caption className="sr-only">First working day of each month in {year}</caption>
                                    <thead><tr className="text-gray-700"><th scope="col" className="py-1 font-medium">Month</th><th scope="col" className="py-1 font-medium">Date</th></tr></thead>
                                    <tbody>
                                        {firstWorkingDays.map((m) => (
                                            <tr key={m.month} className="border-t border-gray-100 text-gray-900">
                                                <th scope="row" className="py-1 text-left font-normal">{monthName(m.month)}</th>
                                                <td className="py-1">{formatDay(m.date)}{m.moved_by_holiday && <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">moved by a holiday</span>}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <p className="mt-3 text-xs text-gray-700">Changing the list never moves a return that is already open. Each return&apos;s due date was fixed when its request went out.</p>
                            </section>
                        )}
                    </div>
                </div>
            </div>

            <ConfirmDialog
                show={removing !== null}
                title={removing ? `Remove ${removing.name}?` : 'Remove'}
                message={removing ? `${formatDay(removing.date)} becomes a working day again for future first-working-day checks and due dates. Returns that are already open keep their due date. The removal is recorded in the audit trail.` : ''}
                confirmLabel="Remove"
                variant="warning"
                processing={removeBusy}
                onCancel={() => setRemoving(null)}
                onConfirm={remove}
            />

            <ConfirmDialog
                show={leaveTo !== null}
                title="Discard the holiday you were adding?"
                message="What you typed hasn't been saved."
                confirmLabel="Discard"
                cancelLabel="Keep editing"
                variant="warning"
                onCancel={() => setLeaveTo(null)}
                onConfirm={() => { const to = leaveTo; bypass.current = true; setLeaveTo(null); router.visit(to); }}
            />
        </AuthenticatedLayout>
    );
}
