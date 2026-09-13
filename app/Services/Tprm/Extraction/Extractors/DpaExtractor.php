<?php

namespace App\Services\Tprm\Extraction\Extractors;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Tprm\Extraction\Extractor;

/**
 * A data processing agreement, checked element by element against GAID Article
 * 34(2)(a)–(t).
 *
 * TWENTY ELEMENTS, ALWAYS TWENTY. The verdict set is fixed here rather than
 * taken from whatever the model returned, so a model that answered fifteen
 * produces fifteen verdicts and five `absent` — never a report that looks
 * complete because the missing elements were quietly dropped. A DPA gap
 * analysis that silently omits the elements it could not find is precisely the
 * artefact a supervisor would take apart.
 *
 * `partial` IS THE DEFAULT DIRECTION OF DOUBT, and the prompt says so. An
 * element is present only if the agreement carries the obligation; addressing
 * the subject without the obligation — a breach notification duty with no
 * timeframe where the law fixes one, an audit right exercisable only with the
 * processor's consent — is partial. A reviewer correcting a partial upward has
 * read the clause; a reviewer accepting a wrong "present" has not.
 *
 * The element titles here are our own summaries of the Article's requirements,
 * written for a reviewer's screen. They are not the Article's text.
 */
class DpaExtractor implements Extractor
{
    /**
     * GAID Art. 34(2)(a)–(t).
     *
     * @var array<string, string>
     */
    public const ELEMENTS = [
        'a' => 'Subject matter and duration of the processing',
        'b' => 'Nature and purpose of the processing',
        'c' => 'Type of personal data',
        'd' => 'Categories of data subjects',
        'e' => 'Obligations and rights of the data controller',
        'f' => 'Processing only on the controller\'s documented instructions',
        'g' => 'Confidentiality obligations on personnel with access',
        'h' => 'Security measures appropriate to the risk',
        'i' => 'Conditions for engaging a sub-processor',
        'j' => 'Flow-down of the same obligations to sub-processors',
        'k' => 'Assistance with data subject rights requests',
        'l' => 'Assistance with security, breach notification and impact assessments',
        'm' => 'Breach notification to the controller, with a timeframe',
        'n' => 'Deletion or return of personal data at the end of the service',
        'o' => 'Making available information necessary to demonstrate compliance',
        'p' => 'Allowing and contributing to audits and inspections',
        'q' => 'Immediate notification of an instruction that infringes the law',
        'r' => 'Cross-border transfer conditions and lawful basis',
        's' => 'Record-keeping of processing activities',
        't' => 'Liability and indemnity allocation between the parties',
    ];

    /** @var list<string> */
    public const VERDICTS = ['present', 'partial', 'absent'];

    public function extractor(): DocumentExtractor
    {
        return DocumentExtractor::Dpa;
    }

    public function schema(): array
    {
        return [
            'agreement_date' => ['type' => 'date'],
            'parties' => ['type' => 'object', 'required' => true],
            'governing_law' => ['type' => 'string'],
            'sub_processors_listed' => ['type' => 'list', 'required' => true],
            'elements' => ['type' => 'object', 'required' => true],
        ];
    }

    public function normalise(array $raw): array
    {
        $returned = is_array($raw['elements'] ?? null) ? $raw['elements'] : [];
        $elements = [];

        foreach (self::ELEMENTS as $key => $title) {
            $entry = is_array($returned[$key] ?? null) ? $returned[$key] : [];
            $verdict = $entry['verdict'] ?? null;

            $elements[$key] = [
                'reference' => 'GAID Art. 34(2)('.$key.')',
                'title' => $title,
                // An element the model did not answer is absent, not unknown.
                // "We could not tell" is not a state a gap report can carry:
                // the reviewer has to be shown something to correct.
                'verdict' => in_array($verdict, self::VERDICTS, true) ? $verdict : 'absent',
                'clause_reference' => $this->str($entry['clause_reference'] ?? null),
                'quote' => $this->str($entry['quote'] ?? null),
            ];
        }

        $parties = is_array($raw['parties'] ?? null) ? $raw['parties'] : [];

        return [
            'agreement_date' => $raw['agreement_date'] ?? null,
            'parties' => [
                'controller' => $this->str($parties['controller'] ?? null),
                'processor' => $this->str($parties['processor'] ?? null),
            ],
            'governing_law' => $this->str($raw['governing_law'] ?? null),
            'sub_processors_listed' => array_values(array_filter(
                (array) ($raw['sub_processors_listed'] ?? []),
                'is_string'
            )),
            'elements' => $elements,
            'summary' => $this->summarise($elements),
        ];
    }

    /**
     * The headline a reviewer reads first.
     *
     * @param  array<string, array<string, mixed>>  $elements
     * @return array<string, int|bool>
     */
    private function summarise(array $elements): array
    {
        $counts = ['present' => 0, 'partial' => 0, 'absent' => 0];

        foreach ($elements as $element) {
            $counts[$element['verdict']]++;
        }

        return $counts + [
            'total' => count(self::ELEMENTS),
            // The compliance question the NDPA actually asks: not "how many"
            // but "are any missing". Nineteen of twenty is not compliance.
            'complete' => $counts['present'] === count(self::ELEMENTS),
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
