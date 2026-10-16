import { Link } from '@inertiajs/react';
import { useContext, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { PageHeaderSlotContext } from '../Layouts/PageHeaderSlot';
import Icon from './Icon';

function Inner({ titleId, title, subtitle, actions, breadcrumbs }) {
    return (
        <div className="page-header">
            <div>
                {breadcrumbs && breadcrumbs.length > 0 && (
                    <nav aria-label="Breadcrumb" className="flex items-center gap-1.5 text-sm text-gray-500 mb-1">
                        {breadcrumbs.map((crumb, idx) => (
                            <span key={idx} className="flex items-center gap-1.5">
                                {idx > 0 && <Icon name="chevron_right" className="w-3.5 h-3.5 text-gray-400" strokeWidth={2} />}
                                {crumb.href ? (
                                    <Link href={crumb.href} className="hover:text-[var(--color-primary)] transition-colors motion-reduce:transition-none">
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span className="text-gray-700 font-medium">{crumb.label}</span>
                                )}
                            </span>
                        ))}
                    </nav>
                )}
                <h2 id={titleId} className="page-title">{title}</h2>
                {subtitle && <p className="text-sm text-gray-500 mt-1">{subtitle}</p>}
            </div>
            {actions && <div className="flex gap-2 flex-shrink-0">{actions}</div>}
        </div>
    );
}

/**
 * A page's title block. Under AuthenticatedLayout it renders into the white
 * band beneath the top bar (first PageHeader wins); anywhere else - a dialog,
 * a second header, a page that passes `header` - it renders inline.
 */
export default function PageHeader(props) {
    const slot = useContext(PageHeaderSlotContext);
    const id = useId();
    const eligible = Boolean(slot?.target);
    // undefined = undecided (first render), true = portaled, false = inline
    const [portal, setPortal] = useState(undefined);
    const slotRef = useRef(slot);
    const holds = useRef(false);
    slotRef.current = slot;

    const tryClaim = () => {
        const won = slotRef.current.claim(id, `${id}-title`);
        holds.current = won;
        setPortal(won);
        return won;
    };

    // First decision, once the band target exists.
    useLayoutEffect(() => {
        if (!slot?.target) {
            setPortal(false);
            return;
        }
        if (!tryClaim() && import.meta.env?.DEV) console.warn('[PageHeader] the page-header band is already claimed; rendering inline');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slot?.target]);

    // An inline header takes the band when the one holding it unmounts
    // (`version` bumps on every release).
    useLayoutEffect(() => {
        if (portal === false && slot?.target && !holds.current) tryClaim();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slot?.version]);

    // Give the band back on unmount.
    useLayoutEffect(
        () => () => {
            if (holds.current) slotRef.current?.release(id);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const inner = <Inner titleId={`${id}-title`} {...props} />;

    if (eligible && portal === undefined) return null;
    return portal && slot?.target ? createPortal(inner, slot.target) : inner;
}
