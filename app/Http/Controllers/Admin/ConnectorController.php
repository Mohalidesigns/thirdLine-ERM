<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunConnectorJob;
use App\Models\Connector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

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
        return view('admin.connectors.index', [
            'connectors' => Connector::withCount('runs')->with('creator')->latest()->get(),
            'drivers' => $this->drivers(),
            'schedules' => config('connectors.schedules'),
        ]);
    }

    public function show(Connector $connector)
    {
        $this->authorizeTenant($connector);

        return view('admin.connectors.show', [
            'connector' => $connector,
            'driver' => $this->drivers()[$connector->type] ?? null,
            'runs' => $connector->runs()->with('triggerer')->paginate(25),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:'.implode(',', array_keys((array) config('connectors.drivers'))),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'config' => 'nullable|array',
            'credentials' => 'nullable|array',
            'field_map' => 'nullable|array',
            'schedule' => 'nullable|string|in:'.implode(',', array_keys((array) config('connectors.schedules'))),
        ]);

        $connector = Connector::create($validated + [
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.connectors.show', $connector)
            ->with('success', 'Connector created. Test the connection, then run it once — the first run is a '
                .'dry run and produces a reconciliation rather than writing anything.');
    }

    public function update(Request $request, Connector $connector)
    {
        $this->authorizeTenant($connector);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'config' => 'nullable|array',
            'field_map' => 'nullable|array',
            'schedule' => 'nullable|string|in:'.implode(',', array_keys((array) config('connectors.schedules'))),
            'is_active' => 'nullable|boolean',
        ]);

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
        $this->authorizeTenant($connector);

        $connector->delete();

        return redirect()->route('admin.connectors.index')
            ->with('success', 'Connector removed. Its run history is kept, and the values it collected stay where they are.');
    }

    /**
     * Reach the source with the settings as they stand, changing nothing.
     */
    public function test(Connector $connector)
    {
        $this->authorizeTenant($connector);

        $class = config('connectors.drivers.'.$connector->type);

        if ($class === null) {
            return back()->with('error', "There is no driver registered for [{$connector->type}].");
        }

        $result = app($class)->testConnection($connector);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function run(Request $request, Connector $connector)
    {
        $this->authorizeTenant($connector);

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

    private function authorizeTenant(Connector $connector): void
    {
        abort_unless($connector->organization_id === TenantContext::organizationId(), 403);
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
