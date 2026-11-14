import PortalGuestLayout from '@/Layouts/PortalGuestLayout';

/**
 * The bare /vendor-portal page — a bookmark without the client's path, a
 * signed-out vendor, or a session that lapsed (the flash says which).
 *
 * IT DOES NOT LIST THE CLIENTS, and must not: a page enumerating every bank
 * on the platform is a customer list readable by anybody. Sign-in needs the
 * client in the path, so all this page can do is say where that link is.
 */
export default function Entry() {
    return (
        <PortalGuestLayout title="Sign in">
            <h1 className="text-lg font-semibold text-gray-900">Sign in to the vendor portal</h1>

            <p className="mt-3 text-sm text-gray-700">
                Each organisation you work with has its own sign-in link. Use the link from the invitation email
                that organisation sent you — it takes you straight to their sign-in page.
            </p>

            <p className="mt-3 text-sm text-gray-700">
                Bookmark that sign-in page once you reach it, so you can come back to it directly.
            </p>

            <div className="mt-5 rounded border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                <p className="font-medium text-gray-900">Can't find your link?</p>
                <p className="mt-1">
                    Ask your contact at the organisation that invited you to send it again. If your invitation has
                    expired, they can issue a new one.
                </p>
            </div>
        </PortalGuestLayout>
    );
}
