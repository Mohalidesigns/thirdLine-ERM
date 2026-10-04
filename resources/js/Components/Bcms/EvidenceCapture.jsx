import { useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * The evidence capture control shared by the execution workspace and the
 * observer-scoring screen (execution-workspace spec §10, observer-scoring
 * spec §9 addendum) — one implementation, so the two evidence cards in this
 * module never drift onto two different resolutions of the same trap.
 *
 * THE RE-ENCODE HAPPENS BEFORE THE UPLOAD REQUEST IS BUILT. An iPhone's
 * default photo format is HEIC; `FileUploadService::MIME_EXTENSIONS` has no
 * `image/heic` entry. Rather than widen that allowlist product-wide (a
 * decision this one capture control is not in a position to make — see the
 * spec's three reasons: it is screen-local, the service's own content
 * detection stays honest because the bytes that arrive are genuinely JPEG,
 * and a re-encoded JPEG costs less on a 100 kbps link than an accepted HEIC
 * would have), the selected image is drawn onto an off-screen canvas and
 * re-encoded to JPEG here, client-side, whatever format it arrived in.
 *
 * THE HEIC-DECODE-FAILURE FALLBACK IS SPECIFIC, NOT A GENERIC ERROR. A
 * facilitator or evaluator standing at an assembly point mid-exercise has
 * neither the time nor, often, the background to diagnose a codec problem
 * from a bare error string.
 */
export default function EvidenceCapture({ uploadUrl, ownerType, ownerKey, compact = false, onUploaded }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [caption, setCaption] = useState('');

    const fallbackMessage = compact
        ? 'Photo not readable here. Switch your camera to JPEG in Settings, or pick a different photo. '
          + 'Nothing else on this screen was lost.'
        : "This photo could not be read on this device. Open your camera settings and switch photo format "
          + "to 'Most Compatible' (JPEG), or use your phone's own Photos app to convert it, then try again. "
          + 'Nothing was lost — you can retake or re-select the photo.';

    const handleFile = (file) => {
        if (!file) return;
        setError(null);
        setBusy(true);

        reencodeToJpeg(file).then((jpegFile) => {
            router.post(uploadUrl, {
                file: jpegFile,
                owner_type: ownerType,
                owner_id: ownerKey,
                kind: 'photo',
                caption: caption || undefined,
            }, {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => { setCaption(''); onUploaded && onUploaded(); },
                onError: () => setError('The upload did not go through — check the connection and try again. '
                    + 'Nothing already saved on this screen was lost.'),
                onFinish: () => setBusy(false),
            });
        }).catch(() => {
            setBusy(false);
            setError(fallbackMessage);
        });
    };

    return (
        <div className="space-y-2">
            {!compact && (
                <input
                    type="text"
                    placeholder="Caption (optional)"
                    className="form-input text-sm"
                    aria-label="Evidence caption"
                    value={caption}
                    onChange={(e) => setCaption(e.target.value)}
                />
            )}
            <label className="block text-xs font-medium text-slate-600">
                {compact ? 'Attach a photo' : 'Capture or attach a photo'}
                <input
                    type="file"
                    accept="image/jpeg,image/png"
                    capture="environment"
                    className="mt-1 block w-full text-xs"
                    disabled={busy}
                    onChange={(e) => handleFile(e.target.files?.[0])}
                />
            </label>
            {busy && <p className="text-xs text-slate-500" role="status">Uploading…</p>}
            {error && <p className="text-xs text-rose-700" role="alert">{error}</p>}
        </div>
    );
}

/**
 * Draws the selected file onto an off-screen canvas and re-encodes it to
 * JPEG. Rejects (never resolves) when the browser cannot decode the source
 * at all — a failed HEIC decode in a browser with no
 * `createImageBitmap`/canvas HEIC support throws here or yields a
 * zero-dimensioned bitmap, both treated as the same "could not be read" case.
 */
function reencodeToJpeg(file) {
    return new Promise((resolve, reject) => {
        const objectUrl = URL.createObjectURL(file);
        const img = new Image();

        img.onload = () => {
            try {
                if (!img.naturalWidth || !img.naturalHeight) {
                    throw new Error('zero-dimensioned image — likely an undecodable HEIC source');
                }

                const canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth;
                canvas.height = img.naturalHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);

                canvas.toBlob((blob) => {
                    URL.revokeObjectURL(objectUrl);
                    if (!blob) {
                        reject(new Error('canvas re-encode produced no blob'));
                        return;
                    }
                    const name = (file.name || 'evidence').replace(/\.\w+$/, '') + '.jpg';
                    resolve(new File([blob], name, { type: 'image/jpeg' }));
                }, 'image/jpeg', 0.85);
            } catch (e) {
                URL.revokeObjectURL(objectUrl);
                reject(e);
            }
        };

        img.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error('the browser could not decode this image at all'));
        };

        img.src = objectUrl;
    });
}
