import Modal from '@/Components/Modal';

export default function ConfirmDialog({
    show = false,
    title = 'Confirm Action',
    message = 'Are you sure you want to proceed?',
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    variant = 'danger',
    processing = false,
    onConfirm,
    onCancel,
}) {
    const btnClass = {
        danger: 'bg-red-600 hover:bg-red-700 focus:ring-red-500',
        warning: 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-500',
        primary: 'bg-[var(--color-primary)] hover:bg-[var(--color-primary-light)] focus:ring-[var(--color-primary)]',
        success: 'bg-green-600 hover:bg-green-700 focus:ring-green-500',
    }[variant] || 'bg-red-600 hover:bg-red-700 focus:ring-red-500';

    const iconBg = {
        danger: 'bg-red-100',
        warning: 'bg-amber-100',
        primary: 'bg-blue-100',
        success: 'bg-green-100',
    }[variant] || 'bg-red-100';

    const iconColor = {
        danger: 'text-red-600',
        warning: 'text-amber-600',
        primary: 'text-blue-600',
        success: 'text-green-600',
    }[variant] || 'text-red-600';

    return (
        <Modal show={show} maxWidth="md" onClose={onCancel}>
            <div className="p-6">
                <div className="flex items-start gap-4">
                    <div className={`w-10 h-10 rounded-full ${iconBg} flex items-center justify-center flex-shrink-0`}>
                        <svg className={`w-5 h-5 ${iconColor}`} fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
                        <p className="text-sm text-gray-500 mt-1">{message}</p>
                    </div>
                </div>
                <div className="flex justify-end gap-3 mt-6">
                    <button onClick={onCancel} className="btn-secondary text-sm" disabled={processing}>
                        {cancelLabel}
                    </button>
                    <button
                        onClick={onConfirm}
                        className={`inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors ${btnClass}`}
                        disabled={processing}
                    >
                        {processing ? (
                            <>
                                <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Processing...
                            </>
                        ) : confirmLabel}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
