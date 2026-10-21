import { useCallback, useState } from "react";
import ConfirmDialog from "@thirdline/ui/Components/ConfirmDialog";

/**
 * The in-page confirmation, in place of window.confirm().
 *
 * A native confirm cannot be styled, cannot say which button is the
 * consequential one, blocks the whole tab, and is suppressed outright by some
 * embedded browsers — where it returns false and the button silently does
 * nothing. The RCSA screens ask through the shared ConfirmDialog the workspace
 * already uses for submit, so every "are you sure" in the module looks and
 * behaves the same way.
 *
 *     const [confirm, confirmDialog] = useConfirm();
 *     confirm({ title, message, confirmLabel, variant, onConfirm });
 *     ...
 *     {confirmDialog}
 *
 * `onConfirm` runs after the dialog has closed.
 */
export default function useConfirm() {
    const [pending, setPending] = useState(null);

    const confirm = useCallback((options) => setPending(options), []);

    const dialog = (
        <ConfirmDialog
            show={pending !== null}
            title={pending?.title}
            message={pending?.message}
            confirmLabel={pending?.confirmLabel ?? "Confirm"}
            cancelLabel={pending?.cancelLabel ?? "Cancel"}
            variant={pending?.variant ?? "primary"}
            onConfirm={() => {
                const run = pending?.onConfirm;

                setPending(null);
                run?.();
            }}
            onCancel={() => setPending(null)}
        />
    );

    return [confirm, dialog];
}
