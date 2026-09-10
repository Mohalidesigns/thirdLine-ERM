import { Dialog, DialogPanel } from '@headlessui/react';

/**
 * ThirdLine's Modal, minus the Headless UI <Transition>: on this build
 * (Headless UI 2.2 + Tailwind 4) the transition never reaches its end state
 * and the panel stays at opacity 0 — the same defect Phase 1 fixed in
 * Dropdown.jsx. The dialog now mounts and unmounts without animation.
 */
export default function Modal({
    children,
    show = false,
    maxWidth = '2xl',
    closeable = true,
    onClose = () => {},
}) {
    const close = () => {
        if (closeable) {
            onClose();
        }
    };

    const maxWidthClass = {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        // Wider sizes for form-heavy dialogs, which otherwise stack every
        // field into a column taller than the screen.
        '3xl': 'sm:max-w-3xl',
        '4xl': 'sm:max-w-4xl',
    }[maxWidth];

    if (!show) return null;

    return (
        <Dialog
            as="div"
            id="modal"
            open={show}
            className="fixed inset-0 z-50 flex transform justify-center overflow-y-auto px-4 py-6 sm:px-0"
            onClose={close}
        >
            <div className="absolute inset-0 bg-gray-500/75" aria-hidden="true" />

            {/*
              * `my-auto` — not `items-center` on the container — is what
              * centres the panel while staying scrollable in both directions
              * when the dialog is taller than the viewport. The height cap
              * keeps the panel inside the viewport and lets it scroll its
              * own content, so a long form never pushes its buttons off-screen.
              */}
            <DialogPanel
                className={`relative my-auto max-h-[calc(100vh-3rem)] w-full overflow-y-auto overscroll-contain rounded-lg bg-white shadow-xl sm:mx-auto ${maxWidthClass}`}
            >
                {children}
            </DialogPanel>
        </Dialog>
    );
}
