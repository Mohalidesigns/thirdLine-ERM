import { Head, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";

/**
 * §14's three open questions, in one screen.
 *
 * THE PAGE SAYS WHAT EACH DEFAULT MEANS. These are the questions the
 * implementation plan put to the bank and never got answered, and the build
 * has been running on its own choices for nine phases. A settings screen that
 * showed three toggles with no explanation would leave the same person guessing
 * — so each section states, in a sentence, what happens if it is left alone.
 *
 * APPETITE IS DISABLED WHILE A CYCLE IS OPEN and says why. It is not hidden: a
 * control that vanishes teaches people the feature does not exist, where a
 * disabled one with a reason teaches them when it is available.
 */
export default function Index({
    methodology,
    bands = [],
    categories = [],
    category_appetites: categoryAppetites = [],
    categories_without_appetite: categoriesWithoutAppetite = [],
    treatment_override_approval_required: overrideApprovalRequired,
    retention,
    retention_preview: retentionPreview,
    open_cycles: openCycles = [],
}) {
    const { flash } = usePage().props;
    const locked = methodology.is_locked;

    const form = useForm({
        appetite_mode: methodology.appetite_mode,
        appetite_ceiling_level: methodology.appetite_ceiling_level,
        category_appetites: categoryAppetites,
        treatment_override_approval_required: overrideApprovalRequired,
        retention: {
            export_files_days: retention.export_files_days ?? "",
            import_files_days: retention.import_files_days ?? "",
            closed_cycle_years: retention.closed_cycle_years ?? "",
        },
    });

    const unusedCategories = categories.filter(
        (category) => !form.data.category_appetites.some((row) => row.risk_category === category),
    );

    const addCategory = () => {
        if (unusedCategories.length === 0) return;

        form.setData("category_appetites", [
            ...form.data.category_appetites,
            {
                risk_category: unusedCategories[0],
                ceiling_level: methodology.appetite_ceiling_level,
                note: "",
            },
        ]);
    };

    const updateCategory = (index, field, value) => {
        form.setData(
            "category_appetites",
            form.data.category_appetites.map((row, i) => (i === index ? { ...row, [field]: value } : row)),
        );
    };

    const removeCategory = (index) => {
        form.setData(
            "category_appetites",
            form.data.category_appetites.filter((_, i) => i !== index),
        );
    };

    const submit = (event) => {
        event.preventDefault();
        form.put(route("rcsa.settings.update"), { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="RCSA settings" />

            <PageHeader
                title="RCSA settings"
                subtitle="How appetite is expressed, whether an override needs approving, and what is kept."
            />

            {flash?.success && (
                <div className="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}

            <form onSubmit={submit} className="space-y-6">
                {/* ---------------------------------------------------------
                    Q4 — appetite
                --------------------------------------------------------- */}
                <section className="rounded border border-gray-200 bg-white">
                    <header className="border-b border-gray-200 px-6 py-4">
                        <h2 className="text-base font-semibold text-gray-900">Risk appetite</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            The highest residual band still inside appetite. Everything above it is above
                            appetite and needs an action plan before an assessment can be filed.
                        </p>
                    </header>

                    {locked && (
                        <div className="border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-900">
                            <strong className="font-semibold">Appetite is frozen.</strong>{" "}
                            {methodology.locked_reason}
                            {openCycles.length > 0 && (
                                <span> Open now: {openCycles.map((cycle) => cycle.name).join(", ")}.</span>
                            )}
                        </div>
                    )}

                    <div className="space-y-5 px-6 py-5">
                        <fieldset disabled={locked} className={locked ? "opacity-60" : undefined}>
                            <legend className="text-sm font-medium text-gray-900">How it is expressed</legend>

                            <label className="mt-3 flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="appetite_mode"
                                    value="single"
                                    checked={form.data.appetite_mode === "single"}
                                    onChange={() => form.setData("appetite_mode", "single")}
                                    className="mt-1"
                                />
                                <span className="text-sm">
                                    <span className="font-medium text-gray-900">One ceiling for every risk</span>
                                    <span className="block text-gray-600">
                                        What the module has done since it was built. Leave it here unless the
                                        board has set appetite by category.
                                    </span>
                                </span>
                            </label>

                            <label className="mt-3 flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="appetite_mode"
                                    value="per_category"
                                    checked={form.data.appetite_mode === "per_category"}
                                    onChange={() => form.setData("appetite_mode", "per_category")}
                                    className="mt-1"
                                />
                                <span className="text-sm">
                                    <span className="font-medium text-gray-900">One ceiling per risk category</span>
                                    <span className="block text-gray-600">
                                        A category with no ceiling of its own falls back to the house ceiling
                                        below — never to no ceiling at all.
                                    </span>
                                </span>
                            </label>

                            {form.errors.appetite_mode && (
                                <p className="mt-2 text-sm text-red-700">{form.errors.appetite_mode}</p>
                            )}
                        </fieldset>

                        <div>
                            <label htmlFor="house-ceiling" className="block text-sm font-medium text-gray-900">
                                House ceiling
                            </label>
                            <select
                                id="house-ceiling"
                                disabled={locked}
                                value={form.data.appetite_ceiling_level}
                                onChange={(e) => form.setData("appetite_ceiling_level", e.target.value)}
                                className="filter-select mt-1 w-64"
                            >
                                {bands.map((band) => (
                                    <option key={band.level} value={band.level}>
                                        {band.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.appetite_ceiling_level && (
                                <p className="mt-2 text-sm text-red-700">{form.errors.appetite_ceiling_level}</p>
                            )}
                        </div>

                        {form.data.appetite_mode === "per_category" && (
                            <div>
                                <h3 className="text-sm font-medium text-gray-900">Ceilings by category</h3>

                                {form.data.category_appetites.length === 0 && (
                                    <p className="mt-2 text-sm text-gray-600">
                                        None set. Add at least one, or every category falls back to the house
                                        ceiling and this mode does nothing.
                                    </p>
                                )}

                                <div className="mt-3 space-y-3">
                                    {form.data.category_appetites.map((row, index) => (
                                        <div key={index} className="flex flex-wrap items-start gap-3">
                                            <select
                                                aria-label="Risk category"
                                                disabled={locked}
                                                value={row.risk_category}
                                                onChange={(e) => updateCategory(index, "risk_category", e.target.value)}
                                                className="filter-select w-56"
                                            >
                                                {categories.map((category) => (
                                                    <option key={category} value={category}>
                                                        {category}
                                                    </option>
                                                ))}
                                            </select>

                                            <select
                                                aria-label="Ceiling"
                                                disabled={locked}
                                                value={row.ceiling_level}
                                                onChange={(e) => updateCategory(index, "ceiling_level", e.target.value)}
                                                className="filter-select w-48"
                                            >
                                                {bands.map((band) => (
                                                    <option key={band.level} value={band.level}>
                                                        {band.label}
                                                    </option>
                                                ))}
                                            </select>

                                            <input
                                                type="text"
                                                aria-label="Why this category differs"
                                                disabled={locked}
                                                value={row.note ?? ""}
                                                onChange={(e) => updateCategory(index, "note", e.target.value)}
                                                placeholder="Why this category differs (optional)"
                                                className="filter-input min-w-[16rem] flex-1"
                                            />

                                            <button
                                                type="button"
                                                disabled={locked}
                                                onClick={() => removeCategory(index)}
                                                className="px-2 py-1 text-sm text-gray-500 hover:text-red-700"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    ))}
                                </div>

                                {form.errors.category_appetites && (
                                    <p className="mt-2 text-sm text-red-700">{form.errors.category_appetites}</p>
                                )}

                                <button
                                    type="button"
                                    disabled={locked || unusedCategories.length === 0}
                                    onClick={addCategory}
                                    className="mt-3 text-sm font-medium text-blue-700 hover:underline disabled:text-gray-400 disabled:no-underline"
                                >
                                    Add a category ceiling
                                </button>

                                {categoriesWithoutAppetite.length > 0 && (
                                    <p className="mt-3 text-sm text-gray-600">
                                        Currently on the house ceiling:{" "}
                                        {categoriesWithoutAppetite.join(", ")}.
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                </section>

                {/* ---------------------------------------------------------
                    Q5 — treatment override approval
                --------------------------------------------------------- */}
                <section className="rounded border border-gray-200 bg-white">
                    <header className="border-b border-gray-200 px-6 py-4">
                        <h2 className="text-base font-semibold text-gray-900">Treatment overrides</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            An assessor can depart from the treatment the methodology calculated. A written
                            justification is always required; a second signature is not, unless you ask for one.
                        </p>
                    </header>

                    <div className="px-6 py-5">
                        <label className="flex items-start gap-3">
                            <input
                                type="checkbox"
                                checked={form.data.treatment_override_approval_required}
                                onChange={(e) =>
                                    form.setData("treatment_override_approval_required", e.target.checked)
                                }
                                className="mt-1"
                            />
                            <span className="text-sm">
                                <span className="font-medium text-gray-900">
                                    An override must be approved before it takes effect
                                </span>
                                <span className="block text-gray-600">
                                    Until it is approved, the calculated treatment is what stands on the export,
                                    the dashboard and the board pack. An assessment cannot be validated while an
                                    override is still undecided. Approvers need the &ldquo;approve treatment
                                    override&rdquo; permission, and nobody can approve their own.
                                </span>
                            </span>
                        </label>
                    </div>
                </section>

                {/* ---------------------------------------------------------
                    Q10 — retention
                --------------------------------------------------------- */}
                <section className="rounded border border-gray-200 bg-white">
                    <header className="border-b border-gray-200 px-6 py-4">
                        <h2 className="text-base font-semibold text-gray-900">Retention</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Leave a field blank to keep for ever, which is the default and what happens today.
                            Nothing is deleted until you set a period and someone runs the sweep.
                        </p>
                    </header>

                    <div className="space-y-5 px-6 py-5">
                        <RetentionField
                            id="export-files"
                            label="Generated export files"
                            unit="days"
                            help="The workbook is deleted; the export log row is kept for ever, so who took what is still answerable."
                            value={form.data.retention.export_files_days}
                            error={form.errors["retention.export_files_days"]}
                            onChange={(value) =>
                                form.setData("retention", { ...form.data.retention, export_files_days: value })
                            }
                        />

                        <RetentionField
                            id="import-files"
                            label="Uploaded import workbooks"
                            unit="days"
                            help="Only for imports already published or discarded. The record of the import itself is kept."
                            value={form.data.retention.import_files_days}
                            error={form.errors["retention.import_files_days"]}
                            onChange={(value) =>
                                form.setData("retention", { ...form.data.retention, import_files_days: value })
                            }
                        />

                        <RetentionField
                            id="closed-cycles"
                            label="Closed cycles"
                            unit="years"
                            help="Reported only. Nothing here deletes a closed cycle — it is the bank's regulatory record, and removing one is a decision a person makes."
                            value={form.data.retention.closed_cycle_years}
                            error={form.errors["retention.closed_cycle_years"]}
                            onChange={(value) =>
                                form.setData("retention", { ...form.data.retention, closed_cycle_years: value })
                            }
                        />

                        {retentionPreview && (
                            <div className="rounded border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                                <p className="font-medium text-gray-900">What a sweep would select today</p>
                                <ul className="mt-2 space-y-1">
                                    <li>{retentionPreview.export_files.length} export file(s) past their period.</li>
                                    <li>{retentionPreview.import_files.length} import workbook(s) past theirs.</li>
                                    <li>
                                        {retentionPreview.closed_cycles.length} closed cycle(s) past the period —
                                        listed, never deleted.
                                    </li>
                                    {retentionPreview.held.length > 0 && (
                                        <li className="text-amber-800">
                                            {retentionPreview.held.length} cycle(s) under legal hold, excluded from
                                            every sweep.
                                        </li>
                                    )}
                                </ul>
                            </div>
                        )}

                        <p className="text-sm text-gray-600">
                            The audit trail is never swept. It is hash-chained, so removing an entry would break
                            the chain rather than shorten it, and the module would report tampering that never
                            happened.
                        </p>
                    </div>
                </section>

                <div className="flex items-center gap-3">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60"
                    >
                        {form.processing ? "Saving…" : "Save settings"}
                    </button>

                    {form.isDirty && <span className="text-sm text-gray-600">Unsaved changes.</span>}
                </div>
            </form>
        </AppLayout>
    );
}

function RetentionField({ id, label, unit, help, value, error, onChange }) {
    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-gray-900">
                {label}
            </label>

            <div className="mt-1 flex items-center gap-2">
                <input
                    id={id}
                    type="number"
                    min="1"
                    value={value}
                    onChange={(e) => onChange(e.target.value === "" ? "" : Number(e.target.value))}
                    placeholder="Keep for ever"
                    className="filter-input w-40"
                />
                <span className="text-sm text-gray-600">{unit}</span>
            </div>

            <p className="mt-1 text-sm text-gray-600">{help}</p>

            {error && <p className="mt-1 text-sm text-red-700">{error}</p>}
        </div>
    );
}
