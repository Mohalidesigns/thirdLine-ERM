import { useEffect, useState } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";

/**
 * The authorised bulk download and its log (§10.1, §10.2).
 *
 * THE ROW COUNT IS SHOWN BEFORE THE BUTTON IS PRESSED, and so is whether the
 * file will arrive now or as a link. An export that silently became a
 * background job is one people press three times and then email about.
 *
 * THE LOG IS PART OF THE SCREEN, not a separate admin page. Anyone who can
 * export sees what they have exported; an administrator sees everybody's, with
 * the IP. §10.2 asks for the second and the first is what makes the control
 * visible to the people it applies to.
 */
export default function Index({
    log,
    sees_everything: seesEverything,
    cycles = [],
    units = [],
    statuses = [],
    sync_limit: syncLimit,
    link_ttl_hours: linkTtlHours,
}) {
    const { flash } = usePage().props;
    const [preview, setPreview] = useState(null);
    const [checking, setChecking] = useState(false);

    const form = useForm({
        cycle: "",
        business_units: [],
        residual_level: "",
        treatment: "",
        appetite: "",
        assessment_status: "",
        from: "",
        to: "",
    });

    // The count follows the filters, debounced. It is a GET against the same
    // query the export runs, so what the screen promises and what the file
    // contains cannot disagree.
    useEffect(() => {
        const timer = setTimeout(() => {
            setChecking(true);
            window.axios
                .get(route("rcsa.exports.preview"), { params: form.data })
                .then((response) => setPreview(response.data))
                .catch(() => setPreview(null))
                .finally(() => setChecking(false));
        }, 350);

        return () => clearTimeout(timer);
    }, [form.data]);

    /**
     * A NATIVE FORM POST, not an Inertia visit — and this is not a style
     * choice. A small export streams the .xlsx back in the response, and Inertia
     * cannot consume a file: it would try to parse the workbook as a page and
     * the download would never reach the user. A real form submit lets the
     * browser do what browsers do with an attachment, and the large-export path
     * still works because its 302 back to this page is an ordinary redirect.
     */
    const submit = (e) => {
        e.preventDefault();

        const token =
            document.querySelector('meta[name="csrf-token"]')?.content ?? "";
        const el = document.createElement("form");
        el.method = "POST";
        el.action = route("rcsa.exports.store");
        el.style.display = "none";

        const add = (name, value) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = name;
            input.value = value;
            el.appendChild(input);
        };

        add("_token", token);

        Object.entries(form.data).forEach(([key, value]) => {
            if (
                value === "" ||
                value === null ||
                (Array.isArray(value) && value.length === 0)
            ) {
                return;
            }

            if (Array.isArray(value)) {
                value.forEach((entry) => add(`${key}[]`, entry));
            } else {
                add(key, value);
            }
        });

        document.body.appendChild(el);
        el.submit();
        document.body.removeChild(el);
    };

    return (
        <AppLayout
            header={
                <PageHeader
                    title="Export the RCSA register"
                    subtitle="The 23-column workbook, as the regulator reads it"
                    breadcrumbs={[{ label: "RCSA" }, { label: "Export" }]}
                />
            }
        >
            <Head title="Export the RCSA register" />

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

            <form onSubmit={submit} className="card mb-6 p-4">
                <div className="grid gap-3 md:grid-cols-4">
                    <Field label="Cycle">
                        <select
                            className="filter-select w-full"
                            value={form.data.cycle}
                            onChange={(e) =>
                                form.setData("cycle", e.target.value)
                            }
                        >
                            <option value="">Every cycle</option>
                            {cycles.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Appetite">
                        <select
                            className="filter-select w-full"
                            value={form.data.appetite}
                            onChange={(e) =>
                                form.setData("appetite", e.target.value)
                            }
                        >
                            <option value="">Above and within</option>
                            <option value="above">Above appetite only</option>
                            <option value="within">Within appetite only</option>
                        </select>
                    </Field>

                    <Field label="Residual level">
                        <select
                            className="filter-select w-full"
                            value={form.data.residual_level}
                            onChange={(e) =>
                                form.setData("residual_level", e.target.value)
                            }
                        >
                            <option value="">Any level</option>
                            {[
                                "very_low",
                                "low",
                                "medium",
                                "high",
                                "very_high",
                            ].map((level) => (
                                <option key={level} value={level}>
                                    {level.replace(/_/g, " ")}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Assessment status">
                        <select
                            className="filter-select w-full"
                            value={form.data.assessment_status}
                            onChange={(e) =>
                                form.setData(
                                    "assessment_status",
                                    e.target.value,
                                )
                            }
                        >
                            <option value="">Any status</option>
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {s.replace(/_/g, " ")}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Submitted from">
                        <input
                            type="date"
                            className="form-input w-full text-sm"
                            value={form.data.from}
                            onChange={(e) =>
                                form.setData("from", e.target.value)
                            }
                        />
                    </Field>

                    <Field label="Submitted to">
                        <input
                            type="date"
                            className="form-input w-full text-sm"
                            value={form.data.to}
                            onChange={(e) => form.setData("to", e.target.value)}
                        />
                        {form.errors.to && (
                            <p className="mt-1 text-xs text-red-600">
                                {form.errors.to}
                            </p>
                        )}
                    </Field>

                    <Field
                        label={`Business units (${form.data.business_units.length || "all"})`}
                    >
                        <select
                            multiple
                            size={3}
                            className="form-select w-full text-sm"
                            value={form.data.business_units}
                            onChange={(e) =>
                                form.setData(
                                    "business_units",
                                    [...e.target.selectedOptions].map(
                                        (o) => o.value,
                                    ),
                                )
                            }
                        >
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                    <button
                        type="submit"
                        className="btn-primary"
                        disabled={form.processing || preview?.rows === 0}
                    >
                        Export to Excel
                    </button>

                    <span className="text-sm text-gray-600">
                        {checking && "Counting…"}
                        {!checking && preview && preview.rows === 0 && (
                            <span className="text-amber-700">
                                Those filters select no risks. Nothing would be
                                exported, and nothing logged.
                            </span>
                        )}
                        {!checking && preview && preview.rows > 0 && (
                            <>
                                <strong>{preview.rows}</strong> risk
                                {preview.rows === 1 ? "" : "s"} —{" "}
                                {preview.synchronous ? (
                                    "downloads straight away."
                                ) : (
                                    <>
                                        over {syncLimit}, so it runs in the
                                        background and you get a link that lasts{" "}
                                        {linkTtlHours} hours.
                                    </>
                                )}
                            </>
                        )}
                    </span>

                    <button
                        type="button"
                        className="ml-auto text-xs text-gray-500 hover:underline"
                        onClick={() => form.reset()}
                    >
                        Clear filters
                    </button>
                </div>

                <p className="mt-3 text-xs text-gray-500">
                    Every export is logged with your name, these filters, the
                    row count and your IP address. A completed RCSA is the
                    bank's operational risk profile in one file.
                </p>
            </form>

            <h2 className="mb-2 text-sm font-semibold text-gray-700">
                {seesEverything ? "Export log — everybody" : "Your exports"}
            </h2>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                {seesEverything && <th>Who</th>}
                                <th>Filters</th>
                                <th className="text-right">Rows</th>
                                <th>Status</th>
                                <th>Collected</th>
                                {seesEverything && <th>From</th>}
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {log.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="py-10 text-center text-sm text-gray-400"
                                    >
                                        Nothing exported yet.
                                    </td>
                                </tr>
                            )}

                            {log.data.map((row) => (
                                <tr key={row.id}>
                                    <td className="whitespace-nowrap text-sm text-gray-600">
                                        {row.created_at}
                                    </td>
                                    {seesEverything && (
                                        <td className="text-sm text-gray-700">
                                            {row.user}
                                            {row.is_mine && (
                                                <span className="ml-1 text-xs text-gray-400">
                                                    (you)
                                                </span>
                                            )}
                                        </td>
                                    )}
                                    <td className="text-xs text-gray-600">
                                        {Object.keys(row.filters).length ===
                                        0 ? (
                                            <span className="text-gray-400">
                                                Everything
                                            </span>
                                        ) : (
                                            Object.entries(row.filters)
                                                .map(
                                                    ([key, value]) =>
                                                        `${key.replace(/_/g, " ")}: ${
                                                            Array.isArray(value)
                                                                ? value.join(
                                                                      ", ",
                                                                  )
                                                                : value
                                                        }`,
                                                )
                                                .join(" · ")
                                        )}
                                    </td>
                                    <td className="text-right text-sm tabular-nums text-gray-700">
                                        {row.row_count}
                                    </td>
                                    <td>
                                        <StatusBadge
                                            status={
                                                row.has_expired
                                                    ? "expired"
                                                    : row.status
                                            }
                                        />
                                        {row.failure_reason && (
                                            <span className="block text-xs text-red-600">
                                                {row.failure_reason}
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-sm text-gray-600">
                                        {row.downloaded_at ? (
                                            <>
                                                {row.downloaded_at}
                                                {row.download_count > 1 && (
                                                    <span className="block text-xs text-gray-400">
                                                        {row.download_count}{" "}
                                                        times
                                                    </span>
                                                )}
                                            </>
                                        ) : (
                                            <span className="text-gray-400">
                                                Not yet
                                            </span>
                                        )}
                                    </td>
                                    {seesEverything && (
                                        <td className="text-xs text-gray-500">
                                            {row.ip_address ?? "—"}
                                        </td>
                                    )}
                                    <td className="text-right">
                                        {row.download_url ? (
                                            <a
                                                href={row.download_url}
                                                className="text-xs text-[var(--color-primary)] hover:underline"
                                            >
                                                Download
                                            </a>
                                        ) : (
                                            <span className="text-xs text-gray-400">
                                                {row.has_expired
                                                    ? "Link expired"
                                                    : "—"}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {log.links && log.links.length > 3 && (
                <div className="mt-4 flex flex-wrap gap-1">
                    {log.links.map((link, i) => (
                        <Link
                            key={i}
                            href={link.url ?? "#"}
                            className={`rounded px-3 py-1 text-xs ${
                                link.active
                                    ? "bg-[var(--color-primary)] text-white"
                                    : "bg-white text-gray-600"
                            } ${link.url ? "" : "pointer-events-none opacity-40"}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            )}
        </AppLayout>
    );
}

function Field({ label, children }) {
    return (
        <label className="text-xs text-gray-600">
            <span className="mb-1 block uppercase tracking-wider">{label}</span>
            {children}
        </label>
    );
}
