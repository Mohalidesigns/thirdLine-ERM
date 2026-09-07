import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";

/**
 * The conflict-resolution screen of §10.4 — a working copy coming back from a
 * laptop, before anything is written.
 *
 * THE CONFLICT ROWS ARE THE FEATURE. An offline round trip that silently
 * applied a fortnight-old file over a colleague's morning would be worse than
 * no offline round trip at all, so a row whose line moved in the system since
 * the export is shown with BOTH answers side by side and defaults to keeping
 * the one already in the system. The user changes that deliberately, row by
 * row.
 *
 * NOTHING IS WRITTEN UNTIL APPLY. Same shape as the universe import: stage,
 * preview, confirm.
 */
const FIELD_LABELS = {
    inherent_likelihood: "Likelihood",
    inherent_impact: "Impact",
    control_effectiveness: "Control effectiveness",
    assessment_rationale: "Rationale",
    treatment_override: "Treatment",
};

export default function Show({ assessment, batch, rows = [], summary = {} }) {
    const { flash } = usePage().props;

    const resolve = (row, action) =>
        router.post(
            route("rcsa.round-trip.resolve", [assessment.id, batch.id, row.id]),
            { action },
            { preserveScroll: true, preserveState: false },
        );

    const apply = () =>
        router.post(route("rcsa.round-trip.apply", [assessment.id, batch.id]));

    const applicable = rows.filter(
        (r) =>
            r.action === "update" &&
            r.changes &&
            Object.keys(r.changes).length > 0,
    );

    return (
        <AppLayout
            header={
                <PageHeader
                    title="Upload a working copy"
                    subtitle={`${assessment.business_unit} · ${batch.original_name}`}
                    breadcrumbs={[
                        {
                            label: "My assessments",
                            href: route("rcsa.assessments.index"),
                        },
                        {
                            label: assessment.business_unit ?? "Assessment",
                            href: route("rcsa.assessments.show", assessment.id),
                        },
                        { label: "Working copy" },
                    ]}
                    actions={
                        !batch.applied &&
                        assessment.editable && (
                            <button
                                type="button"
                                onClick={apply}
                                disabled={applicable.length === 0}
                                className="btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                title={
                                    applicable.length === 0
                                        ? "Nothing is set to be applied."
                                        : `Apply ${applicable.length} change(s)`
                                }
                            >
                                Apply {applicable.length} change
                                {applicable.length === 1 ? "" : "s"}
                            </button>
                        )
                    }
                />
            }
        >
            <Head title="Upload a working copy" />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                    {flash.error}
                </div>
            )}

            {batch.applied && (
                <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    This upload has already been applied — {batch.updated_count}{" "}
                    risk
                    {batch.updated_count === 1 ? "" : "s"} were updated from it.
                </div>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-6">
                <Tile label="Rows in the file" value={summary.total ?? 0} />
                <Tile label="Changes" value={summary.changes ?? 0} />
                <Tile
                    label="Conflicts"
                    value={summary.conflicts ?? 0}
                    tone={summary.conflicts > 0 ? "warn" : undefined}
                />
                <Tile
                    label="Locked"
                    value={summary.locked ?? 0}
                    tone={summary.locked > 0 ? "warn" : undefined}
                />
                <Tile
                    label="Errors"
                    value={summary.errors ?? 0}
                    tone={summary.errors > 0 ? "error" : undefined}
                />
                <Tile label="Unchanged" value={summary.unchanged ?? 0} />
            </div>

            {summary.locked > 0 && (
                <div className="mb-4 rounded-lg border border-gray-300 bg-gray-50 p-4 text-sm text-gray-700">
                    <strong>
                        {summary.locked} risk
                        {summary.locked === 1 ? " is" : "s are"} locked as
                        filed.
                    </strong>{" "}
                    The ORM did not reopen them, so nothing in your file can
                    change them — whatever the file says about them is ignored.
                </div>
            )}

            {summary.conflicts > 0 && (
                <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>
                        {summary.conflicts} risk
                        {summary.conflicts === 1 ? " was" : "s were"} changed in
                        the system after you took your copy.
                    </strong>{" "}
                    Each is set to keep what is already in the system. Switch
                    any of them to your answer if yours is the one that should
                    stand.
                </div>
            )}

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Risk</th>
                                <th>What your file changes</th>
                                <th>What the system holds</th>
                                <th>Keep</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr
                                    key={row.id}
                                    className={
                                        row.status === "error"
                                            ? "bg-red-50/50"
                                            : row.is_conflict
                                              ? "bg-amber-50/50"
                                              : row.is_locked
                                                ? "bg-gray-50"
                                                : undefined
                                    }
                                >
                                    <td className="text-xs text-gray-500">
                                        {row.row_number}
                                    </td>

                                    <td className="text-sm">
                                        <span className="font-medium text-gray-700">
                                            {row.risk_no ?? "—"}
                                        </span>
                                        <span className="block max-w-sm truncate text-xs text-gray-500">
                                            {row.potential_risk}
                                        </span>
                                        {row.errors.map((error, i) => (
                                            <span
                                                key={i}
                                                className={`mt-1 block text-xs ${
                                                    error.severity === "error"
                                                        ? "text-red-700"
                                                        : "text-amber-800"
                                                }`}
                                            >
                                                {error.message}
                                            </span>
                                        ))}
                                    </td>

                                    <td className="text-sm">
                                        {Object.keys(row.changes ?? {})
                                            .length === 0 ? (
                                            <span className="text-xs text-gray-400">
                                                Nothing
                                            </span>
                                        ) : (
                                            <ul className="space-y-0.5">
                                                {Object.entries(
                                                    row.changes,
                                                ).map(([field, value]) => (
                                                    <li
                                                        key={field}
                                                        className="text-xs"
                                                    >
                                                        <span className="text-gray-500">
                                                            {FIELD_LABELS[
                                                                field
                                                            ] ?? field}
                                                            :{" "}
                                                        </span>
                                                        <span className="font-medium text-gray-800">
                                                            {String(value)}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>

                                    <td className="text-sm">
                                        {Object.keys(row.theirs ?? {})
                                            .length === 0 ? (
                                            <span className="text-xs text-gray-400">
                                                —
                                            </span>
                                        ) : (
                                            <ul className="space-y-0.5">
                                                {Object.entries(row.theirs).map(
                                                    ([field, value]) => (
                                                        <li
                                                            key={field}
                                                            className="text-xs"
                                                        >
                                                            <span className="text-gray-500">
                                                                {FIELD_LABELS[
                                                                    field
                                                                ] ?? field}
                                                                :{" "}
                                                            </span>
                                                            <span className="font-medium text-gray-800">
                                                                {value ===
                                                                    null ||
                                                                value === ""
                                                                    ? "—"
                                                                    : String(
                                                                          value,
                                                                      )}
                                                            </span>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}
                                    </td>

                                    <td>
                                        {row.status === "error" ? (
                                            <span className="text-xs text-red-700">
                                                Cannot be applied
                                            </span>
                                        ) : row.is_locked ? (
                                            /*
                                             * P5's rule, said BEFORE the choice
                                             * rather than after apply refuses
                                             * it. A "keep mine" button on a row
                                             * the server will reject is a
                                             * button that lies.
                                             */
                                            <span className="text-xs text-gray-500">
                                                Locked as filed
                                            </span>
                                        ) : Object.keys(row.changes ?? {})
                                              .length === 0 ? (
                                            <span className="text-xs text-gray-400">
                                                Nothing to keep
                                            </span>
                                        ) : batch.applied ? (
                                            <span className="text-xs text-gray-500">
                                                {row.action === "update"
                                                    ? "Yours"
                                                    : "System"}
                                            </span>
                                        ) : (
                                            <div className="flex rounded-md border border-gray-300 p-0.5">
                                                {[
                                                    ["update", "Mine"],
                                                    ["skip", "System"],
                                                ].map(([action, text]) => (
                                                    <button
                                                        key={action}
                                                        type="button"
                                                        onClick={() =>
                                                            resolve(row, action)
                                                        }
                                                        className={`rounded px-2 py-0.5 text-xs ${
                                                            row.action ===
                                                            action
                                                                ? "bg-[var(--color-primary)] text-white"
                                                                : "text-gray-600"
                                                        }`}
                                                    >
                                                        {text}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <p className="mt-3 text-xs text-gray-500">
                Only likelihood, impact, control effectiveness, the treatment
                and the rationale are read back from the file. Every calculated
                column is recomputed by the system, so anything typed into a
                grey cell is discarded rather than trusted.
            </p>
        </AppLayout>
    );
}

function Tile({ label, value, tone }) {
    const border =
        tone === "error"
            ? "border-red-200 bg-red-50"
            : tone === "warn"
              ? "border-amber-200 bg-amber-50"
              : "border-gray-200 bg-white";
    const text =
        tone === "error"
            ? "text-red-700"
            : tone === "warn"
              ? "text-amber-800"
              : "text-gray-800";

    return (
        <div className={`rounded-lg border p-4 ${border}`}>
            <p className="text-xs uppercase tracking-wider text-gray-500">
                {label}
            </p>
            <p className={`mt-1 text-2xl font-semibold ${text}`}>{value}</p>
        </div>
    );
}
