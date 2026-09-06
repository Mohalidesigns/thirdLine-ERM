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

for (const testCase of cases) {
  const actual = calculate(
    {
      likelihood: testCase.likelihood,
      impact: testCase.impact,
      controlEffectiveness: testCase.controlEffectiveness,
    },
    methodology,
  );

  for (const field of FIELDS) {
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

process.stdout.write(JSON.stringify({ checked: cases.length, mismatches }, null, 2));

if (mismatches.length > 0) {
  process.exit(1);
}
