<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Http\Controllers\Controller;
use App\Jobs\Bcms\DispatchAlertChunkJob;
use App\Jobs\Bcms\EscalateAlertRecipientsJob;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Presenters\Bcms\EmnsPresenter;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\EvidenceExport;
use App\Services\Bcms\Emns\RollCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The EMNS console and the live alert dashboard.
 *
 * THE DISPATCH ROUTE IS THE MOST DANGEROUS ENDPOINT IN THE PRODUCT and is
 * guarded four ways: the `bcms.alert.dispatch` permission, a recent MFA
 * assertion (criterion 13, enforced in the route not here), the dual-approval
 * state, and the simulation default on anything attached to an exercise. Three
 * of those can be satisfied by one person; the fourth cannot, by design.
 *
 * DISPATCH RETURNS BEFORE THE MESSAGES GO. It materialises the recipient list,
 * queues the chunks and redirects — criterion 2 gives thirty seconds to queue
 * ten thousand people, and no HTTP request should be holding that. The operator
 * lands on the live dashboard, which is where they want to be anyway.
 */
class AlertController extends Controller
{
    /** Recipients per queued job. See DispatchAlertChunkJob for the reasoning. */
    private const CHUNK = 200;

    public function __construct(
        private AlertService $alerts,
        private RollCallService $rollCall,
        private EvidenceExport $evidence,
        private EmnsPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.alert.view');

        return Inertia::render('Bcms/Emns/Index', array_merge(
            $this->presenter->console($request->user()),
            ['can' => $this->abilities($request)],
        ));
    }

    public function show(Request $request, Alert $alert): Response
    {
        Gate::authorize('bcms.alert.view');

        return Inertia::render('Bcms/Emns/Alert', array_merge(
            $this->presenter->alert($alert),
            ['can' => $this->abilities($request)],
        ));
    }

    /** The polled payload behind the live dashboard. */
    public function live(Request $request, Alert $alert): JsonResponse
    {
        Gate::authorize('bcms.alert.view');

        return response()->json($this->presenter->alert($alert));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.alert.compose');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:4000'],
            'template_id' => ['nullable', 'integer'],
            'severity' => ['required', 'string', 'in:'.implode(',', array_column(AlertSeverity::cases(), 'value'))],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:'.implode(',', array_column(ChannelKey::cases(), 'value'))],
            'audience_rule' => ['nullable', 'array'],
            'occurrence_id' => ['nullable', 'integer'],
            'response_required' => ['nullable', 'boolean'],
            'ack_window_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        if (filled($data['template_id'] ?? null) && AlertTemplate::query()->find($data['template_id']) === null) {
            throw ValidationException::withMessages(['template_id' => 'That template does not exist.']);
        }

        $alert = $this->alerts->compose($data, (int) $request->user()?->getKey());

        return redirect()->route('bcms.alerts.show', $alert);
    }

    /** Recipient count and cost, before anything is sent. */
    public function estimate(Request $request, Alert $alert): JsonResponse
    {
        Gate::authorize('bcms.alert.compose');

        return response()->json($this->alerts->estimate($alert));
    }

    public function approve(Request $request, Alert $alert): RedirectResponse
    {
        Gate::authorize('bcms.alert.approve');

        try {
            $this->alerts->approve($alert, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['approve' => $e->getMessage()]);
        }

        return back()->with('success', 'Approval recorded.');
    }

    /**
     * Send it.
     */
    public function dispatchAlert(Request $request, Alert $alert): RedirectResponse
    {
        Gate::authorize('bcms.alert.dispatch');

        /*
         * A LIVE ALERT ATTACHED TO AN EXERCISE NEEDS ITS OWN PERMISSION.
         * Standing rule 5 defaults exercise traffic to simulation; turning that
         * off means a drill will reach a real branch, and the person who may
         * compose and dispatch routine alerts is not automatically the person
         * who may do that.
         */
        if ($alert->occurrence_id !== null && ! $alert->is_simulation) {
            Gate::authorize('bcms.alert.life_safety');
        }

        if ($this->alerts->isHeldByQuietHours($alert)) {
            throw ValidationException::withMessages([
                'dispatch' => 'This alert\'s severity respects quiet hours and it is currently quiet hours. '
                    .'Raise the severity if it genuinely cannot wait — life-safety and critical traffic '
                    .'is never held.',
            ]);
        }

        try {
            $contacts = $this->alerts->release($alert, (int) $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            // The refused attempt is recorded (criterion 4): an alert somebody
            // tried to send without a second authoriser is exactly the event an
            // auditor asks to see.
            $alert->recordAudit('alert.dispatch_refused', [
                'reason' => $e->getMessage(),
                'attempted_by' => $request->user()?->name,
            ]);

            throw ValidationException::withMessages(['dispatch' => $e->getMessage()]);
        }

        $queue = $alert->severity->queue();

        AlertRecipient::query()
            ->where('alert_id', $alert->getKey())
            ->select('id')
            ->chunkById(self::CHUNK, function ($chunk) use ($alert, $queue): void {
                DispatchAlertChunkJob::dispatch(
                    (int) $alert->getKey(),
                    (int) $alert->organization_id,
                    $chunk->pluck('id')->map(fn ($id) => (int) $id)->all(),
                )->onQueue($queue);
            });

        if ($alert->escalation_enabled) {
            EscalateAlertRecipientsJob::dispatch((int) $alert->getKey(), (int) $alert->organization_id)
                ->onQueue($queue)
                ->delay(now()->addMinutes(max(1, (int) ($alert->ack_window_minutes ?: 30))));
        }

        return redirect()->route('bcms.alerts.show', $alert)->with(
            'success',
            $alert->is_simulation
                ? 'Simulation released to '.$contacts->count().' recipients. Nothing left the building.'
                : 'Dispatched to '.$contacts->count().' recipients.',
        );
    }

    public function rollCall(Request $request, Alert $alert): JsonResponse
    {
        Gate::authorize('bcms.alert.view');

        return response()->json($this->rollCall->summary($alert));
    }

    /** Record somebody's response from inside the app. */
    public function respond(Request $request, Alert $alert, AlertRecipient $recipient): RedirectResponse
    {
        Gate::authorize('bcms.alert.view');

        abort_unless((int) $recipient->alert_id === (int) $alert->getKey(), 404);

        $data = $request->validate([
            'response' => ['nullable', 'string', 'in:safe,needs_help,not_on_site'],
            'text' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rollCall->record($recipient, $data['response'] ?? null, $data['text'] ?? null, 'in_app');

        return back()->with('success', 'Response recorded.');
    }

    public function evidence(Request $request, Alert $alert): StreamedResponse
    {
        Gate::authorize('bcms.report.export');

        $preamble = $this->evidence->preamble($alert);
        $columns = $this->evidence->columns();
        $rows = $this->evidence->rows($alert);

        return response()->streamDownload(function () use ($preamble, $columns, $rows): void {
            $out = fopen('php://output', 'wb');

            foreach ($preamble as $line) {
                fputcsv($out, $line);
            }

            fputcsv($out, $columns);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $this->evidence->filename($alert), ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'compose' => $user?->can('bcms.alert.compose') === true,
            'dispatch' => $user?->can('bcms.alert.dispatch') === true,
            'approve' => $user?->can('bcms.alert.approve') === true,
            'life_safety' => $user?->can('bcms.alert.life_safety') === true,
            'templates' => $user?->can('bcms.alert.template.manage') === true,
            'export' => $user?->can('bcms.report.export') === true,
        ];
    }
}
