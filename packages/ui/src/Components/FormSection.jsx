/**
 * One titled card on a form page — the "Basic Information" / "Classification
 * & Risk Profile" blocks of the ThirdLine reference screen.
 *
 * A form page is a `.form-page` stack of these, each a `.card` with a
 * `.card-header` carrying the title (and an optional right-hand badge such as
 * "Optional") over a `.card-body`. The body is `space-y-4` so the grids inside
 * it fall at the same rhythm on every screen.
 */
export default function FormSection({ title, badge, description, actions, children, className = '', bodyClassName = '' }) {
    return (
        <section className={`card ${className}`}>
            {(title || badge || actions) && (
                <div className="card-header flex items-center justify-between gap-4">
                    <div className="min-w-0">
                        {title && <h3 className="form-section-title">{title}</h3>}
                        {description && <p className="mt-0.5 text-xs text-gray-500">{description}</p>}
                    </div>
                    {badge && <span className="form-section-badge shrink-0">{badge}</span>}
                    {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
                </div>
            )}
            <div className={`card-body space-y-4 ${bodyClassName}`}>{children}</div>
        </section>
    );
}
