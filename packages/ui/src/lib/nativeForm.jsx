/**
 * Helpers for the handful of forms that post NATIVELY rather than through
 * Inertia's useForm — sign-in and the MFA screens.
 *
 * Why: until Phase 6 their success redirect lands on a Blade page
 * (/risk/dashboard). An Inertia XHR that follows a redirect into non-Inertia
 * HTML shows that HTML in an error modal; a native form submission simply
 * navigates. Validation errors still arrive the Inertia way (the redirect
 * back lands on this Inertia page with `errors` and `old` shared).
 */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

export function CsrfField() {
    return <input type="hidden" name="_token" value={csrfToken()} />;
}
