import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

// Inline SVG icon components (no external dependency)
const Icon = ({ d, className = 'w-5 h-5', strokeWidth = 1.5 }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" strokeWidth={strokeWidth} stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d={d} />
    </svg>
);

const Icons = {
    shield: (cls) => <Icon className={cls} d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />,
    check: (cls) => <Icon className={cls} d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />,
    x: (cls) => <Icon className={cls} d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />,
    alert: (cls) => <Icon className={cls} d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />,
    clock: (cls) => <Icon className={cls} d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />,
    key: (cls) => <Icon className={cls} d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />,
    download: (cls) => <Icon className={cls} d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />,
    upload: (cls) => <Icon className={cls} d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />,
    server: (cls) => <Icon className={cls} d="M21.75 17.25v-.228a4.5 4.5 0 00-.12-1.03l-2.268-9.64a3.375 3.375 0 00-3.285-2.602H7.923a3.375 3.375 0 00-3.285 2.602l-2.268 9.64a4.5 4.5 0 00-.12 1.03v.228m19.5 0a3 3 0 01-3 3H5.25a3 3 0 01-3-3m19.5 0a3 3 0 00-3-3H5.25a3 3 0 00-3 3m16.5 0h.008v.008h-.008v-.008zm-3 0h.008v.008h-.008v-.008z" />,
    refresh: (cls) => <Icon className={cls} d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />,
    trash: (cls) => <Icon className={cls} d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />,
    bolt: (cls) => <Icon className={cls} d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />,
    lock: (cls) => <Icon className={cls} d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />,
    unlock: (cls) => <Icon className={cls} d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />,
    eye: (cls) => <Icon className={cls} d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />,
    eyeOff: (cls) => <Icon className={cls} d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />,
    chevDown: (cls) => <Icon className={cls} d="M19.5 8.25l-7.5 7.5-7.5-7.5" />,
    chevUp: (cls) => <Icon className={cls} d="M4.5 15.75l7.5-7.5 7.5 7.5" />,
    activity: (cls) => <Icon className={cls} d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />,
    hdd: (cls) => <Icon className={cls} d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.7L5.737 5.1a3.375 3.375 0 012.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 01.9 2.7m0 0a3 3 0 01-3 3m0 3h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008zm-3 6h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008z" />,
};

const statusConfig = {
    normal:     { color: 'text-green-600', bg: 'bg-green-100', label: 'Active', icon: 'check' },
    grace:      { color: 'text-amber-600', bg: 'bg-amber-100', label: 'Grace Period', icon: 'alert' },
    read_only:  { color: 'text-orange-600', bg: 'bg-orange-100', label: 'Expired (Read Only)', icon: 'clock' },
    locked:     { color: 'text-red-600', bg: 'bg-red-100', label: 'Locked', icon: 'x' },
    unlicensed: { color: 'text-gray-500', bg: 'bg-gray-100', label: 'No License', icon: 'lock' },
};

