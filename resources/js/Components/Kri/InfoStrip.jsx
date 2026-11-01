import Glyph from './Glyph';

const TONES = {
    info: 'bg-blue-50 border-blue-100 text-gray-700',
    warning: 'bg-amber-50 border-amber-200 text-amber-900',
    error: 'bg-red-50 border-red-200 text-red-800',
    success: 'bg-green-50 border-green-200 text-green-900',
    orange: 'bg-orange-50 border-orange-200 text-orange-900',
};

const GLYPHS = { info: 'info', warning: 'warning', error: 'error', success: 'check_circle', orange: 'lock_open' };

/**
 * A banner. `role` is deliberate: "note" for page content, "status" for a
 * result the user caused, "alert" only for something that has just failed after
 * an action - never on page load (00-common section 5).
 */
export default function InfoStrip({ tone = 'info', role = 'note', children, className = '', ...rest }) {
    return (
        <div role={role} className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm ${TONES[tone] ?? TONES.info} ${className}`} {...rest}>
            <Glyph name={GLYPHS[tone] ?? 'info'} className="mt-0.5" />
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}
