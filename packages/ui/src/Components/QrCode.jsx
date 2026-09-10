import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

/**
 * A QR code drawn IN THE BROWSER from the value it is given.
 *
 * The MFA enrolment screen feeds this the otpauth:// URI, which carries the
 * shared secret. The previous implementation fetched the image from
 * api.qrserver.com with that URI in the query string — every enrolment handed
 * the secret and the user's email to a third party. Nothing here makes a
 * network request: the `qrcode` package renders an SVG string locally.
 */
export default function QrCode({ value, size = 192, label = 'QR code' }) {
    const [svg, setSvg] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let alive = true;
        setSvg(null);
        setFailed(false);

        QRCode.toString(value, { type: 'svg', margin: 1, errorCorrectionLevel: 'M' })
            .then((markup) => alive && setSvg(markup))
            .catch(() => alive && setFailed(true));

        return () => {
            alive = false;
        };
    }, [value]);

    if (failed) {
        return (
            <div className="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 flex items-center justify-center text-xs text-gray-500" style={{ width: size, height: size }}>
                QR code unavailable — use the manual key
            </div>
        );
    }

    if (!svg) {
        return <div className="rounded-lg bg-gray-100 animate-pulse" style={{ width: size, height: size }} aria-hidden="true" />;
    }

    return (
        <div
            role="img"
            aria-label={label}
            className="rounded-lg border border-gray-200 bg-white p-2 [&>svg]:w-full [&>svg]:h-full"
            style={{ width: size, height: size }}
            dangerouslySetInnerHTML={{ __html: svg }}
        />
    );
}
