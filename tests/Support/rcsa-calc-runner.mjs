/**
 * Runs resources/js/lib/rcsa-calc.js over the truth table and reports what
 * disagrees.
 *
 * This exists because the repository has no JavaScript test runner and the
 * browser mirror still has to be held to the same 100 cases as the PHP engine.
 * RcsaCalculationTest::the_browser_mirror_computes_what_the_server_computes
 * pipes {methodology, cases} in on stdin and reads the report off stdout.
 *
 * Exits 1 on any mismatch so the PHP side can fail on the exit code alone if
 * the JSON is unreadable.
 */

import { calculate } from '../../resources/js/lib/rcsa-calc.js';

const FIELDS = [
  'inherentScore',
  'inherentLevel',
  'ceModifier',
  'residualScore',
  'residualLevel',
  'riskTreatment',
  'appetiteStatus',
  'actionPlanRequired',
  'aboveAppetite',
];

const read = () =>
  new Promise((resolve, reject) => {
    let buffer = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => {
      buffer += chunk;
    });
    process.stdin.on('end', () => resolve(buffer));
    process.stdin.on('error', reject);
  });

const { methodology, cases } = JSON.parse(await read());

const mismatches = [];
let compared = 0;

for (const testCase of cases) {
  const actual = calculate(
    {
      likelihood: testCase.likelihood,
      impact: testCase.impact,
      controlEffectiveness: testCase.controlEffectiveness,
      // Column H. Absent from the 100-case truth table, which predates §14 Q4
      // and runs in `single` mode where the category is ignored.
      riskCategory: testCase.riskCategory ?? null,
    },
    methodology,
  );

  for (const field of FIELDS) {
    // A case states only the fields it means to pin. The count of comparisons
    // actually made is reported back so the PHP side can assert it, rather
    // than a mistyped field name silently comparing nothing.
    if (!Object.prototype.hasOwnProperty.call(testCase.expected, field)) continue;

    compared += 1;

    const expected = testCase.expected[field];

    // Loose only across the integer/float line: the fixture writes a residual
    // of 0 where JavaScript computes 0. Everything else compares strictly.
    const equal =
      typeof expected === 'number' && typeof actual[field] === 'number'
        ? Math.abs(expected - actual[field]) < 1e-9
        : expected === actual[field];

    if (!equal) {
      mismatches.push({
        case: `L${testCase.likelihood} x I${testCase.impact}, ${testCase.controlEffectiveness}`,
        field,
        expected,
        actual: actual[field],
      });
    }
  }
}

process.stdout.write(JSON.stringify({ checked: cases.length, compared, mismatches }, null, 2));

if (mismatches.length > 0) {
  process.exit(1);
}
