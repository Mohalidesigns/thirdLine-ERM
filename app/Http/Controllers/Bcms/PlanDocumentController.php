<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanActivation;
use App\Services\Bcms\Plans\OfflineBundleBuilder;
use App\Services\Bcms\Plans\PlanAcknowledgementService;
use App\Services\Bcms\Plans\PlanActivationService;
use App\Services\Bcms\Plans\PlanDocumentRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Distributing a plan: the PDF, the offline bundle, acknowledgement and
 * activation.
 *
 * THE BUNDLE IS GATED ON `bcms.contact.export`, NOT `bcms.plan.view`. It carries
 * mobile numbers off the platform onto somebody's phone, which is a bulk
 * personal-data export under the NDPA whatever the button is called. The PDF is
 * gated on plan view because a plan is a document people are meant to have; the
 * bundle exists precisely so that the data leaves, and the permission has to
 * reflect that.
 */
class PlanDocumentController extends Controller
{
    public function __construct(
        private PlanDocumentRenderer $renderer,
        private OfflineBundleBuilder $bundles,
        private PlanAcknowledgementService $acknowledgements,
        private PlanActivationService $activations,
    ) {}

    /** The printed plan. */
    public function pdf(Request $request, Plan $plan): Response
    {
        Gate::authorize('bcms.plan.view');

        $pdf = $this->renderer->pdf($plan, $request->user()?->name);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->renderer->filename($plan).'"',
        ]);
    }

    /** Build the offline bundle and stamp the plan. */
    public function generateBundle(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.contact.export');

        try {
            $bundle = $this->bundles->generate($plan, $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $problems = $this->bundles->validate($bundle);

        if ($problems !== []) {
            // Validated on the way out, not only on the way in. A bundle that
            // fails the viewer's expectations is worth catching here, where
            // somebody is looking at a screen, rather than on a phone during
            // an event.
            return back()->with('error', 'The bundle was generated but does not validate: '
                .implode(' ', $problems));
        }

        return back()->with('success', sprintf(
            'Offline bundle generated: %d sections%s.',
            count($bundle['sections']),
            $bundle['contains_personal_data'] ? ', including contact details' : '',
        ));
    }

    /** The bundle itself, for the PWA to cache. */
    public function bundle(Request $request, Plan $plan): JsonResponse
    {
        Gate::authorize('bcms.contact.export');

        try {
            $bundle = $this->bundles->stored($plan) ?? $this->bundles->build($plan);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($bundle);
    }

    /* ------------------------------------------------------------------ */
    /*  Acknowledgement — ISO 22301 clause 7.4 */
    /* ------------------------------------------------------------------ */

    /**
     * Record that this reader has read this plan.
     *
     * NO EXTRA PERMISSION. Anybody who may see a plan may say they have read
     * it, and requiring a permission to acknowledge would mean the people the
     * plan is distributed to could not produce the evidence that it was.
     */
    public function acknowledge(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.view');

        try {
            $this->acknowledgements->acknowledge($plan, $request->user(), $request->ip());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded. Thank you.');
    }

    /** The clause 7.4 evidence export. */
    public function acknowledgements(Request $request, Plan $plan): StreamedResponse
    {
        Gate::authorize('bcms.report.export');

        $rows = $this->acknowledgements->evidence($plan);

        $columns = [
            'plan' => 'Plan', 'version' => 'Version', 'period_year' => 'Year',
            'name' => 'Name', 'role' => 'Role', 'acknowledged_at' => 'Acknowledged at',
            'statement' => 'Statement', 'ip_address' => 'IP address', 'iso_clause_ref' => 'ISO clause',
        ];

        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)));
            }

            fclose($out);
        }, 'plan-acknowledgements-'.$plan->uuid.'.csv', ['Content-Type' => 'text/csv']);
    }

    /* ------------------------------------------------------------------ */
    /*  Activation */
    /* ------------------------------------------------------------------ */

    public function activate(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.activate');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'is_exercise' => ['nullable', 'boolean'],
        ]);

        try {
            $this->activations->activate(
                $plan,
                $request->user(),
                $data['reason'],
                (bool) ($data['is_exercise'] ?? false),
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', ($data['is_exercise'] ?? false)
            ? 'Plan activated for an exercise. It is recorded as a rehearsal, not as a live activation.'
            : 'Plan activated.');
    }

    public function deactivate(Request $request, Plan $plan, PlanActivation $activation): RedirectResponse
    {
        Gate::authorize('bcms.plan.activate');

        if ((int) $activation->plan_id !== (int) $plan->getKey()) {
            abort(404);
        }

        try {
            $this->activations->deactivate($activation);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Plan stood down.');
    }
}
