import { createContext } from 'react';

/**
 * The white band under the top bar is a portal target. A PageHeader claims it
 * through this context; the first one to claim wins, later ones render inline.
 *
 * Value is `null` where no band is available: no layout above, the page passed
 * its own `header`, or inside a dialog (see PageHeaderSlotBoundary).
 * Otherwise `{ target, claim(id), release(id) }`.
 */
export const PageHeaderSlotContext = createContext(null);

/**
 * Wrap anything that renders through a React portal (Modal, ConfirmDialog, a
 * future drawer). Context flows through portals, so without this a PageHeader
 * inside a dialog would hijack the page's band.
 */
export function PageHeaderSlotBoundary({ children }) {
    return <PageHeaderSlotContext.Provider value={null}>{children}</PageHeaderSlotContext.Provider>;
}
