import InfoStrip from './InfoStrip';

/** The banner for a failure captured by useVisitFailures. `role="alert"`: it follows an action. */
export default function FailureBanner({ failure, onRetry, onDismiss, retryLabel }) {
    if (!failure) return null;

    return (
        <InfoStrip tone="error" role="alert" className="mb-4">
            <p>{failure.message}</p>
            <div className="mt-2 flex gap-2">
                {onRetry && (
                    <button type="button" onClick={onRetry} className="btn-secondary text-sm">
                        {retryLabel ?? (failure.kind === 'session' ? 'Retry' : 'Try again')}
                    </button>
                )}
                <button type="button" onClick={onDismiss} className="text-sm font-medium text-red-800 underline">Dismiss</button>
            </div>
        </InfoStrip>
    );
}
