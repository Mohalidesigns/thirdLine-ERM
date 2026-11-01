import { useEffect, useState } from 'react';
import { DELIVERY_SENTENCE, NOTICE_STATUS, ROLE_LABELS, formatDay, formatLagosTime, recipientStatusWords, tierWords } from '@/lib/kri-format';

/**
 * One return's request and reminder history (docs/ui/kri-cycle/04 section 3.5).
 * Mounted only when expanded - a closed row's notices are not in the DOM.
 *
 * `sent` is "Handed to mail server", never "Delivered": bounces are not tracked
 * (ADR section 5), and the sentence says so.
 *
 * The row carries the latest three notices. When there are more, the full list
 * is fetched once on expand from risk.kri.returns.notices (A1.5), as JSON.
 */
function NoticeBlock({ notice }) {
    const title = notice.kind === 'request' ? 'Monthly request' : `Reminder ${notice.sequence}`;

    return (
        <li className="rounded-lg border border-gray-200 bg-white p-3">
            <h4 className="text-sm font-semibold text-gray-900">{title}</h4>
            <p className="text-xs text-gray-700">
                {formatDay(notice.sent_on)} {'·'} by {notice.sent_by ? notice.sent_by.name : 'the scheduler'} {'·'} reached {tierWords(notice.tier).toLowerCase()} {'·'} {NOTICE_STATUS[notice.status] ?? notice.status}
            </p>

            {notice.kind === 'request' && notice.status === 'no_recipients' && (
                <p className="mt-2 text-sm text-amber-900">The monthly request went to nobody because no risk champion was set at the time.</p>
            )}
            {notice.kind !== 'request' && notice.status === 'no_recipients' && (
                <p className="mt-2 text-sm text-amber-900">This reminder went to nobody: nobody at that level could receive it.</p>
            )}

            {(notice.recipients ?? []).length > 0 && (
                <div className="mt-2 overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <caption className="sr-only">Who received {title}</caption>
                        <thead>
                            <tr className="text-gray-700">
                                <th scope="col" className="py-1 pr-3 font-medium">Name</th>
                                <th scope="col" className="py-1 pr-3 font-medium">Email</th>
                                <th scope="col" className="py-1 pr-3 font-medium">Role</th>
                                <th scope="col" className="py-1 pr-3 font-medium">Status</th>
                                <th scope="col" className="py-1 font-medium">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            {notice.recipients.map((r) => (
                                <tr key={`${r.email}-${r.contact_role}`} className="border-t border-gray-100 align-top text-gray-800">
                                    <td className="py-1 pr-3">{r.name}</td>
                                    <td className="py-1 pr-3 break-all">{r.email}</td>
                                    <td className="py-1 pr-3">{ROLE_LABELS[r.contact_role] ?? r.contact_role}</td>
                                    <td className="py-1 pr-3">
                                        {recipientStatusWords(r.status, r.skip_reason)}
                                        {r.status === 'failed' && r.error && (
                                            <details className="mt-1">
                                                <summary className="cursor-pointer text-[#1A365D] underline">Why it failed</summary>
                                                <p className="mt-1 break-words text-gray-700">{r.error}</p>
                                            </details>
                                        )}
                                    </td>
                                    <td className="py-1">{r.sent_at ? formatLagosTime(r.sent_at) : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </li>
    );
}

export default function NoticeHistory({ returnId, department, preview = [], total = null, historyUrl, cache, onLoaded }) {
    const cached = cache?.[returnId] ?? null;
    const needsFull = total !== null && total > preview.length;
    const [state, setState] = useState(needsFull && !cached ? 'loading' : 'ready');
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!needsFull || cached) return undefined;

        const controller = new AbortController();

        setState('loading');
        fetch(historyUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: controller.signal })
            .then((res) => {
                if (!res.ok) throw new Error(String(res.status));

                return res.json();
            })
            .then((json) => {
                onLoaded(returnId, json.notices ?? []);
                setState('ready');
            })
            .catch((error) => {
                if (error.name !== 'AbortError') setState('error');
            });

        return () => controller.abort();
    }, [returnId, needsFull, cached, historyUrl, attempt]); // eslint-disable-line react-hooks/exhaustive-deps

    const notices = cached ?? preview;

    return (
        <div role="region" aria-label={`Reminder history for ${department}`} className="space-y-3 bg-gray-50 p-4">
            <p className="text-xs text-gray-700">{DELIVERY_SENTENCE}</p>

            {notices.length === 0 && state !== 'loading' && (
                <p className="text-sm text-gray-800">No request or reminder has been sent for this return.</p>
            )}

            {notices.length > 0 && <ul className="space-y-3">{notices.map((n) => <NoticeBlock key={n.id} notice={n} />)}</ul>}

            {state === 'loading' && <p role="status" className="text-sm text-gray-700">Loading all {total} notices&hellip;</p>}
            {state === 'error' && (
                <p role="alert" className="text-sm text-red-700">
                    Couldn&apos;t load the full history. Showing the latest {preview.length} of {total}.{' '}
                    <button type="button" onClick={() => setAttempt((n) => n + 1)} className="underline">Try again</button>
                </p>
            )}
        </div>
    );
}
