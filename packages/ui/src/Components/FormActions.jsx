import { Link } from '@inertiajs/react';

/**
 * The bottom-right Cancel / submit pair of every form page.
 *
 * `cancelHref` renders an Inertia link; `onCancel` a button. The submit label
 * changes to `processingLabel` while the form is in flight so the button
 * itself reports the state — the reference screen does not use a spinner.
 */
export default function FormActions({
    submitLabel = 'Save',
    processingLabel = 'Saving...',
    processing = false,
    cancelHref,
    onCancel,
    cancelLabel = 'Cancel',
    disabled = false,
    children,
    className = '',
}) {
    return (
        <div className={`form-actions ${className}`}>
            {children}
            {cancelHref && (
                <Link href={cancelHref} className="btn-secondary text-sm">{cancelLabel}</Link>
            )}
            {!cancelHref && onCancel && (
                <button type="button" onClick={onCancel} className="btn-secondary text-sm">{cancelLabel}</button>
            )}
            <button type="submit" className="btn-primary text-sm" disabled={processing || disabled}>
                {processing ? processingLabel : submitLabel}
            </button>
        </div>
    );
}
