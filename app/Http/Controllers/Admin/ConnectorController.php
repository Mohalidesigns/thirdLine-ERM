<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Integrations\StoreConnectorRequest;
use App\Http\Requests\Admin\Integrations\UpdateConnectorRequest;
use App\Jobs\RunConnectorJob;
use App\Models\Connector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * WP-07 TASK 4 — configuring connectors and reading their run history.
 *
 * The run history is the part that earns its keep. "Read 412, wrote 0" is the
 * message an operator needs, and a green tick over a sync that imported nothing
 * is how a KRI quietly stops being measured — which nobody notices until the
 * breach that was never flagged.
 */
class ConnectorController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Connector::class);

        return Inertia::render('Admin/Connectors/Index', [
            'connectors' => Connector::withCount('runs')->with('creator:id,name')->latest()->get()
                ->map(fn (Connector $connector) => $this->present($connector))
                ->values()->all(),
            'drivers' => $this->drivers(),
            'schedules' => (array) config('connectors.schedules'),
            'canManage' => Gate::allows('create', Connector::class),
        ]);
    }

    public function show(Connector $connector)
    {
        Gate::authorize('view', $connector);

        $runs = $connector->runs()->with('triggerer:id,name')->latest()->paginate(25);

        return Inertia::render('Admin/Connectors/Show', [
            'connector' => $this->present($connector),
            'driver' => $this->drivers()[$connector->type] ?? null,
            'schedules' => (array) config('connectors.schedules'),
            'runs' => $runs->through(fn ($run) => array_merge($run->only([
                'id', 'status', 'trigger', 'dry_run', 'records_read', 'records_written', 'records_skipped',
            ]), [
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                // "Read 412, wrote 0" is the message an operator needs: a green
                // tick over a sync that imported nothing is how a KRI quietly
                // stops being measured.
                'errors' => array_values((array) ($run->errors ?? [])),
                'reconciliation' => (array) ($run->reconciliation ?? []),
                'triggered_by' => $run->getRelationValue('triggerer')?->name,
            ])),
            'canManage' => Gate::allows('update', $connector),
            'canRun' => Gate::allows('run', $connector),
        ]);
    }

    public function store(StoreConnectorRequest $request)
    {
        $validated = $request->validated();

        $connector = Connector::create($validated + [
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.connectors.show', $connector)
            ->with('success', 'Connector created. Test the connection, then run it once — the first run is a '
                .'dry run and produces a reconciliation rather than writing anything.');
    }

    public function update(UpdateConnectorRequest $request, Connector $connector)
    {
        $validated = $request->validated();
        unset($validated['credentials']);

        // Credentials are only overwritten when something was actually typed:
        // the form renders them blank (they are never sent to the browser), so
        // saving the page must not wipe them.
        $credentials = array_filter((array) $request->input('credentials', []), fn ($v) => $v !== null && $v !== '');

        $connector->update($validated + [
            'is_active' => $request->boolean('is_active'),
            'credentials' => $credentials === []
                ? $connector->credentials
                : array_merge((array) $connector->credentials, $credentials),
        ]);

        // Re-enabling clears the failure counter, otherwise a connector fixed
        // after ten failures is switched straight off again by the eleventh.
        if ($connector->is_active) {
            $connector->forceFill(['consecutive_failures' => 0])->save();
        }

        return back()->with('success', 'Connector updated.');
    }

    public function destroy(Connector $connector)
    {
        Gate::authorize('delete', $connector);

        $connector->delete();

        return redirect()->route('admin.connectors.index')
            ->with('success', 'Connector removed. Its run history is kept, and the values it collected stay where they are.');
    }

    /**
     * Reach the source with the settings as they stand, changing nothing.
     */
    public function test(Connector $connector)
    {
        Gate::authorize('test', $connector);

        $class = config('connectors.drivers.'.$connector->type);

        if ($class === null) {
            return back()->with('error', "There is no driver registered for [{$connector->type}].");
        }

        $result = app($class)->testConnection($connector);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function run(Request $request, Connector $connector)
    {
        Gate::authorize('run', $connector);

        $dryRun = $request->boolean('dry_run')
            || ($connector->last_run_at === null && config('connectors.dry_run_first', true));

        $jobRun = RunConnectorJob::track(
            label: 'Connector: '.$connector->name.($dryRun ? ' (dry run)' : ''),
            subject: $connector,
            organizationId: $connector->organization_id,
            creator: $request->user(),
        );

        RunConnectorJob::dispatch($connector->id, $dryRun, 'manual', $request->user()->id, $jobRun->id);

        return back()->with('success', $dryRun
            ? 'Dry run queued. It will report what it would have written without writing anything.'
            : 'Run queued. Its result appears in the run history.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * A connector as the screen may see it.
     *
     * CREDENTIALS ARE NEVER SENT. The form renders them blank and only
     * overwrites what was typed, so a save cannot wipe what it was never shown.
     *
     * @return array<string, mixed>
     */
    private function present(Connector $connector): array
    {
        return array_merge($connector->only([
            'id', 'type', 'name', 'description', 'schedule', 'is_active',
            'consecutive_failures', 'disabled_reason', 'runs_count',
        ]), [
            'config' => (array) ($connector->config ?? []),
            'field_map' => (array) ($connector->field_map ?? []),
            'has_credentials' => ! empty($connector->credentials),
            'last_run_at' => $connector->last_run_at?->toIso8601String(),
            'creator' => $connector->getRelationValue('creator')?->name,
        ]);
    }

    /**
     * Each registered driver's self-description, which is what the admin form
     * is built from — so a new connector type gets a UI without anybody writing
     * one.
     *
     * @return array<string, array<string, mixed>>
     */
    private function drivers(): array
    {
        $drivers = [];

        foreach ((array) config('connectors.drivers') as $type => $class) {
            if (class_exists($class)) {
                $drivers[$type] = app($class)->describe();
            }
        }

        return $drivers;
    }
}
