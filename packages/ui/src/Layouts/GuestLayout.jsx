import { usePage } from '@inertiajs/react';

/**
 * The unauthenticated shell: brand panel, one card. Flash messages (the
 * login redirects carry success / error / warning) render above the card.
 */
export default function GuestLayout({ title, subtitle, icon = 'verified_user', status, children, wide = false }) {
    const { flash } = usePage().props;

    const banners = [
        status && { tone: 'green', text: status },
        flash?.success && { tone: 'green', text: flash.success },
        flash?.error && { tone: 'red', text: flash.error },
        flash?.warning && { tone: 'yellow', text: flash.warning },
        flash?.status && { tone: 'green', text: flash.status },
    ].filter(Boolean);

    const tones = {
        green: 'bg-green-50 border-green-200 text-green-700',
        red: 'bg-red-50 border-red-200 text-red-700',
        yellow: 'bg-yellow-50 border-yellow-200 text-yellow-700',
    };

    return (
        <div
            className="min-h-screen flex items-center justify-center px-4 py-10"
            style={{ background: 'linear-gradient(135deg, #1A365D 0%, #2D7D46 100%)' }}
        >
            <div className={`w-full ${wide ? 'max-w-2xl' : 'max-w-md'}`}>
                <div className="text-center mb-8">
                    <div className="inline-flex items-center justify-center w-16 h-16 rounded-lg bg-white/20 backdrop-blur-sm mb-4">
                        <span className="material-symbols-outlined text-white text-4xl">{icon}</span>
                    </div>
                    <h1 className="text-white text-3xl font-bold">Atheris ERM</h1>
                    <p className="text-white/70 text-sm mt-2">{subtitle || 'Enterprise Risk & Compliance Management'}</p>
                </div>

                <div className="bg-white rounded-xl shadow-2xl overflow-hidden">
                    <div className="p-8">
                        {title && <h2 className="text-2xl font-bold text-gray-900 mb-1">{title}</h2>}
                        {banners.map((banner, i) => (
                            <div key={i} className={`mt-4 mb-2 p-3 rounded-lg border text-sm ${tones[banner.tone]}`}>
                                {banner.text}
                            </div>
                        ))}
                        {children}
                    </div>
                    <div className="px-8 py-4 bg-gray-50 border-t border-gray-100">
                        <p className="text-center text-xs text-gray-500">Designed for CBN ORMS reporting · Basel III-aligned taxonomy</p>
                    </div>
                </div>
            </div>
        </div>
    );
}
