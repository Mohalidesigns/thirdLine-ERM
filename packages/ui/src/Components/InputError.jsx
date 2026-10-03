export default function InputError({ message, className = '', ...props }) {
    return message ? (
        <p
            {...props}
            className={'form-error ' + className}
        >
            {message}
        </p>
    ) : null;
}
