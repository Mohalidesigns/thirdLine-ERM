import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { INPUT, SELECT } from './format';

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Open a campaign (Phase 4.5: risk/campaigns/create.blade.php).
 *
 * The questionnaire list holds PUBLISHED questionnaires only, as it always has
 * — and StoreCampaignRequest now agrees with it, so a campaign cannot be hung
 * off a draft whose questions can still be rewritten under its respondents.
 */
export default function Create({ questionnaires = [], users = [], campaignTypes = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        campaign_type: 'rcsa',
        questionnaire_id: '',
        start_date: today(),
        end_date: '',
        reviewer_id: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.campaigns.store'));
    };

    return (
        <AuthenticatedLayout title="Create Campaign">
            <Head title="Create Campaign" />

            <PageHeader
                title="Create Assessment Campaign"
                subtitle="Who is being assessed, against what, and by when"
                breadcrumbs={[{ label: 'Campaigns', href: route('risk.campaigns.index') }, { label: 'Create' }]}
            />

            <form onSubmit={submit} className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
                    <div>
                        <InputLabel htmlFor="title">Campaign Title<span className="text-red-500"> *</span></InputLabel>
                        <input
                            id="title"
                            type="text"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            className={INPUT}
                            placeholder="e.g. Q1 2026 RCSA Campaign"
                        />
                        <InputError message={errors.title} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="description">Description</InputLabel>
                        <textarea
                            id="description"
                            rows={3}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className={INPUT}
                        />
                        <InputError message={errors.description} className="mt-1" />
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <InputLabel htmlFor="campaign_type">Campaign Type<span className="text-red-500"> *</span></InputLabel>
                            <select
                                id="campaign_type"
                                value={data.campaign_type}
                                onChange={(e) => setData('campaign_type', e.target.value)}
                                className={SELECT}
                            >
                                {Object.entries(campaignTypes).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                            <InputError message={errors.campaign_type} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="questionnaire_id">Questionnaire</InputLabel>
                            <select
                                id="questionnaire_id"
                                value={data.questionnaire_id}
                                onChange={(e) => setData('questionnaire_id', e.target.value)}
                                className={SELECT}
                            >
                                <option value="">None (free-form)</option>
                                {questionnaires.map((questionnaire) => (
                                    <option key={questionnaire.id} value={questionnaire.id}>{questionnaire.title}</option>
                                ))}
                            </select>
                            <p className="text-xs text-gray-400 mt-1">
                                {questionnaires.length === 0
                                    ? 'No published questionnaires yet — respondents will answer on the register risks of their unit.'
                                    : 'Respondents answer these questions as well as assessing their unit’s register risks.'}
                            </p>
                            <InputError message={errors.questionnaire_id} className="mt-1" />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                        <div>
                            <InputLabel htmlFor="start_date">Start Date<span className="text-red-500"> *</span></InputLabel>
                            <input
                                id="start_date"
                                type="date"
                                value={data.start_date}
                                onChange={(e) => setData('start_date', e.target.value)}
                                className={INPUT}
                            />
                            <InputError message={errors.start_date} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="end_date">End Date<span className="text-red-500"> *</span></InputLabel>
                            <input
                                id="end_date"
                                type="date"
                                value={data.end_date}
                                onChange={(e) => setData('end_date', e.target.value)}
                                className={INPUT}
                            />
                            <InputError message={errors.end_date} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="reviewer_id">Default Reviewer</InputLabel>
                            <select
                                id="reviewer_id"
                                value={data.reviewer_id}
                                onChange={(e) => setData('reviewer_id', e.target.value)}
                                className={SELECT}
                            >
                                <option value="">Select reviewer</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>{user.name}</option>
                                ))}
                            </select>
                            <InputError message={errors.reviewer_id} className="mt-1" />
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-between mt-6">
                    <Link href={route('risk.campaigns.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Create Campaign
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
