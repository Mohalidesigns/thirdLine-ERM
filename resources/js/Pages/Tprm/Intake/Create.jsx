import { useCallback, useEffect, useMemo, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputError from '@thirdline/ui/Components/InputError';
import TierBadge from '@/Components/Tprm/TierBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Intake Wizard (TRD §11) — Service → Functions → Data & Access →
 * Commercial → Questionnaire, with a live tier preview beside it.
 *
 * TWO THINGS ON THIS PAGE ARE THE PRODUCT'S ARGUMENT.
 *
 * THE LIVE PREVIEW asks the server on every change rather than approximating
 * the score in JavaScript. A client-side copy of the model would be a second
 * implementation of the regulatory logic, and the first time the two disagreed
 * the requester would be shown a tier the register then contradicted.
 *
 * THE PROHIBITED-FUNCTION WARNING appears the moment such a function is
 * selected — but it is a COURTESY, not the control. The block is server-side
 * in IntakeService, inside the transaction (AC-01), and this banner exists so
 * the requester finds out at step two rather than after filling in eighteen
 * questions.
 */
export default function Create({ options = {}, questionnaire = {}, preselectedThirdParty = null }) {
    const { flash = {} } = usePage().props;
    const prohibitedFromServer = flash.prohibitedFunctions ?? [];

    const { data, setData, post, processing, errors } = useForm({
        third_party_id: preselectedThirdParty ?? '',
        name: '',
        service_description: '',
        engagement_type: 'ict_service',
        service_type_id: '',
        business_unit_id: '',
        relationship_owner_id: '',
        executive_sponsor_id: '',
        annual_spend_minor: '',
        currency: 'NGN',
        business_function_ids: [],
        processes_personal_data: false,
        cross_border: false,
        transfer_basis: '',
        pci_in_scope: false,
        cloud_model: '',
        substitutability: '',
        time_to_replace_months: '',
        answers: {
            A1: '', A2: '', A3: '', A4: '', A5: '', A8: '', A9: '',
            A10: [], A11: false, A12: '', A13: '', A14: '',
            A15: '', A16: '', A17: false, A18: false,
        },
    });

    const [preview, setPreview] = useState(null);
    const [previewing, setPreviewing] = useState(false);

    const functions = options.businessFunctions ?? [];

    /* The selected functions that may not be outsourced — AC-01's courtesy half. */
    const prohibited = useMemo(
        () => functions.filter((f) => data.business_function_ids.includes(f.id) && f.is_prohibited_outsourcing),
        [functions, data.business_function_ids],
    );

    const setAnswer = (key, value) => setData('answers', { ...data.answers, [key]: value });

    const toggleFunction = (id) => {
        const next = data.business_function_ids.includes(id)
            ? data.business_function_ids.filter((f) => f !== id)
            : [...data.business_function_ids, id];
        setData('business_function_ids', next);
    };

    /* Debounced so a keystroke does not become a request. */
    const refreshPreview = useCallback(async () => {
        const required = ['A1', 'A2', 'A3', 'A5', 'A8', 'A12', 'A13', 'A14'];
        if (required.some((k) => !data.answers[k]) || data.business_function_ids.length === 0) {
            setPreview(null);
            return;
        }

        setPreviewing(true);
        try {
            const response = await window.axios.post(tryRoute('tprm.intake.preview'), {
                third_party_id: data.third_party_id,
                engagement_type: data.engagement_type,
                processes_personal_data: data.processes_personal_data,
                cross_border: data.cross_border,
                transfer_basis: data.transfer_basis,
                pci_in_scope: data.pci_in_scope,
                substitutability: data.substitutability,
                business_function_ids: data.business_function_ids,
                answers: data.answers,
            });
            setPreview(response.data);
        } catch {
            setPreview(null);
        } finally {
            setPreviewing(false);
        }
    }, [data]);

    useEffect(() => {
        const timer = setTimeout(refreshPreview, 400);

        return () => clearTimeout(timer);
    }, [refreshPreview]);

    const submit = (event) => {
        event.preventDefault();
        post(tryRoute('tprm.intake.store'));
    };

    const blocked = prohibited.length > 0;

    return (
        <AppLayout title="Raise an intake">
            <Head title="Raise an intake" />

            <PageHeader
                title="Raise an intake"
                subtitle="Describe the service, name the functions it supports, and the tier is computed as you answer."
            />

            {(blocked || prohibitedFromServer.length > 0) && (
                <div className="card mb-6 border-l-4 border-red-500 p-5">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-red-900">
                        <span className="material-symbols-outlined text-lg">block</span>
                        This service cannot be outsourced
                    </h3>
                    <ul className="mt-3 space-y-2">
                        {(prohibitedFromServer.length > 0
                            ? prohibitedFromServer
                            : prohibited.map((f) => ({ function: f.name, code: f.function_code, citation: f.prohibition_citation }))
                        ).map((item) => (
                            <li key={item.code} className="text-sm">
                                <p className="font-medium text-red-900">{item.function}</p>
                                {/* The citation is the point. A block with no reason is a
                                    block a business unit routes around. */}
                                <p className="mt-0.5 text-xs italic text-red-800">{item.citation}</p>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-3 text-xs text-red-800">
                        Remove the function above to continue. Nothing has been created.
                    </p>
                </div>
            )}

            <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Section step="1" title="The service">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Select label="Third party" required value={data.third_party_id} onChange={(v) => setData('third_party_id', v)} error={errors.third_party_id}
                                options={(options.thirdParties ?? []).map((t) => ({ value: t.id, label: t.legal_name }))} />
                            <Select label="Engagement type" required value={data.engagement_type} onChange={(v) => setData('engagement_type', v)} error={errors.engagement_type}
                                options={options.engagementTypes ?? []} />
                            <Text label="Engagement name" required value={data.name} onChange={(v) => setData('name', v)} error={errors.name} />
                            <Select label="Service category" value={data.service_type_id} onChange={(v) => setData('service_type_id', v)} error={errors.service_type_id}
                                options={(options.categories ?? []).map((c) => ({ value: c.id, label: c.name }))} />
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700">What the service is</label>
                                <textarea rows="2" value={data.service_description} onChange={(e) => setData('service_description', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <InputError message={errors.service_description} className="mt-1" />
                            </div>
                        </div>
                    </Section>

                    <Section step="2" title="Business functions supported"
                        hint="The tier inherits the highest criticality among these (FR-TIER-06).">
                        <InputError message={errors.business_function_ids} className="mb-2" />
                        <div className="max-h-72 overflow-y-auto rounded-md border border-gray-200">
                            {functions.map((fn) => {
                                const selected = data.business_function_ids.includes(fn.id);

                                return (
                                    <label key={fn.id}
                                        className={`flex cursor-pointer items-center gap-3 border-b border-gray-100 px-3 py-2 text-sm last:border-0 ${
                                            selected && fn.is_prohibited_outsourcing ? 'bg-red-50' : selected ? 'bg-blue-50' : 'hover:bg-gray-50'
                                        }`}>
                                        <input type="checkbox" checked={selected} onChange={() => toggleFunction(fn.id)} className="rounded border-gray-300" />
                                        <span className="font-mono text-xs text-gray-500">{fn.function_code}</span>
                                        <span className="flex-1">{fn.name}</span>
                                        {fn.is_prohibited_outsourcing && (
                                            <span className="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-800">
                                                Cannot be outsourced
                                            </span>
                                        )}
                                        <span className="text-xs capitalize text-gray-500">{fn.criticality}</span>
                                    </label>
                                );
                            })}
                        </div>
                    </Section>

                    <Section step="3" title="Data, access and commercial">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Toggle label="Processes personal data" checked={data.processes_personal_data} onChange={(v) => setData('processes_personal_data', v)} />
                            <Toggle label="Data crosses a border" checked={data.cross_border} onChange={(v) => setData('cross_border', v)} />
                            <Select label="Transfer basis (NDPA §41)" value={data.transfer_basis} onChange={(v) => setData('transfer_basis', v)} error={errors.transfer_basis}
                                options={options.transferBases ?? []}
                                hint={data.cross_border && !data.transfer_basis ? 'Without a recorded basis this tiers Critical (KO-PII-XB).' : null} />
                            <Toggle label="Cardholder data in scope (PCI)" checked={data.pci_in_scope} onChange={(v) => setData('pci_in_scope', v)} />
                            <Select label="Cloud model" value={data.cloud_model} onChange={(v) => setData('cloud_model', v)} options={options.cloudModels ?? []} />
                            <Select label="Substitutability" value={data.substitutability} onChange={(v) => setData('substitutability', v)} options={options.substitutability ?? []} />
                            <Select label="Business unit" value={data.business_unit_id} onChange={(v) => setData('business_unit_id', v)} error={errors.business_unit_id}
                                options={(options.businessUnits ?? []).map((b) => ({ value: b.id, label: b.name }))} />
                            <Select label="Executive sponsor" value={data.executive_sponsor_id} onChange={(v) => setData('executive_sponsor_id', v)} error={errors.executive_sponsor_id}
                                options={(options.users ?? []).map((u) => ({ value: u.id, label: u.name }))}
                                hint="Required before approval where a critical function is supported (FR-INT-08)." />
                        </div>
                    </Section>

                    <Section step="4" title="Inherent risk questionnaire"
                        hint="Appendix A. The tier updates beside you as you answer.">
                        <div className="space-y-4">
                            {Object.entries(questionnaire).map(([key, q]) => (
                                <Question key={key} code={key} question={q}
                                    value={data.answers[key]}
                                    onChange={(v) => setAnswer(key, v)}
                                    error={errors[`answers.${key}`]} />
                            ))}
                        </div>
                    </Section>

                    <div className="flex items-center justify-end gap-3">
                        <a href={tryRoute('tprm.third-parties.index')} className="btn-secondary text-sm">Cancel</a>
                        <button type="submit" disabled={processing || blocked} className="btn-primary text-sm disabled:opacity-50">
                            Submit intake
                        </button>
                    </div>
                </div>

                {/* The live preview. Sticky, because the questionnaire is long and
                    watching the tier move as you answer is the point of it. */}
                <div className="lg:col-span-1">
                    <div className="sticky top-6 space-y-4">
                        <div className="card p-5">
                            <div className="flex items-center justify-between">
                                <h3 className="text-sm font-semibold text-gray-900">Live tier preview</h3>
                                {previewing && <span className="text-xs text-gray-400">computing…</span>}
                            </div>

                            {preview ? (
                                <>
                                    <div className="mt-4 flex items-center justify-between">
                                        <span className="text-sm text-gray-600">Weighted score</span>
                                        <span className="text-2xl font-semibold tabular-nums text-gray-900">{preview.score}</span>
                                    </div>

                                    <div className="mt-3 flex items-center justify-between">
                                        <span className="text-sm text-gray-600">Tier</span>
                                        <TierBadge tier={preview.effective_tier} label={preview.effective_tier_label} />
                                    </div>

                                    {preview.raised_by_knockout && (
                                        <p className="mt-3 rounded bg-amber-50 px-2.5 py-2 text-xs text-amber-900">
                                            Raised above the weighted score by a knockout rule.
                                        </p>
                                    )}

                                    {preview.knockouts?.length > 0 && (
                                        <ul className="mt-3 space-y-2">
                                            {preview.knockouts.map((k) => (
                                                <li key={k.code} className="rounded bg-gray-50 p-2 text-xs">
                                                    <div className="flex items-center justify-between">
                                                        <span className="font-mono font-semibold">{k.code}</span>
                                                        <TierBadge tier={k.floor} size="sm" showLabel={false} />
                                                    </div>
                                                    <p className="mt-1 text-gray-700">{k.name}</p>
                                                    <p className="mt-0.5 italic text-gray-500">{k.citation}</p>
                                                </li>
                                            ))}
                                        </ul>
                                    )}

                                    {preview.factors?.length > 0 && (
                                        <table className="mt-4 w-full text-xs">
                                            <tbody className="divide-y divide-gray-100">
                                                {preview.factors.map((f) => (
                                                    <tr key={f.code}>
                                                        <td className="py-1 text-gray-600">{f.label}</td>
                                                        <td className="py-1 text-right tabular-nums text-gray-900">{f.weighted}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </>
                            ) : (
                                <p className="mt-3 text-xs text-gray-500">
                                    Select at least one business function and answer the required questions to see
                                    the tier.
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}

function Section({ step, title, hint, children }) {
    return (
        <div className="card p-5">
            <div className="mb-4 flex items-baseline gap-3">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white">
                    {step}
                </span>
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
                    {hint && <p className="text-xs text-gray-500">{hint}</p>}
                </div>
            </div>
            {children}
        </div>
    );
}

function Question({ code, question, value, onChange, error }) {
    return (
        <div className="border-b border-gray-100 pb-4 last:border-0 last:pb-0">
            <label className="block text-sm text-gray-800">
                <span className="mr-2 font-mono text-xs text-gray-400">{code}</span>
                {question.question}
                {question.knockout && (
                    <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">
                        {question.knockout}
                    </span>
                )}
            </label>

            {question.type === 'boolean' && (
                <div className="mt-2 flex gap-4 text-sm">
                    {[['Yes', true], ['No', false]].map(([label, val]) => (
                        <label key={label} className="flex items-center gap-1.5">
                            <input type="radio" checked={value === val} onChange={() => onChange(val)} className="border-gray-300" />
                            {label}
                        </label>
                    ))}
                </div>
            )}

            {question.type === 'select' && (
                <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Select…</option>
                    {(question.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            )}

            {question.type === 'multiselect' && (
                <div className="mt-2 flex flex-wrap gap-2">
                    {(question.options ?? []).map((o) => {
                        const selected = (value ?? []).includes(o.value);

                        return (
                            <button key={o.value} type="button"
                                onClick={() => onChange(selected ? value.filter((v) => v !== o.value) : [...(value ?? []), o.value])}
                                className={`rounded-full border px-2.5 py-1 text-xs ${
                                    selected ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-gray-300 text-gray-600 hover:bg-gray-50'
                                }`}>
                                {o.label}
                            </button>
                        );
                    })}
                </div>
            )}

            {question.type === 'country' && (
                <input type="text" maxLength="2" value={value ?? ''} onChange={(e) => onChange(e.target.value.toUpperCase())}
                    placeholder="NG"
                    className="mt-1 block w-24 rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
            )}

            <InputError message={error} className="mt-1" />
        </div>
    );
}

function Text({ label, value, onChange, error, required = false }) {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-700">
                {label}{required && <span className="ml-0.5 text-red-600">*</span>}
            </label>
            <input type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function Select({ label, value, onChange, error, options = [], hint, required = false }) {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-700">
                {label}{required && <span className="ml-0.5 text-red-600">*</span>}
            </label>
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="">Select…</option>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {hint && <p className="mt-1 text-xs text-amber-700">{hint}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function Toggle({ label, checked, onChange }) {
    return (
        <label className="flex items-center gap-2 self-end pb-2 text-sm">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="rounded border-gray-300" />
            {label}
        </label>
    );
}
