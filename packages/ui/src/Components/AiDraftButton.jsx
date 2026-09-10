import axios from 'axios';
import { useState } from 'react';

/**
 * The AI builder card the Blade create forms carried as an Alpine component
 * with a raw fetch() — the Risk Statement Builder in
 * risk/register/create.blade.php (migration Phase 3.2) and the Treatment Plan
 * Builder in risk/treatments/create.blade.php (Phase 3.5).
 *
 * The two differ only in their copy, the route they post to and how a draft
 * maps onto form fields, so those are props: `endpoint`, `payload` (built at
 * click time, so it reads the CURRENT form values) and `onDraft`, which
 * receives the model's `data` object and decides what to set.
 *
 * Every one of these routes sits behind `permission:ai.use`. The Blade pages
 * drew the card unconditionally, so a tenant without it got a button that
 * failed; each page now renders this only when the server says the caller
 * holds it.
 */
export default function AiDraftButton({
    title,
    blurb,
    placeholder,
    endpoint,
    payload = () => ({}),
    onDraft,
}) {
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
            const { data } = await axios.post(endpoint, { scenario, ...payload() });

            if (!data.ok) {
                setError(data.error || 'The local LLM did not return a usable draft.');

                return;
            }

            onDraft(data.data);
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
                        <h3 className="text-sm font-semibold">{title}</h3>
                        <span className="text-[10px] px-2 py-0.5 rounded-full bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40">
                            Local LLM &middot; Granite
                        </span>
                    </div>
                    <p className="text-xs text-white/70 mt-0.5">{blurb}</p>
                </div>
            </div>

            <div className="flex gap-2">
                <input
                    type="text"
                    value={scenario}
                    onChange={(e) => setScenario(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            draft();
                        }
                    }}
                    placeholder={placeholder}
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
