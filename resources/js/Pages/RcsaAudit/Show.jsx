import { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";

/**
 * §11's read-only audit view: everything that happened to one assessment.
 *
 * READ-ONLY IS THE DATA, NOT THE SCREEN. There is no edit control here because
 * there is no endpoint behind one — the transition log and the revisions have
 * no updated_at and no soft delete, and the estate-wide trail is append-only by
 * database trigger.
 *
 * THE SEAL IS SHOWN. A trail displayed without saying whether it still verifies
 * presents tampered rows as fact, which is worse than not showing it.
 */
const FIELD_LABELS = {
    inherent_likelihood: "Likelihood (J)",
    inherent_impact: "Impact (K)",
    control_effectiveness: "Control effectiveness (O)",
    residual_likelihood: "Residual likelihood",
    residual_impact: "Residual impact",
    residual_score: "Residual risk (Q)",
    risk_treatment: "Risk treatment (S)",
    treatment_override: "Treatment override",
    target_date: "Implementation date (W)",
    status: "Status",
};

function label(value) {
    if (!value) return "—";
    const words = String(value).replace(/_/g, " ");
    return words.charAt(0).toUpperCase() + words.slice(1);
}

export default function Show({
    assessment,
    transitions = [],
    revisions = [],
    estate = null,
    can = {},
}) {
    const [tab, setTab] = useState("timeline");

    const tabs = [
        ["timeline", `Workflow (${transitions.length})`],
        ["revisions", `Rating changes (${revisions.length})`],
        ...(can.see_estate_trail
            ? [["estate", `Audit trail (${estate?.length ?? 0})`]]
            : []),
    ];

    const unsealed = (estate ?? []).filter((row) => !row.sealed).length;

    return (
        <AppLayout
            header={
                <PageHeader
                    title="Audit trail"
                    subtitle={`${assessment.business_unit} · ${assessment.cycle}`}
                    breadcrumbs={[
                        {
                            label: "My assessments",
                            href: route("rcsa.assessments.index"),
                        },
                        {
                            label: assessment.business_unit ?? "Assessment",
                            href: route("rcsa.assessments.show", assessment.id),
                        },
                        { label: "Audit" },
                    ]}
                    actions={<StatusBadge status={assessment.status} />}
                />
            }
        >
            <Head title={`Audit — ${assessment.business_unit}`} />

            <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                This record cannot be edited or deleted by anybody, including an
                administrator. The workflow log and the rating changes have no
                update path; the audit trail below them is append-only in the
                database itself and each row is sealed against the one before
                it.
            </div>

            {unsealed > 0 && (
                <div className="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">
                    <strong>
                        {unsealed} row(s) no longer match their seal.
                    </strong>{" "}
                    Somebody has changed this trail at the database level.
                    Report it — do not rely on what is shown below.
                </div>
            )}

            <div className="mb-4 flex gap-1">
                {tabs.map(([key, text]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setTab(key)}
                        className={`rounded px-3 py-1.5 text-sm ${
                            tab === key
                                ? "bg-[var(--color-primary)] text-white"
                                : "bg-white text-gray-600"
                        }`}
                    >
                        {text}
                    </button>
                ))}
            </div>

            {tab === "timeline" && (
                <div className="card p-4">
                    {transitions.length === 0 ? (
                        <Empty>This assessment has not moved yet.</Empty>
                    ) : (
                        <ol className="space-y-3">
                            {transitions.map((event) => (
                                <li
                                    key={event.id}
                                    className="flex gap-3 text-sm"
                                >
                                    <span className="w-40 shrink-0 text-xs text-gray-400">
                                        {event.at}
                                    </span>
                                    <span className="text-gray-700">
                                        <strong>{event.by ?? "System"}</strong>{" "}
                                        {event.event === "escalate"
                                            ? "escalated it"
                                            : `moved it ${event.from ? `from ${label(event.from).toLowerCase()} ` : ""}to ${label(event.to).toLowerCase()}`}
                                        {event.reason && (
                                            <span className="mt-0.5 block text-xs text-gray-500">
                                                “{event.reason}”
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            )}

            {tab === "revisions" && (
                <div className="card">
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Risk</th>
                                    <th>Field</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>By</th>
                                    <th>Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                {revisions.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="py-10 text-center text-sm text-gray-400"
                                        >
                                            No ratings have been changed yet.
                                        </td>
                                    </tr>
                                )}
                                {revisions.map((row) => (
                                    <tr key={row.id}>
                                        <td className="whitespace-nowrap text-xs text-gray-500">
                                            {row.at}
                                        </td>
                                        <td className="text-sm font-medium text-gray-700">
                                            {row.risk_no ?? "—"}
                                        </td>
                                        <td className="text-sm text-gray-600">
                                            {FIELD_LABELS[row.field] ??
                                                label(row.field)}
                                        </td>
                                        <td className="text-sm text-gray-500">
                                            {row.old ?? "—"}
                                        </td>
                                        <td className="text-sm font-medium text-gray-800">
                                            {row.new ?? "—"}
                                        </td>
                                        <td className="text-sm text-gray-600">
                                            {row.by ?? "System"}
                                        </td>
                                        <td className="max-w-xs text-xs text-gray-500">
                                            {row.reason ?? ""}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {tab === "estate" && can.see_estate_trail && (
                <div className="card">
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>What</th>
                                    <th>Field</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>By</th>
                                    <th>From where</th>
                                    <th>Seal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(estate ?? []).length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="py-10 text-center text-sm text-gray-400"
                                        >
                                            Nothing recorded yet.
                                        </td>
                                    </tr>
                                )}
                                {(estate ?? []).map((row) => (
                                    <tr
                                        key={row.id}
                                        className={
                                            row.sealed ? undefined : "bg-red-50"
                                        }
                                    >
                                        <td className="whitespace-nowrap text-xs text-gray-500">
                                            {row.at}
                                        </td>
                                        <td className="text-xs text-gray-600">
                                            {label(row.action)}
                                        </td>
                                        <td className="text-sm text-gray-600">
                                            {FIELD_LABELS[row.field] ??
                                                label(row.field)}
                                        </td>
                                        <td className="text-sm text-gray-500">
                                            {row.old ?? "—"}
                                        </td>
                                        <td className="text-sm font-medium text-gray-800">
                                            {row.new ?? "—"}
                                        </td>
                                        <td className="text-sm text-gray-600">
                                            {row.by ?? "System"}
                                        </td>
                                        <td className="text-xs text-gray-500">
                                            {row.ip ?? "—"}
                                        </td>
                                        <td className="text-xs">
                                            {row.sealed ? (
                                                <span className="text-green-700">
                                                    Verified
                                                </span>
                                            ) : (
                                                <span className="font-semibold text-red-700">
                                                    Broken
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <p className="mt-3 text-xs text-gray-500">
                <Link
                    href={route("rcsa.assessments.show", assessment.id)}
                    className="text-[var(--color-primary)] hover:underline"
                >
                    Back to the assessment
                </Link>
            </p>
        </AppLayout>
    );
}

function Empty({ children }) {
    return <p className="py-8 text-center text-sm text-gray-400">{children}</p>;
}
