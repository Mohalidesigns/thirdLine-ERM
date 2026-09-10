/**
 * Guided mode — one risk at a time, for the occasional assessor (§8.2).
 *
 * The difference from grid mode is not layout, it is CONTEXT. The impact
 * criteria and the control-effectiveness descriptions travel with the page for
 * exactly this screen, so somebody rating three risks twice a year is told what
 * "High" and "Mostly Achieved" mean at the moment they choose them, rather than
 * being sent to a spreadsheet nobody opens.
 *
 * It writes through the same `onChange` the grid does, so switching modes keeps
 * everything.
 */
export default function GuidedStep({
    line,
    index,
    total,
    methodology,
    impactCriteria = {},
    controlGuidance = [],
    editable,
    onChange,
    onMove,
}) {
    if (!line) {
        return (
            <div className="card p-8 text-center text-sm text-gray-500">
                Nothing to assess here.
            </div>
        );
    }

    const criteria = impactCriteria?.[line.inherent_impact] ?? null;
    const control = controlGuidance.find((item) => item.label === line.control_effectiveness);

    return (
        <div className="card p-6">
            {/* Where am I */}
            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-gray-500">
                    Risk {index + 1} of {total}
                </p>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => onMove(-1)}
                        disabled={index === 0}
                        className="btn-secondary text-sm disabled:opacity-40"
                    >
                        ← Previous
                    </button>
                    <button
                        type="button"
                        onClick={() => onMove(1)}
                        disabled={index >= total - 1}
                        className="btn-primary text-sm disabled:opacity-40"
                    >
                        Next →
                    </button>
                </div>
            </div>

            {/* The risk as the universe stated it */}
            <div className="rounded-lg bg-gray-50 p-4">
                <span className="font-mono text-xs text-gray-500">{line.risk_no}</span>
                <p className="mt-1 text-base font-medium text-gray-800">{line.potential_risk}</p>

                {line.risk_driver && (
                    <p className="mt-2 text-sm text-gray-600">
                        <span className="font-medium">Driver:</span> {line.risk_driver}
                    </p>
                )}

                <div className="mt-3 flex flex-wrap gap-3 text-xs text-gray-500">
                    {line.process_name && <span>{line.process_name}</span>}
                    {line.sub_process_name && <span>· {line.sub_process_name}</span>}
                    {line.risk_category && <span>· {line.risk_category}</span>}
                    {(line.system_names ?? []).length > 0 && <span>· {line.system_names.join(', ')}</span>}
                </div>
            </div>

            {/* Step 4 — inherent risk */}
            <Section number={4} title="How likely is this, and how bad would it be?">
                <div className="grid gap-4 md:grid-cols-2">
                    <Field label="Likelihood (without controls)">
                        <select
                            className="filter-select w-full"
                            disabled={!editable}
                            value={line.inherent_likelihood ?? ''}
                            onChange={(e) =>
                                onChange({ inherent_likelihood: e.target.value === '' ? null : Number(e.target.value) })
                            }
                        >
                            <option value="">Choose…</option>
                            {methodology.likelihood.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.value} · {item.label}
                                </option>
                            ))}
                        </select>
                        <Hint>
                            {methodology.likelihood.find((i) => i.value === line.inherent_likelihood)?.description}
                        </Hint>
                    </Field>

                    <Field label="Impact (without controls)">
                        <select
                            className="filter-select w-full"
                            disabled={!editable}
                            value={line.inherent_impact ?? ''}
                            onChange={(e) =>
                                onChange({ inherent_impact: e.target.value === '' ? null : Number(e.target.value) })
                            }
                        >
                            <option value="">Choose…</option>
                            {methodology.impact.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.value} · {item.label}
                                </option>
                            ))}
                        </select>
                        <Hint>{methodology.impact.find((i) => i.value === line.inherent_impact)?.percentBand}</Hint>
                    </Field>
                </div>

                {/*
                  * The impact criteria, expanded. THE HIGHEST APPLICABLE
                  * DIMENSION DRIVES THE RATING, and without these on screen a
                  * five-point impact scale collapses into a naira scale and
                  * every non-financial risk is under-rated.
                  */}
                {criteria && (
                    <details className="mt-3 rounded-md border border-gray-200 p-3" open>
                        <summary className="cursor-pointer text-sm font-medium text-gray-700">
                            What this impact rating means
                        </summary>
                        <dl className="mt-2 grid gap-2 md:grid-cols-2">
                            {Object.entries(criteria).map(([dimension, text]) => (
                                <div key={dimension}>
                                    <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                        {dimension.replace(/_/g, ' ')}
                                    </dt>
                                    <dd className="text-sm text-gray-700">{text}</dd>
                                </div>
                            ))}
                        </dl>
                        <p className="mt-2 text-xs text-gray-500">
                            Rate against the <strong>highest</strong> dimension that applies, not only the financial one.
                        </p>
                    </details>
                )}

                <Computed label="Inherent risk" score={line.inherent_score} level={line.inherent_level} />
            </Section>

            {/* Step 5 — control effectiveness */}
            <Section number={5} title="How well do the existing controls work?">
                {line.existing_control ? (
                    <div className="mb-3 whitespace-pre-line rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                        {line.existing_control}
                    </div>
                ) : (
                    <p className="mb-3 text-sm text-amber-700">
                        No control is recorded against this risk. Rate what is actually in place — if nothing is, that
                        is "Not Achieved".
                    </p>
                )}

                <select
                    className="filter-select w-full md:w-1/2"
                    disabled={!editable}
                    value={line.control_effectiveness ?? ''}
                    onChange={(e) => onChange({ control_effectiveness: e.target.value || null })}
                >
                    <option value="">Choose…</option>
                    {controlGuidance.map((item) => (
                        <option key={item.label} value={item.label}>
                            {item.label} ({item.band})
                        </option>
                    ))}
                </select>

                {control && (
                    <p className="mt-2 rounded-md border border-gray-200 p-3 text-sm text-gray-700">
                        {control.description}
                    </p>
                )}
            </Section>

            {/* Step 6 — residual */}
            <Section number={6} title="What is left after the controls?">
                <Computed label="Residual risk" score={line.residual_score} level={line.residual_level} />

                {line.appetite_status && (
                    <p
                        className={`mt-3 rounded-md p-3 text-sm ${
                            line.appetite_status.startsWith('Above')
                                ? 'bg-red-50 text-red-800'
                                : 'bg-green-50 text-green-800'
                        }`}
                    >
                        {line.appetite_status}
                    </p>
                )}

                {line.moved_materially && (
                    <div className="mt-3">
                        <Field label="This moved materially since the last cycle — why?">
                            <textarea
                                rows={2}
                                className="filter-input w-full"
                                disabled={!editable}
                                defaultValue={line.assessment_rationale ?? ''}
                                onBlur={(e) => onChange({ assessment_rationale: e.target.value })}
                            />
                        </Field>
                        {line.prior && (
                            <p className="mt-1 text-xs text-gray-500">
                                Last cycle: inherent {line.prior.inherent_score}, control{' '}
                                {line.prior.control_effectiveness ?? '—'}.
                            </p>
                        )}
                    </div>
                )}
            </Section>
        </div>
    );
}

function Section({ number, title, children }) {
    return (
        <section className="mt-6 border-t border-gray-100 pt-5">
            <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-800">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-[var(--color-primary)] text-xs text-white">
                    {number}
                </span>
                {title}
            </h3>
            {children}
        </section>
    );
}

function Field({ label, children }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">{label}</label>
            {children}
        </div>
    );
}

function Hint({ children }) {
    if (!children) return null;

    return <p className="mt-1 text-xs text-gray-500">{children}</p>;
}

function Computed({ label, score, level }) {
    return (
        <div className="mt-4 flex items-center gap-3">
            <span className="text-sm text-gray-600">{label}:</span>
            {score === null || score === undefined ? (
                <span className="text-sm text-gray-400">not yet — answer the questions above</span>
            ) : (
                <span className="text-lg font-semibold text-gray-800">
                    {Number(score).toFixed(Number.isInteger(Number(score)) ? 0 : 2)}{' '}
                    <span className="text-sm font-normal capitalize text-gray-500">
                        {(level ?? '').replace(/_/g, ' ')}
                    </span>
                </span>
            )}
        </div>
    );
}
