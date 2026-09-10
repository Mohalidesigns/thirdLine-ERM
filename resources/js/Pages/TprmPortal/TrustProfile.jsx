import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * FR-PRT-04 — the profile the vendor writes once.
 *
 * THE PAGE LEADS WITH WHAT THE VENDOR GETS, not with a percentage. "Answer
 * these ten questions once and new assessments arrive part-answered" is a
 * reason to spend an afternoon; "your profile is 43% complete" is a scold. The
 * bar is here because progress should be visible, not because it is the
 * argument.
 *
 * DRAFT AND PUBLISHED ARE VISIBLY DIFFERENT THINGS. A vendor editing next
 * quarter's answers must be able to see that four banks are still reading last
 * quarter's, or they will not risk touching it.
 */
export default function TrustProfile({
    profile = {}, schema = [], completeness = {}, nextBest = [], tenantCount = 1,
}) {
    const [open, setOpen] = useState(schema[0]?.key ?? null);

    return (
        <PortalLayout title="Trust profile">
            <h1 className="mb-1 text-lg font-semibold text-gray-900">Your trust profile</h1>
            <p className="mb-6 max-w-3xl text-sm text-gray-600">
                Answer these once. When a client sends you an assessment, the questions you have already answered
                arrive filled in with your own words for you to confirm or correct — so the second questionnaire
                takes a fraction of the time the first did.
                {tenantCount > 1 && (
                    <> You serve {tenantCount} organisations on this platform, and one profile answers all of them.</>
                )}
            </p>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-3 lg:col-span-2">
                    {schema.map((section) => (
                        <Section
                            key={section.key}
                            section={section}
                            draft={profile.draft?.[section.key] ?? {}}
                            score={(completeness.sections ?? []).find((s) => s.key === section.key)}
                            isOpen={open === section.key}
                            onToggle={() => setOpen(open === section.key ? null : section.key)}
                        />
                    ))}
                </div>

                <aside className="space-y-4">
                    <div className="rounded-lg border border-gray-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-gray-900">Published</h2>

                        {profile.published_version > 0 ? (
                            <p className="mt-1 text-sm text-gray-700">
                                Version {profile.published_version}, {profile.last_published_at}.
                            </p>
                        ) : (
                            <p className="mt-1 text-sm text-gray-500">
                                Nothing published yet. Your clients see nothing until you publish and approve them
                                individually.
                            </p>
                        )}

                        <div className="mt-3 h-2 w-full overflow-hidden rounded bg-gray-100">
                            <div className="h-full bg-green-600" style={{ width: `${completeness.pct ?? 0}%` }} />
                        </div>
                        <p className="mt-1 text-xs text-gray-500">{completeness.pct ?? 0}% of the published profile</p>

                        {profile.has_unpublished_changes && (
                            <p className="mt-3 rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-900">
                                You have saved changes your clients cannot see yet.
                            </p>
                        )}

                        <button
                            type="button"
                            className="mt-3 w-full rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                            onClick={() => router.post(route('tprm-portal.trust-profile.publish'))}
                        >
                            Publish
                        </button>
                    </div>

                    {nextBest.length > 0 && (
                        <div className="rounded-lg border border-gray-200 bg-white p-4">
                            <h2 className="text-sm font-semibold text-gray-900">What unlocks faster onboarding</h2>
                            <ul className="mt-2 space-y-3">
                                {nextBest.map((section) => (
                                    <li key={section.key}>
                                        <p className="text-xs font-medium text-gray-800">{section.label}</p>
                                        <p className="mt-0.5 text-xs text-gray-600">{section.unlocks}</p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            {section.answered}/{section.total} answered
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </aside>
            </div>
        </PortalLayout>
    );
}

function Section({ section, draft, score, isOpen, onToggle }) {
    const form = useForm({ sections: { [section.key]: { ...draft } } });

    const setField = (field, value) => {
        form.setData('sections', {
            [section.key]: { ...form.data.sections[section.key], [field]: value },
        });
    };

    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50"
            >
                <span className="min-w-0">
                    <span className="block text-sm font-medium text-gray-900">{section.label}</span>
                    <span className="block text-xs text-gray-500">
                        {score ? `${score.answered}/${score.total} answered` : 'Not started'} · {section.unlocks}
                    </span>
                </span>
                <span className="shrink-0 text-gray-400">{isOpen ? '−' : '+'}</span>
            </button>

            {isOpen && (
                <form
                    className="space-y-3 border-t border-gray-100 p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(route('tprm-portal.trust-profile.save'), { preserveScroll: true });
                    }}
                >
                    {section.fields.map((field) => (
                        <div key={field}>
                            <label className="mb-1 block text-xs font-medium text-gray-700" htmlFor={`${section.key}-${field}`}>
                                {field.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())}
                            </label>
                            <textarea
                                id={`${section.key}-${field}`}
                                className="w-full rounded border border-gray-200 p-2 text-sm"
                                rows="2"
                                value={form.data.sections[section.key]?.[field] ?? ''}
                                onChange={(event) => setField(field, event.target.value)}
                            />
                        </div>
                    ))}

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-40"
                    >
                        Save section
                    </button>
                </form>
            )}
        </div>
    );
}
