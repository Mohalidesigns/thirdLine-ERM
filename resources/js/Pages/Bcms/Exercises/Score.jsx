import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import EvidenceCapture from '@/Components/Bcms/EvidenceCapture';

const ANCHORS = { 1: 'Failed', 2: 'Poor', 3: 'Adequate', 4: 'Good', 5: 'Excellent' };

/**
 * Observer scoring — one objective at a time, mobile-first (observer-scoring
 * spec). No `PageHeader` chrome: this is built to be usable one-handed,
 * outdoors, between other tasks.
 *
 * EACH SCORE POSTS INDEPENDENTLY. A single giant submit at the end is a
 * single point of total loss on a 100 kbps connection (spec §2) — every
 * "Save" below is its own small `POST`.
 */
export default function Score({
    occurrence = {}, closed = false, objectives = [], store_url: storeUrl,
    workspace_url: workspaceUrl, evidence_upload_url: evidenceUploadUrl,
}) {
    const firstUnscored = objectives.findIndex((o) => !o.saved);
    const [index, setIndex] = useState(firstUnscored >= 0 ? firstUnscored : 0);

    const scoredCount = objectives.filter((o) => o.saved).length;
    const current = objectives[index];

    return (
        <AppLayout title={`Score — ${occurrence.title ?? ''}`}>
            <Head title={`Score — ${occurrence.title ?? ''}`} />

            <div className="mx-auto max-w-lg p-4">
                <div className="mb-4 flex items-center justify-between">
                    <Link href={workspaceUrl} aria-label="Back to the exercise workspace" className="text-lg text-slate-500">×</Link>
                    <p className="truncate text-sm font-medium text-slate-700">{occurrence.title}</p>
                    <p className="text-xs text-slate-500">{scoredCount} of {objectives.length}</p>
                </div>

                {closed && (
                    <div className="mb-4 rounded border border-slate-300 bg-slate-50 p-3 text-sm text-slate-700">
                        Scoring is closed — this exercise is final.
                    </div>
                )}

                {objectives.length === 0 ? (
                    <div className="rounded border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
                        This exercise has no objectives to score. If that is wrong, ask the exercise owner to add
                        them to the definition.
                    </div>
                ) : current ? (
                    <ObjectiveCard
                        key={current.index}
                        objective={current}
                        storeUrl={storeUrl}
                        evidenceUploadUrl={evidenceUploadUrl}
                        readOnly={closed}
                    />
                ) : null}

                {objectives.length > 0 && (
                    <div className="mt-4 flex items-center justify-between">
                        <button type="button" disabled={index === 0}
                            onClick={() => setIndex((i) => Math.max(0, i - 1))}
                            className="rounded border border-slate-300 px-3 py-2 text-sm disabled:opacity-40">
                            Prev objective
                        </button>
                        <button type="button" disabled={index >= objectives.length - 1}
                            onClick={() => setIndex((i) => Math.min(objectives.length - 1, i + 1))}
                            className="rounded border border-slate-300 px-3 py-2 text-sm disabled:opacity-40">
                            Next objective
                        </button>
                    </div>
                )}

                {objectives.length > 0 && objectives.every((o) => o.saved) && (
                    <Link href={workspaceUrl} className="mt-6 block text-center text-sm text-blue-700 hover:underline">
                        Done — return to workspace
                    </Link>
                )}
            </div>
        </AppLayout>
    );
}

function ObjectiveCard({ objective, storeUrl, evidenceUploadUrl, readOnly }) {
    const form = useForm({
        objective_index: objective.index,
        score: objective.my_score ?? '',
        commentary: objective.my_commentary ?? '',
    });
    const [saved, setSaved] = useState(objective.saved);
    const [savedFlash, setSavedFlash] = useState(false);
    const groupRef = useRef(null);

    const lowScore = Number(form.data.score) === 1 || Number(form.data.score) === 2;

    const submit = (e) => {
        e.preventDefault();
        form.post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setSaved(true);
                setSavedFlash(true);
                setTimeout(() => setSavedFlash(false), 2500);
            },
        });
    };

    const selectScore = (n) => form.setData('score', n);

    const onKeyDown = (e) => {
        const order = [1, 2, 3, 4, 5];
        const currentIdx = order.indexOf(Number(form.data.score) || 1);
        let next = null;
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = order[Math.max(0, currentIdx - 1)];
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = order[Math.min(order.length - 1, currentIdx + 1)];
        if (next != null) {
            e.preventDefault();
            selectScore(next);
            groupRef.current?.querySelector(`[data-score="${next}"]`)?.focus();
        }
    };

    return (
        <fieldset className="rounded-lg border border-slate-200 bg-white p-4" disabled={readOnly}>
            <legend className="sr-only">Score this objective</legend>

            <h2 id={`objective-${objective.index}`} className="text-lg font-medium leading-snug text-slate-900">
                {objective.text}
            </h2>

            {objective.others_count > 0 && (
                <p className="mt-1 text-xs text-slate-500">Also scored by {objective.others_count} other evaluator(s)</p>
            )}

            <form onSubmit={submit} className="mt-4 space-y-4">
                <div role="radiogroup" aria-labelledby={`objective-${objective.index}`} ref={groupRef}
                    onKeyDown={onKeyDown} className="grid grid-cols-5 gap-2">
                    {[1, 2, 3, 4, 5].map((n) => {
                        const selected = Number(form.data.score) === n;
                        return (
                            <button
                                key={n}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                aria-label={`Score ${n}: ${ANCHORS[n]}`}
                                data-score={n}
                                tabIndex={selected || (!form.data.score && n === 1) ? 0 : -1}
                                onClick={() => selectScore(n)}
                                className={`flex min-h-[44px] flex-col items-center justify-center rounded border py-2 text-sm ${
                                    selected ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                <span className="font-semibold">{selected ? '✓ ' : ''}{n}</span>
                                <span className="text-[10px]">{ANCHORS[n]}</span>
                            </button>
                        );
                    })}
                </div>

                <div>
                    <label htmlFor={`commentary-${objective.index}`} className="block text-sm font-medium text-slate-700">
                        Commentary {lowScore && <span className="text-rose-600">(required for this score)</span>}
                    </label>
                    {lowScore && <span id={`commentary-hint-${objective.index}`} className="sr-only">Commentary is required for this score</span>}
                    <textarea
                        id={`commentary-${objective.index}`}
                        rows={4}
                        aria-required={lowScore}
                        aria-describedby={lowScore ? `commentary-hint-${objective.index}` : undefined}
                        className={`form-textarea mt-1 w-full ${lowScore ? 'border-red-300' : ''}`}
                        value={form.data.commentary}
                        onChange={(e) => form.setData('commentary', e.target.value)}
                    />
                    {form.errors.commentary && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.commentary}</p>}
                </div>

                {/* Same evidence gap resolution as the workspace screen (spec §9),
                    in its compact wording — but only once a score row exists to
                    attach a photo to. */}
                {objective.score_id != null && (
                    <EvidenceCapture uploadUrl={evidenceUploadUrl} ownerType="score" ownerKey={objective.score_id} compact />
                )}

                {!readOnly && (
                    <button type="submit" disabled={form.processing}
                        className="w-full rounded bg-slate-800 py-3 text-base font-medium text-white hover:bg-slate-700 disabled:opacity-60">
                        {form.processing ? 'Saving…' : saved ? 'Update this score' : 'Save this objective'}
                    </button>
                )}

                <p aria-live="polite" className="text-center text-sm text-emerald-700">
                    {savedFlash ? 'Saved.' : ''}
                </p>
            </form>
        </fieldset>
    );
}
