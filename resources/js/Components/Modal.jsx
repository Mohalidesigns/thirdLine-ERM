import {
    Dialog,
    DialogPanel,
    Transition,
    TransitionChild,
} from '@headlessui/react';

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
        // Wider sizes for form-heavy dialogs (e.g. the test procedure editor),
        // which otherwise stack every field into a column taller than the screen.
        '3xl': 'sm:max-w-3xl',
        '4xl': 'sm:max-w-4xl',
    }[maxWidth];

    return (
        <Transition show={show} leave="duration-200">
            <Dialog
                as="div"
                id="modal"
                className="fixed inset-0 z-50 flex transform justify-center overflow-y-auto px-4 py-6 transition-all sm:px-0"
                onClose={close}
            >
                <TransitionChild
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="absolute inset-0 bg-gray-500/75" />
                </TransitionChild>

                <TransitionChild
                    enter="ease-out duration-300"
                    enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enterTo="opacity-100 translate-y-0 sm:scale-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                    leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    {/*
                      * `my-auto` — not `items-center` on the container — is what
                      * centres the panel. Flex `align-items: center` centres by
                      * overflowing equally in both directions, and scrollTop
                      * cannot go below 0, so the top of any dialog taller than
                      * the viewport was permanently unreachable: you could only
                      * see it by zooming the browser out. Auto margins centre
                      * the same way but stay scrollable in both directions.
                      *
                      * The height cap then keeps the panel inside the viewport
                      * and lets it scroll its own content, so a long form never
                      * pushes its buttons off-screen. `overflow-y-auto` (rather
                      * than `hidden`) is essential here — with a cap and hidden
                      * overflow the excess would simply be cut off.
                      */}
                    <DialogPanel
                        className={`my-auto max-h-[calc(100vh-3rem)] w-full transform overflow-y-auto overscroll-contain rounded-lg bg-white shadow-xl transition-all sm:mx-auto ${maxWidthClass}`}
                    >
                        {children}
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}