function LicenseStatusCard({ status, totalUsers }) {
    const config = statusConfig[status?.mode] || statusConfig.unlicensed;
    const reachable = status?.server_reachable;
    const reachConfig = reachable === true
        ? { color: 'text-green-700', bg: 'bg-green-50', label: 'Server reachable', icon: 'check' }
        : reachable === false
            ? { color: 'text-amber-700', bg: 'bg-amber-50', label: 'Server unreachable', icon: 'alert' }
            : { color: 'text-gray-500', bg: 'bg-gray-50', label: 'Server status unknown', icon: 'clock' };

    return (
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-lg bg-[#1B2559] flex items-center justify-center">
                        {Icons.shield('w-5 h-5 text-white')}
                    </div>
                    <div>
                        <h3 className="text-sm font-semibold text-gray-900 flex items-center gap-2">
                            License Status
                            {status?.type && status.type !== 'full' && status.plan !== 'none' && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-amber-100 text-amber-700">
                                    {status.type}
                                </span>
                            )}
                        </h3>
                        <p className="text-xs text-gray-500">
                            {status?.plan && status.plan !== 'none'
                                ? `${status.plan.charAt(0).toUpperCase() + status.plan.slice(1)} Plan`
                                : 'No License Activated'}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <span title={status?.server_last_error || ''}
                          className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium ${reachConfig.bg} ${reachConfig.color}`}>
                        {Icons[reachConfig.icon]('w-3 h-3')}
                        {reachConfig.label}
                    </span>
                    <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ${config.bg} ${config.color}`}>
                        {Icons[config.icon]('w-3.5 h-3.5')}
                        {config.label}
                    </span>
                </div>
            </div>

            <div className="px-6 py-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">Days Remaining</p>
                    <p className="text-2xl font-bold text-gray-900">{status?.days_remaining ?? 0}</p>
                    <div className="mt-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            className={`h-full rounded-full transition-all ${
                                (status?.days_remaining || 0) > 30 ? 'bg-green-500' :
                                (status?.days_remaining || 0) > 7  ? 'bg-amber-500' : 'bg-red-500'
                            }`}
                            style={{ width: `${Math.min(100, ((status?.days_remaining || 0) / 365) * 100)}%` }}
                        />
                    </div>
                </div>
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">Max Users</p>
                    <p className="text-2xl font-bold text-gray-900">{status?.max_users ?? 0}</p>
                    <p className="text-xs text-gray-500">{totalUsers} currently active</p>
                </div>
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">License ID</p>
                    <p className="text-sm font-mono text-gray-700 truncate">{status?.license_id || '---'}</p>
                </div>
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1">Last Sync</p>
                    <p className="text-sm text-gray-700">
                        {status?.last_sync
                            ? new Date(status.last_sync * 1000).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
                            : 'Never'}
                    </p>
                </div>
            </div>
        </div>
    );
}

function FeaturesGrid({ features, availableFeatures }) {
    const featureEntries = Object.entries(availableFeatures || {});

    return (
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-gray-900">Licensed Modules</h3>
                <p className="text-xs text-gray-500 mt-0.5">Features enabled under your current plan</p>
            </div>
            <div className="px-6 py-4">
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    {featureEntries.map(([key, label]) => {
                        const enabled = features?.[key] === true;
                        return (
                            <div key={key} className={`flex items-center gap-2 px-3 py-2.5 rounded-lg border ${
                                enabled ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-50 border-gray-200 text-gray-400'
                            }`}>
                                {enabled ? Icons.unlock('w-4 h-4 flex-shrink-0') : Icons.lock('w-4 h-4 flex-shrink-0')}
                                <span className="text-xs font-medium truncate">{label}</span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function ActivationSection({ status }) {
    const [mode, setMode] = useState('online');
    const [licenseKey, setLicenseKey] = useState('');
    const [showKey, setShowKey] = useState(false);
    const [loading, setLoading] = useState(false);
    const [fingerprintData, setFingerprintData] = useState(null);
    const [offlineStep, setOfflineStep] = useState(1);

    const handleOnlineActivation = (e) => {
        e.preventDefault();
        setLoading(true);
        router.post(route('admin.license.activate'), { license_key: licenseKey }, {
            onFinish: () => setLoading(false),
        });
    };

    const handleFileUpload = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        setLoading(true);
        const formData = new FormData();
        formData.append('license_file', file);
        router.post(route('admin.license.offline-activate'), formData, {
            forceFormData: true,
            onFinish: () => setLoading(false),
        });
    };

    const generateFingerprint = async () => {
        setLoading(true);
        try {
            const response = await fetch(route('admin.license.generate-fingerprint'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
            });
            const data = await response.json();
            setFingerprintData(data.fingerprint_file);
            setOfflineStep(2);
        } catch (err) {
            console.error('Failed to generate fingerprint:', err);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-gray-900">License Activation</h3>
                <p className="text-xs text-gray-500 mt-0.5">
                    {status?.valid ? 'License is active. You can re-activate with a new key.' : 'Activate your license to enable all features.'}
                </p>
            </div>
            <div className="px-6 py-4">
                {/* Mode Toggle */}
                <div className="flex flex-wrap gap-2 mb-6">
                    <button onClick={() => setMode('online')}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                            mode === 'online' ? 'bg-[#1B2559] text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                        }`}>
                        {Icons.key('w-4 h-4')} Online Activation
                    </button>
                    <button onClick={() => setMode('offline')}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                            mode === 'offline' ? 'bg-[#1B2559] text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                        }`}>
                        {Icons.hdd('w-4 h-4')} Offline Activation
                    </button>
                </div>

                {/* Online Activation */}
                {mode === 'online' && (
                    <form onSubmit={handleOnlineActivation} className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">License Key</label>
                            <div className="relative">
                                <input
                                    type={showKey ? 'text' : 'password'}
                                    value={licenseKey}
                                    onChange={(e) => setLicenseKey(e.target.value)}
                                    placeholder="Paste your JWT license key here..."
                                    className="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-[#1B2559]/20 focus:border-[#1B2559] pr-10"
                                />
                                <button type="button" onClick={() => setShowKey(!showKey)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    {showKey ? Icons.eyeOff('w-4 h-4') : Icons.eye('w-4 h-4')}
                                </button>
                            </div>
                        </div>
                        <button type="submit" disabled={loading || !licenseKey}
                            className="px-6 py-2.5 bg-[#1B2559] text-white rounded-lg text-sm font-semibold hover:bg-[#2a3670] transition-colors disabled:opacity-50">
                            {loading ? 'Activating...' : 'Activate License'}
                        </button>
                    </form>
                )}

                {/* Offline Activation Wizard */}
                {mode === 'offline' && (
                    <div className="space-y-6">
                        <div className="flex flex-wrap items-center gap-2">
                            {[{ step: 1, label: 'Generate Fingerprint', icon: 'hdd' }, { step: 2, label: 'Download & Send', icon: 'download' }, { step: 3, label: 'Import License', icon: 'upload' }].map(({ step, label, icon }, idx) => (
                                <div key={step} className="flex items-center">
                                    <div className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold ${
                                        offlineStep === step ? 'bg-[#1B2559] text-white' : offlineStep > step ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'
                                    }`}>
                                        {Icons[icon]('w-3.5 h-3.5')} {label}
                                    </div>
                                    {idx < 2 && <div className={`w-6 h-0.5 mx-1 ${offlineStep > step ? 'bg-green-300' : 'bg-gray-200'}`} />}
                                </div>
                            ))}
                        </div>
                        {offlineStep === 1 && (
                            <div className="text-center py-6">
                                {Icons.hdd('w-12 h-12 text-[#1B2559] mx-auto mb-3')}
                                <h4 className="text-sm font-semibold text-gray-900 mb-1">Generate Device Fingerprint</h4>
                                <p className="text-xs text-gray-500 mb-4 max-w-md mx-auto">Creates a unique identifier for this server. Share with your licensing administrator.</p>
                                <button onClick={generateFingerprint} disabled={loading}
                                    className="px-6 py-2.5 bg-[#1B2559] text-white rounded-lg text-sm font-semibold hover:bg-[#2a3670] disabled:opacity-50">
                                    {loading ? 'Generating...' : 'Generate Fingerprint'}
                                </button>
                            </div>
                        )}
                        {offlineStep === 2 && fingerprintData && (
                            <div className="text-center py-6">
                                {Icons.download('w-12 h-12 text-[#C9A84C] mx-auto mb-3')}
                                <h4 className="text-sm font-semibold text-gray-900 mb-1">Download Fingerprint File</h4>
                                <p className="text-xs text-gray-500 mb-4">Send this file to your licensing administrator to receive your license file.</p>
                                <a href={`data:text/plain;base64,${fingerprintData}`} download="device_fingerprint.dat"
                                    className="inline-block px-6 py-2.5 bg-[#C9A84C] text-white rounded-lg text-sm font-semibold hover:bg-[#b8993f] mb-3">
                                    Download Fingerprint File
                                </a>
                                <br />
                                <button onClick={() => setOfflineStep(3)} className="text-sm text-[#1B2559] font-medium hover:underline mt-2">
                                    I have received my license file &rarr;
                                </button>
                            </div>
                        )}
                        {offlineStep === 3 && (
                            <div className="text-center py-6">
                                {Icons.upload('w-12 h-12 text-green-600 mx-auto mb-3')}
                                <h4 className="text-sm font-semibold text-gray-900 mb-1">Import License File</h4>
                                <p className="text-xs text-gray-500 mb-4">Upload the signed license file from your administrator.</p>
                                <label className="inline-block px-6 py-2.5 bg-green-600 text-white rounded-lg text-sm font-semibold cursor-pointer hover:bg-green-700">
                                    {loading ? 'Importing...' : 'Choose License File'}
                                    <input type="file" accept=".lic,.dat,.enc,.jwt,.txt" onChange={handleFileUpload} className="hidden" disabled={loading} />
                                </label>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

function DeviceInfoCard({ status }) {
    return (
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-gray-900">Device Information</h3>
            </div>
            <div className="px-6 py-4 space-y-3">
                <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-gray-500">Device Fingerprint</span>
                    <span className="text-xs font-mono text-gray-700 truncate max-w-[200px]">{status?.device_fingerprint || '---'}</span>
                </div>
                <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-gray-500">Grace Period</span>
                    <span className="text-xs text-gray-700">{status?.grace_period?.message || 'N/A'}</span>
                </div>
            </div>
        </div>
    );
}

function ActionsCard({ status }) {
    const [loading, setLoading] = useState(null);
    return (
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-gray-900">Actions</h3>
            </div>
            <div className="px-6 py-4 space-y-2">
                <button onClick={() => { setLoading('sync'); router.post(route('admin.license.sync'), {}, { onFinish: () => setLoading(null) }); }}
                    disabled={loading === 'sync' || !status?.valid}
                    className="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-gray-200 hover:bg-gray-50 disabled:opacity-50 text-left">
                    <span className={loading === 'sync' ? 'animate-spin' : ''}>{Icons.refresh('w-4 h-4 text-[#1B2559]')}</span>
                    <div>
                        <p className="text-sm font-medium text-gray-900">Sync Heartbeat</p>
                        <p className="text-xs text-gray-500">Check in with the licensing server</p>
                    </div>
                </button>
                <button onClick={() => { if (confirm('Are you sure you want to deactivate this license?')) { setLoading('deact'); router.post(route('admin.license.deactivate'), {}, { onFinish: () => setLoading(null) }); } }}
                    disabled={loading === 'deact' || !status?.valid}
                    className="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-red-200 hover:bg-red-50 disabled:opacity-50 text-left">
                    {Icons.trash('w-4 h-4 text-red-500')}
                    <div>
                        <p className="text-sm font-medium text-red-700">Deactivate License</p>
                        <p className="text-xs text-red-500">Remove license from this device</p>
                    </div>
                </button>
            </div>
        </div>
    );
}

function AuditLogTable({ logs }) {
    const [expanded, setExpanded] = useState(false);
    const displayLogs = expanded ? logs : logs.slice(0, 10);
    const actionColors = {
        validation_success: 'bg-green-100 text-green-700', heartbeat_success: 'bg-blue-100 text-blue-700',
        activation_success: 'bg-green-100 text-green-700', heartbeat_failed: 'bg-amber-100 text-amber-700',
        validation_failed: 'bg-red-100 text-red-700', tamper_detected: 'bg-red-100 text-red-700',
        device_mismatch: 'bg-red-100 text-red-700', license_expired: 'bg-orange-100 text-orange-700',
        license_deactivated: 'bg-gray-100 text-gray-700', feature_access_denied: 'bg-amber-100 text-amber-700',
    };

    return (
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">Audit Log</h3>
                    <p className="text-xs text-gray-500 mt-0.5">Local licensing event trail</p>
                </div>
                {Icons.activity('w-4 h-4 text-gray-400')}
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Action</th>
                            <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Details</th>
                            <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Synced</th>
                            <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {displayLogs.length === 0 ? (
                            <tr><td colSpan={4} className="px-4 py-8 text-center text-gray-400 text-sm">No audit log entries yet</td></tr>
                        ) : displayLogs.map((log) => (
                            <tr key={log.id} className="hover:bg-gray-50">
                                <td className="px-4 py-2.5">
                                    <span className={`inline-block px-2 py-0.5 rounded text-xs font-semibold ${actionColors[log.action] || 'bg-gray-100 text-gray-600'}`}>
                                        {log.action.replace(/_/g, ' ')}
                                    </span>
                                </td>
                                <td className="px-4 py-2.5 text-xs text-gray-600 max-w-[200px] truncate">
                                    {log.metadata ? JSON.stringify(log.metadata).substring(0, 80) : '---'}
                                </td>
                                <td className="px-4 py-2.5">
                                    {log.synced ? Icons.check('w-4 h-4 text-green-500') : Icons.clock('w-4 h-4 text-gray-400')}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-gray-500">{log.created_at}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {logs.length > 10 && (
                <div className="px-6 py-3 border-t border-gray-100 text-center">
                    <button onClick={() => setExpanded(!expanded)} className="text-sm text-[#1B2559] font-medium hover:underline inline-flex items-center gap-1">
                        {expanded ? <>{Icons.chevUp('w-4 h-4')} Show Less</> : <>{Icons.chevDown('w-4 h-4')} Show All ({logs.length})</>}
                    </button>
                </div>
            )}
        </div>
    );
}

export default function License({ licenseStatus, auditLogs, availableFeatures, plans, totalUsers }) {
    const { flash } = usePage().props;

    return (
        <AuthenticatedLayout>
            <Head title="License Management" />

            <div className="mb-2">
                <nav className="text-sm text-gray-500 mb-1">
                    <span>Administration</span> &rsaquo; <span className="font-medium text-gray-900">License</span>
                </nav>
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">License Management</h1>
                        <p className="text-sm text-gray-500 mt-1">Manage this deployment's licence, features, and activation</p>
                    </div>
                    {Icons.server('w-6 h-6 text-gray-400')}
                </div>
            </div>

            {flash?.success && (
                <div className="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 flex items-center gap-2">
                    {Icons.check('w-4 h-4')} {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 flex items-center gap-2">
                    {Icons.x('w-4 h-4')} {flash.error}
                </div>
            )}

            <div className="space-y-6">
                <LicenseStatusCard status={licenseStatus} totalUsers={totalUsers} />
                <FeaturesGrid features={licenseStatus?.features} availableFeatures={availableFeatures} />
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2">
                        <ActivationSection status={licenseStatus} />
                    </div>
                    <div className="space-y-6">
                        <DeviceInfoCard status={licenseStatus} />
                        <ActionsCard status={licenseStatus} />
                    </div>
                </div>
                <AuditLogTable logs={auditLogs || []} />
            </div>
        </AuthenticatedLayout>
    );
}
