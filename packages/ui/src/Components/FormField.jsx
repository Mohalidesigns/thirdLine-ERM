import { Children, cloneElement, isValidElement, useId } from 'react';
import InputError from './InputError.jsx';

const CONTROLS = new Set(['input', 'select', 'textarea']);

/**
 * Label, control, hint and error in the reference screen's order and spacing.
 *
 * The control is whatever the caller renders — a plain input, select or
 * textarea gets the product look from the field baseline in app.css, so
 * there is nothing to import for the common case:
 *
 *   <FormField label="Entity name" required error={errors.name}>
 *       <input value={data.name} onChange={...} placeholder="e.g., Treasury Operations" />
 *   </FormField>
 *
 * Accessibility is handled here, once, rather than at 150 call sites:
 *
 * - The label is associated with the control. Pass `htmlFor` and give the
 *   control that `id`, or pass nothing: a native control rendered as the
 *   direct child is given a generated id, and the label points at it.
 * - `required` draws the red asterisk (decorative, aria-hidden), adds a
 *   visually hidden "required" to the label text, and sets `aria-required`
 *   on a native direct-child control. It does NOT set the HTML `required`
 *   attribute: that would switch on the browser's own validation bubble and
 *   change how every form submits, and validation belongs to the server.
 * - Only a native control that is the DIRECT child is wired. A control nested
 *   in a wrapper div, a fragment, or a component must carry its own `id`
 *   (and the caller passes `htmlFor`) or an `aria-label`; when no target is
 *   known the heading is rendered as a <span>, never as a <label> that
 *   names nothing. Two bare native children would share the generated id —
 *   give them ids.
 * - `action` is an optional element rendered to the right of the label (an
 *   inline "+ Create" link, for instance).
 * - `hint` sits under the control in the muted size; `error` is an Inertia
 *   validation message and wins over the hint so the two never stack. The
 *   control is pointed at whichever is shown via `aria-describedby`, merged
 *   with any `aria-describedby` the caller already set.
 */
export default function FormField({ label, htmlFor, required = false, hint, error, action, className = '', children }) {
    const generated = useId();
    const describedBy = error ? `${generated}-error` : hint ? `${generated}-hint` : undefined;

    let controlId = htmlFor;
    const wired = Children.map(children, (child) => {
        if (!isValidElement(child) || typeof child.type !== 'string' || !CONTROLS.has(child.type)) {
            return child;
        }
        const id = child.props.id ?? htmlFor ?? `${generated}-control`;
        if (!controlId || child.props.id) controlId = id;
        const extra = { id };
        if (required && child.props['aria-required'] === undefined) extra['aria-required'] = 'true';
        if (describedBy) {
            const own = child.props['aria-describedby'];
            extra['aria-describedby'] = own ? `${own} ${describedBy}` : describedBy;
        }
        if (error && child.props['aria-invalid'] === undefined) extra['aria-invalid'] = 'true';
        return cloneElement(child, extra);
    });

    const Heading = controlId ? 'label' : 'span';

    return (
        <div className={className}>
            {(label || action) && (
                <div className={action ? 'mb-1 flex items-center justify-between' : undefined}>
                    {label && <Heading htmlFor={controlId} className={action ? 'form-label mb-0' : 'form-label'}>
                        {label}
                        {required && (
                            <>
                                <span className="form-required" aria-hidden="true">*</span>
                                <span className="sr-only"> (required)</span>
                            </>
                        )}
                    </Heading>}
                    {action}
                </div>
            )}
            {wired}
            {error ? (
                <InputError id={`${generated}-error`} message={error} />
            ) : hint ? (
                <span id={`${generated}-hint`} className="form-hint">{hint}</span>
            ) : null}
        </div>
    );
}
