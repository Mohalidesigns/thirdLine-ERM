import axios from 'axios';
import { useState } from 'react';

/**
 * The AI Risk Statement Builder from risk/register/create.blade.php
 * (migration Phase 3.2), which was an Alpine component with a raw fetch().
 *
 * The route it posts to sits behind `feature:ai_intelligence` and
 * `permission:ai.view`. The Blade page drew this card unconditionally, so a
 * tenant without the feature got a button that 404'd; the page now renders it
 * only when the server says both hold (`canDraftWithAi`).
 */
export default function AiDraftButton({ categoryName, businessUnitName, onDraft }) {
    const [scenario, setScenario] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [elapsed, setElapsed] = useState(null);

    const draft = async () => {
        if (scenario.trim().length < 3) return;

        setLoading(true);
        setError(null);
        setElapsed(null);

        const started = performance.now();

        try {
            const { data } = await axios.post(route('risk.ai.tools.risk-statement'), {
                scenario,
                category: categoryName || null,
                business_unit: businessUnitName || null,
            });

            if (!data.ok) {
                setError(data.error || 'The local LLM did not return a usable draft.');

                return;
            }

            const draftData = data.data;

            onDraft({
                title: draftData.title,
                description:
                    `Cause: ${draftData.cause}\nEvent: ${draftData.event}\nConsequence: ${draftData.consequence}` +
                    `\n\n${draftData.description}`,
            });
            setElapsed(Math.round(performance.now() - started));
        } catch (e) {
            setError(`Network error: ${e.message}`);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="mb-6 bg-gradient-to-br from-[#1A365D] to-[#2c4a7a] rounded-xl p-5 text-white shadow-lg">
            <div className="flex items-start gap-3 mb-3">
                <div className="w-9 h-9 rounded-lg bg-[#D4AF37]/20 border border-[#D4AF37]/40 flex items-center justify-center flex-shrink-0">
                    <span className="material-symbols-outlined text-[#D4AF37]" style={{ fontSize: 20 }}>auto_awesome</span>
                </div>
                <div className="flex-1">
                    <div className="flex items-center gap-2">
                        <h3 className="text-sm font-semibold">AI Risk Statement Builder</h3>
                        <span className="text-[10px] px-2 py-0.5 rounded-full bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40">
                            Local LLM &middot; Granite
                        </span>
                    </div>
                    <p className="text-xs text-white/70 mt-0.5">
                        Describe the scenario in plain English — the model drafts a board-ready Cause &rarr; Event &rarr;
                        Consequence statement and prefills the form.
                    </p>
                </div>
            </div>

            <div className="flex gap-2">
                <input
                    type="text"
                    value={scenario}
                    onChange={(e) => setScenario(e.target.value)}
                    placeholder="e.g. core banking outage during month-end settlement"
                    className="flex-1 px-3 py-2 rounded-lg text-sm bg-white/10 border border-white/20 text-white placeholder-white/40 focus:outline-none focus:border-[#D4AF37]"
                />
                <button
                    type="button"
                    onClick={draft}
                    disabled={loading || scenario.trim().length < 3}
                    className="px-4 py-2 rounded-lg text-sm font-medium bg-[#D4AF37] text-[#1A365D] disabled:opacity-50"
                >
                    {loading ? 'Drafting…' : 'Draft'}
                </button>
            </div>

            {error && <p className="text-xs text-red-200 mt-2">{error}</p>}
            {elapsed !== null && <p className="text-xs text-white/60 mt-2">Drafted in {elapsed} ms.</p>}
        </div>
    );
}
