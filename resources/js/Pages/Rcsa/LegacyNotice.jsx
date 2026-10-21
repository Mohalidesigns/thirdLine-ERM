import { Link } from '@inertiajs/react';

/**
 * The banner on every legacy (register-based) RCSA screen once RCSA v2 is on.
 *
 * RCSA-02: the legacy module reads the enterprise risk register, v2 reads the
 * cycle tables, and a user looking at both saw two "Dashboard"s with different
 * numbers. This says which one they are on. It is not dismissible on purpose —
 * a notice that can be closed is gone by the second visit, and the confusion it
 * prevents does not go away with it.
 *
 * `notice` is App\Support\Rcsa\RcsaCutover::legacyNotice(). It renders nothing
 * when v2 is off: then the legacy module is the install's only RCSA and there
 * is nothing to tell apart. `closedMessage` is for the worksheet, which is the
 * one legacy screen that stops accepting work at cutover.
 */
export default function LegacyNotice({ notice, closedMessage = null }) {
    if (!notice?.v2) {
        return null;
    }

    return (
        <div role="note" aria-label="Legacy RCSA" className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p>
                This is the register-based RCSA kept for audit history. Cycle-based RCSA is under RCSA → Dashboard / Cycles.
                {notice.v2Url && (
                    <>
                        {' '}
                        <Link href={notice.v2Url} className="font-medium underline">Open the cycle-based RCSA Dashboard</Link>
                    </>
                )}
            </p>
            {notice.cutOver && closedMessage && <p className="mt-2 font-medium">{closedMessage}</p>}
        </div>
    );
}

export const WORKSHEET_CLOSED_MESSAGE =
    'This organisation has moved to the new RCSA module, so the old worksheet no longer accepts submissions. '
    + 'File your work under RCSA → My Assessments.';
