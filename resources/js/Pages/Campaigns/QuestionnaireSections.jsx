import InputError from '@/Components/InputError';
import { INPUT, SELECT } from './format';

/**
 * A published questionnaire, rendered for a respondent (Phase 4.5).
 *
 * NOTHING RENDERED THIS BEFORE. CampaignController::respond() has eager-loaded
 * `campaign.questionnaire.sections.questions` since it was written and
 * risk/campaigns/respond.blade.php referenced none of it, so a campaign built
 * on a published questionnaire showed its respondents a list of register risks
 * instead. See App\Services\Campaigns\QuestionnaireAnswerSheet.
 *
 * ON NOT USING <DynamicForm/>. The phase prompt suggests reusing its field
 * renderers, and the classes below are its classes so the two look identical —
 * but DynamicForm's contract is FormSchemaPresenter's schema, where a field is
 * `mapped` to a column or lands in `configured_attributes`. A questionnaire
 * answer is neither: it is one of eight question types with its own per-question
 * `options`, and it is stored as JSON on a campaign response. Pushing it through
 * that component would mean inventing a fake schema on the server and unpicking
 * it again on submit. Same look, own renderer.
 */

/** The two question types nothing in this product can render. */
const UNSUPPORTED = ['matrix', 'file_upload'];

const YES_NO = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];

function optionsFor(question) {
    if (question.type === 'yes_no') {
        return YES_NO;
    }

    return (question.options ?? [])
        .filter((option) => option && typeof option === 'object')
        .map((option) => ({ value: String(option.value ?? ''), label: String(option.label ?? option.value ?? '') }));
}

function Answer({ question, value, onChange }) {
    const id = `question-${question.id}`;

    if (UNSUPPORTED.includes(question.type)) {
        // Stated, not silently dropped: a section that quietly omitted two of
        // its questions would produce a submission everyone believes is
        // complete. The builder no longer offers these types.
        return (
            <p className="mt-1 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                This question is a “{question.type.replace('_', ' ')}” type, which has no answer control in this product.
                It cannot be answered here — remove it from the questionnaire or replace it with another type.
            </p>
        );
    }

    if (question.type === 'free_text') {
        return (
            <textarea
                id={id}
                rows={3}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={INPUT}
            />
        );
    }

    if (question.type === 'numeric') {
        return (
            <input
                id={id}
                type="number"
                step="any"
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={INPUT}
            />
        );
    }

    const options = optionsFor(question);

    // A likert or rating question whose options were never set has nothing to
    // choose from; fall back to free text rather than an empty select.
    if (options.length === 0) {
        return (
            <input
                id={id}
                type="text"
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={INPUT}
            />
        );
    }

    return (
        <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={SELECT}>
            <option value="">Select</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
            ))}
        </select>
    );
}

export default function QuestionnaireSections({ questionnaire, answers = {}, errors = {}, onChange }) {
    if (!questionnaire) {
        return null;
    }

    return (
        <div className="space-y-6">
            <div className="bg-[#1A365D]/5 border border-[#1A365D]/15 rounded-xl p-5">
                <h2 className="text-base font-bold text-[#1A365D]">{questionnaire.title}</h2>
                {questionnaire.description && <p className="text-sm text-gray-600 mt-1">{questionnaire.description}</p>}
            </div>

            {questionnaire.sections.map((section) => (
                <div key={section.id} className="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 className="text-sm font-semibold text-[#1A365D]">{section.title}</h3>
                    {section.description && <p className="text-xs text-gray-500 mt-1">{section.description}</p>}

                    <div className="mt-4 space-y-5">
                        {section.questions.length === 0 && (
                            <p className="text-sm text-gray-400">This section has no questions.</p>
                        )}

                        {section.questions.map((question, index) => (
                            <div key={question.id}>
                                <label htmlFor={`question-${question.id}`} className="block text-sm text-gray-800">
                                    {index + 1}. {question.text}
                                    {question.isRequired && <span className="text-red-500"> *</span>}
                                </label>
                                {question.helpText && <p className="text-xs text-gray-400 mt-0.5">{question.helpText}</p>}

                                <Answer
                                    question={question}
                                    value={answers[question.id]}
                                    onChange={(value) => onChange(question.id, value)}
                                />

                                <InputError message={errors[`questionnaire_answers.${question.id}`]} className="mt-1" />
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}
