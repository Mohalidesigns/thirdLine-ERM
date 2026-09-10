export default function StatusBadge({ status }) {
    const statusMap = {
        draft: "badge-status-draft",
        planning: "badge-status-pending",
        fieldwork: "badge-status-active",
        reporting: "badge-status-pending",
        completed: "badge-status-completed",
        approved: "badge-status-active",
        active: "badge-status-active",
        in_progress: "badge-status-active",
        pending_approval: "badge-status-pending",
        pending: "badge-status-pending",
        open: "badge-status-active",
        overdue: "badge-status-overdue",
        closed: "badge-status-completed",
        cancelled: "badge-status-draft",
        rejected: "badge-status-overdue",
        on_hold: "badge-status-draft",
        issued: "badge-status-completed",
        review: "badge-status-pending",
        submitted: "badge-status-pending",
        not_started: "badge-status-draft",
        deferred: "badge-status-draft",
        scheduled: "badge-status-pending",
        management_response: "badge-status-pending",
        final_report: "badge-status-pending",
        draft_report: "badge-status-pending",
        pending_validation: "badge-status-pending",
        response_pending: "badge-status-pending",
        action_pending: "badge-status-pending",
        action_in_progress: "badge-status-active",
        reopened: "badge-status-overdue",
        validation_failed: "badge-status-overdue",
        disputed: "badge-status-overdue",
        on_track: "badge-status-active",
        at_risk: "badge-medium",
        breached: "badge-status-overdue",
        excellent: "badge-status-active",
        good: "badge-status-pending",
        needs_improvement: "badge-medium",
        poor: "badge-status-overdue",
        // Investigation module
        reported: "badge-status-pending",
        under_investigation: "badge-status-active",
        pending_review: "badge-status-pending",
        suspended: "badge-medium",
        recommended: "badge-status-pending",
        implemented: "badge-status-completed",
        exonerated: "badge-status-completed",
        culpable: "badge-status-overdue",
        partially_culpable: "badge-medium",
        inconclusive: "badge-status-draft",
        // Master-data lifecycles (RCSA Universe and anything else that governs
        // a reference list). Without these, `published` fell through to the
        // draft styling and a published row was indistinguishable from an
        // unapproved one — on a screen whose entire point is that distinction.
        published: "badge-status-active",
        retired: "badge-status-draft",
        // RCSA v2's workflow states (§9.1). Without these, `under_review`,
        // `validated`, `returned` and `bu_approval` all fell through to the
        // draft styling — so on the review queue, whose whole job is to say
        // what has been decided and what has not, a validated assessment
        // looked exactly like an untouched one.
        bu_approval: "badge-status-pending",
        under_review: "badge-status-active",
        in_review: "badge-status-active",
        validated: "badge-status-completed",
        returned: "badge-status-overdue",
        // The per-line ORM verdict on the same screens.
        accepted: "badge-status-completed",
        flagged: "badge-status-overdue",
        challenged: "badge-medium",
        escalated: "badge-status-overdue",
        // §10.2's export log: a link past its date is not a failure, and
        // without a key of its own it drew as a draft — indistinguishable from
        // an export still queued.
        expired: "badge-status-draft",
        ready: "badge-status-completed",
        processing: "badge-status-active",
        queued: "badge-status-pending",
        failed: "badge-status-overdue",
    };
    const formatted = status ? status.replace(/_/g, " ") : "unknown";
    return (
        <span className={`badge ${statusMap[status] || "badge-status-draft"}`}>
            {formatted.charAt(0).toUpperCase() + formatted.slice(1)}
        </span>
    );
}
