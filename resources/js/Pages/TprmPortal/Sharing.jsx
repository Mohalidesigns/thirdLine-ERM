import { router } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * FR-PRT-04's consent screen — who may read the profile.
 *
 * EACH CLIENT IS APPROVED SEPARATELY AND CAN BE WITHDRAWN. That is not
 * ceremony: a vendor's security posture is commercially sensitive, and a
 * profile readable by every bank on the platform the moment it was written is
 * a profile no vendor completes.
 */
export default function Sharing({ shares = [], sections = [] }) {
    const [approving, setApproving] = useState(null);

    const pending = shares.filter((share) => share.status === 'requested');
    const decided = shares.filter((share) => share.status !== 'requested');

    return (
        <PortalLayout title="Sharing">
            <h1 className="mb-1 text-lg font-semibold text-gray-900">Who can read your profile</h1>
            <p className="mb-6 max-w-3xl text-sm text-gray-600">
                Nobody sees your trust profile until you say so, and you can choose which sections each client
                gets. Withdrawing access takes effect immediately.
            </p>

            {pending.length > 0 && (
                <section className="mb-6">
                    <h2 className="mb-2 text-sm font-semibold text-gray-900">Waiting on you</h2>
                    <div className="divide-y divide-gray-100 overflow-hidden rounded-lg border border-amber-200 bg-white">
                        {pending.map((share) => (
                            <div key={share.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">{share.client}</p>
                                    <p className="text-xs text-gray-500">Asked {share.requested_at}</p>
                                </div>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white"
                                        onClick={() => setApproving(share)}
                                    >
                                        Approve
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded border border-gray-200 px-3 py-1.5 text-sm text-gray-700"
                                        onClick={() => router.post(route('tprm-portal.sharing.decline', share.id))}
                                    >
                                        Decline
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            <section>
                <h2 className="mb-2 text-sm font-semibold text-gray-900">Your clients</h2>
                {decided.length === 0 ? (
                    <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
                        No client has asked for your profile yet.
                    </div>
                ) : (
                    <div className="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white">
                        {decided.map((share) => (
                            <div key={share.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-900">{share.client}</p>
                                    <p className="text-xs text-gray-500">
                                        {share.status === 'approved' && `Reading since ${share.approved_at}`}
                                        {share.status === 'revoked' && `Withdrawn ${share.revoked_at}`}
                                        {share.status === 'declined' && 'Declined'}
                                        {share.status === 'approved' && share.sections?.length > 0 && (
                                            <> · {share.sections.length} of {sections.length} sections</>
                                        )}
                                    </p>
                                </div>

                                {share.status === 'approved' && (
                                    <button
                                        type="button"
                                        className="rounded border border-red-200 px-3 py-1.5 text-sm text-red-700"
                                        onClick={() => router.post(route('tprm-portal.sharing.revoke', share.id))}
                                    >
                                        Withdraw access
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {approving && (
                <ApproveDialog share={approving} sections={sections} onClose={() => setApproving(null)} />
            )}
        </PortalLayout>
    );
}

function ApproveDialog({ share, sections, onClose }) {
    const [selected, setSelected] = useState(sections.map((section) => section.key));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <div className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h2 className="text-base font-semibold text-gray-900">Share with {share.client}</h2>
                <p className="mt-1 text-xs text-gray-600">
                    Choose what they may read. You can change this or withdraw it later.
                </p>

                <div className="mt-3 space-y-2">
                    {sections.map((section) => (
                        <label key={section.key} className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={selected.includes(section.key)}
                                onChange={(event) => setSelected(
                                    event.target.checked
                                        ? [...selected, section.key]
                                        : selected.filter((key) => key !== section.key),
                                )}
                            />
                            {section.label}
                        </label>
                    ))}
                </div>

                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" className="px-3 py-1.5 text-sm text-gray-600" onClick={onClose}>Cancel</button>
                    <button
                        type="button"
                        className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white"
                        onClick={() => router.post(
                            route('tprm-portal.sharing.approve', share.id),
                            { sections: selected },
                            { onSuccess: onClose },
                        )}
                    >
                        Approve
                    </button>
                </div>
            </div>
        </div>
    );
}
