import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

/**
 * A text-like input in the product look. The classes are `.form-input`; the
 * field baseline in app.css would draw the same box on a bare <input>, so the
 * component exists for `isFocused` and the imperative focus handle, not for
 * the styling.
 */
export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={'form-input ' + className}
            ref={localRef}
        />
    );
});
