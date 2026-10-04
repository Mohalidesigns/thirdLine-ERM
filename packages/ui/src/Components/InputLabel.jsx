export default function InputLabel({
    value,
    required = false,
    className = '',
    children,
    ...props
}) {
    return (
        <label
            {...props}
            className={`form-label ` + className}
        >
            {value ? value : children}
            {required && (
                <>
                    <span className="form-required" aria-hidden="true">*</span>
                    <span className="sr-only"> (required)</span>
                </>
            )}
        </label>
    );
}
