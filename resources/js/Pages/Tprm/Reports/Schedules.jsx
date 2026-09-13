import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * Standing report schedules — FR-RPT-09.
 *
 * THE LIST SHOWS EVERY SCHEDULE, including ones reading data you cannot open.
 * That is the opposite of the reports hub's rule and is deliberate: a schedule
 * is a standing instruction to email data out of the institution, and somebody
 * reviewing that estate needs to see a screening log going to an external
 * mailbox every Monday. Names and recipients are shown; contents are not.
 *
 * A FAILED RUN IS LOUD. A schedule that fails silently is worse than no
 * schedule, because everybody believes it is running — so the failure count
 * and the reason sit in the row rather than in a log.
 */
export default function Schedules({
    schedules = [],
    available = [],
    formats = [],
    frequencies = [],
    maxDayOfMonth = 28,
    mailerIsLog = false,
    can = {},
}) {
    const [adding, setAdding] = useState(false);
    const [recipient, setRecipient] = useState('');

    const { data, setData, post, processing, errors, reset } = useForm({
        report_key: available[0]?.key ?? '',
        name: '',
        frequency: 'weekly',
        day_of_week: 1,
        day_of_month: 1,
        send_at: '07:00',
        format: 'xlsx',
        recipients: [],
        is_active: true,
    });

    const addRecipient = () => {
        const value = recipient.trim();
        if (value && !data.recipients.includes(value)) {
            setData('recipients', [...data.recipients, value]);
            setRecipient('');
        }
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('tprm.reports.schedules.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setAdding(false);
            },
        });
    };

    return (
        <AppLayout title="Report schedules">
            <Head title="Report schedules" />

            <PageHeader
                title="Report schedules"
                subtitle="Standing instructions to render a report and email it. Every send is authorised against the schedule owner's permissions, not the recipients'."
            />

            {mailerIsLog && (
                <div className="mb-4 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <strong>This installation has no outbound mail configured.</strong> `MAIL_MAILER` is set to
                    `log`, so a scheduled report is written to the application log rather than delivered. A run
                    will still record as sent, because the log driver accepts the message — do not read that as
                    proof an email arrived.
                </div>
            )}

            {can.manage && (
                <div className="mb-4">
                    <button type="button" className="btn-primary" onClick={() => setAdding(!adding)}>
                        {adding ? 'Cancel' : 'New schedule'}
                    </button>
                </div>
            )}

            {adding && can.manage && (
                <form onSubmit={submit} className="card mb-6 grid gap-4 p-4 md:grid-cols-2">
                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Report</span>
                        <select
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.report_key}
                            onChange={(event) => setData('report_key', event.target.value)}
                        >
                            {available.map((report) => (
                                <option key={report.key} value={report.key}>{report.title}</option>
                            ))}
                        </select>
                        {errors.report_key && (
                            <span className="mt-1 block text-xs text-red-600">{errors.report_key}</span>
                        )}
                        <span className="mt-1 block text-xs text-gray-500">
                            Only reports you can read yourself are listed.
                        </span>
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Name</span>
                        <input
                            type="text"
                            className="w-full rounded border-gray-300 text-sm"
                            placeholder="Monday procurement pack"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                        {errors.name && <span className="mt-1 block text-xs text-red-600">{errors.name}</span>}
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Frequency</span>
                        <select
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.frequency}
                            onChange={(event) => setData('frequency', event.target.value)}
                        >
                            {frequencies.map((frequency) => (
                                <option key={frequency} value={frequency}>
                                    {frequency.charAt(0).toUpperCase() + frequency.slice(1)}
                                </option>
                            ))}
                        </select>
                    </label>

                    {data.frequency === 'weekly' && (
                        <label className="text-sm">
                            <span className="mb-1 block font-medium text-gray-700">Day</span>
                            <select
                                className="w-full rounded border-gray-300 text-sm"
                                value={data.day_of_week}
                                onChange={(event) => setData('day_of_week', Number(event.target.value))}
                            >
                                {['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
                                    .map((day, index) => (
                                        <option key={day} value={index + 1}>{day}</option>
                                    ))}
                            </select>
                        </label>
                    )}

                    {data.frequency === 'monthly' && (
                        <label className="text-sm">
                            <span className="mb-1 block font-medium text-gray-700">Day of month</span>
                            <input
                                type="number"
                                min="1"
                                max={maxDayOfMonth}
                                className="w-full rounded border-gray-300 text-sm"
                                value={data.day_of_month}
                                onChange={(event) => setData('day_of_month', Number(event.target.value))}
                            />
                            {errors.day_of_month && (
                                <span className="mt-1 block text-xs text-red-600">{errors.day_of_month}</span>
                            )}
                            <span className="mt-1 block text-xs text-gray-500">
                                Capped at {maxDayOfMonth}. A later day would skip February.
                            </span>
                        </label>
                    )}

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Send at</span>
                        <input
                            type="time"
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.send_at}
                            onChange={(event) => setData('send_at', event.target.value)}
                        />
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Format</span>
                        <select
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.format}
                            onChange={(event) => setData('format', event.target.value)}
                        >
                            {formats.map((format) => (
                                <option key={format} value={format}>{format.toUpperCase()}</option>
                            ))}
                        </select>
                    </label>

                    <div className="text-sm md:col-span-2">
                        <span className="mb-1 block font-medium text-gray-700">Recipients</span>
                        <div className="flex gap-2">
                            <input
                                type="email"
                                className="flex-1 rounded border-gray-300 text-sm"
                                placeholder="procurement@bank.example"
                                value={recipient}
                                onChange={(event) => setRecipient(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        addRecipient();
                                    }
                                }}
                            />
                            <button type="button" className="btn-secondary" onClick={addRecipient}>
                                Add
                            </button>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {data.recipients.map((address) => (
                                <span
                                    key={address}
                                    className="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700"
                                >
                                    {address}
                                    <button
                                        type="button"
                                        className="ml-2 text-gray-400"
                                        onClick={() => setData('recipients', data.recipients.filter((r) => r !== address))}
                                    >
                                        ×
                                    </button>
                                </span>
                            ))}
                        </div>
                        {errors.recipients && (
                            <span className="mt-1 block text-xs text-red-600">{errors.recipients}</span>
                        )}
                        <span className="mt-1 block text-xs text-gray-500">
                            Addresses, not users — a distribution list is frequently a shared mailbox. The report
                            is still rendered with your permissions.
                        </span>
                    </div>

                    <div className="md:col-span-2">
                        <button type="submit" className="btn-primary" disabled={processing}>
                            Create schedule
                        </button>
                    </div>
                </form>
            )}

            <div className="card overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Schedule</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Report</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">When</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Recipients</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Owner</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Last run</th>
                            <th scope="col" className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {schedules.map((schedule) => (
                            <tr key={schedule.uuid} className={schedule.is_active ? '' : 'opacity-60'}>
                                <td className="px-4 py-2 font-medium">
                                    {schedule.name}
                                    {!schedule.is_active && (
                                        <span className="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs">Paused</span>
                                    )}
                                </td>
                                <td className="px-4 py-2">
                                    {schedule.report_title}
                                    <div className="text-xs text-gray-400">{schedule.format}</div>
                                </td>
                                <td className="px-4 py-2 text-xs">{schedule.frequency}</td>
                                <td className="px-4 py-2 text-xs">
                                    {schedule.recipients.length} — {schedule.recipients.join(', ')}
                                </td>
                                <td className="px-4 py-2">{schedule.owner ?? 'Account removed'}</td>
                                <td className="px-4 py-2 text-xs">
                                    <span
                                        className={
                                            schedule.last_run_status === 'failed'
                                                ? 'font-medium text-red-700'
                                                : schedule.last_run_status === 'skipped'
                                                  ? 'font-medium text-amber-700'
                                                  : 'text-gray-600'
                                        }
                                    >
                                        {schedule.last_run}
                                    </span>
                                    {schedule.consecutive_failures > 0 && (
                                        <div className="text-red-700">
                                            {schedule.consecutive_failures} consecutive failures
                                        </div>
                                    )}
                                </td>
                                <td className="px-4 py-2 text-right">
                                    {can.manage && (
                                        <div className="flex justify-end gap-2 text-xs">
                                            <button
                                                type="button"
                                                className="text-indigo-600"
                                                onClick={() =>
                                                    router.post(
                                                        route('tprm.reports.schedules.run', schedule.uuid),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Send now
                                            </button>
                                            <button
                                                type="button"
                                                className="text-red-600"
                                                onClick={() =>
                                                    router.delete(
                                                        route('tprm.reports.schedules.destroy', schedule.uuid),
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {schedules.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">
                                    No standing schedules. Every report can still be exported on demand.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <p className="mt-4 text-xs text-gray-500">
                &ldquo;Send now&rdquo; sends the real report to the real recipients, not a test copy to you — a
                test that only proved the render would leave the distribution list unchecked, which is the half
                that is usually wrong.
            </p>
        </AppLayout>
    );
}
