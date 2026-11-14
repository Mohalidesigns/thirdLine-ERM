import PortalGuestLayout from '@/Layouts/PortalGuestLayout';

/**
 * An invitation that cannot be used — unrecognised, expired, already used or
 * withdrawn. The server says which, because each needs a different next step
 * and a bare "invalid link" sends every vendor to the same dead end.
 */
export default function InvitationRefused({ reason }) {
    return (
        <PortalGuestLayout title="Invitation unavailable">
            <h1 className="text-lg font-semibold text-gray-900">This invitation can't be used</h1>

            <p role="alert" className="mt-3 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {reason}
            </p>

            <p className="mt-4 text-sm text-gray-700">
                If you need access, ask your contact at the organisation that invited you to send a new invitation.
                If you have already set up your account, sign in with the link from your original invitation email.
            </p>
        </PortalGuestLayout>
    );
}
