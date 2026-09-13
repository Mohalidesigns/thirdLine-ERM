import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

/**
 * The running totals on the assessment form (migration Phase 3.3, Decision 5b).
 *
 * These used to be an Alpine component that reimplemented impact aggregation,
 * effectiveness weighting and the axis split in JavaScript. That mirror was
 * free to drift from the server's arithmetic, and it could not evaluate a
 * tenant's configured residual formula at all — it said so in a comment and
 * fell back to the platform default, so an organisation on a custom formula
 * watched one number while typing and got a different one on save.
 *
 * There is no arithmetic here now. The chain is sent to the server, which
 * scores it with the same code that saves it, on a 300 ms debounce so a
 * keystroke does not become a request. Requests are sequenced: a response that
 * arrives after a newer one was issued is dropped rather than painting a stale
 * number over a fresh one.
 */
export default function useAssessmentPreview(riskId, chain, { debounce = 300 } = {}) {
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    // Serialised so the effect fires on a real change rather than on every
    // re-render handing it a new object with the same contents.
    const payload = JSON.stringify(chain);
    const issued = useRef(0);

    useEffect(() => {
        if (!riskId) return undefined;

        const timer = setTimeout(async () => {
            const sequence = ++issued.current;

            setLoading(true);

            try {
                const { data } = await axios.post(route('risk.assessments.preview'), {
                    risk_id: riskId,
                    ...JSON.parse(payload),
                });

                if (sequence === issued.current) {
                    setPreview(data);
                    setError(null);
                }
            } catch (e) {
                if (sequence === issued.current) {
                    setError(e.response?.status === 422 ? null : 'Could not score the chain just now.');
                }
            } finally {
                if (sequence === issued.current) setLoading(false);
            }
        }, debounce);

        return () => clearTimeout(timer);
    }, [riskId, payload, debounce]);

    return { preview, loading, error };
}
