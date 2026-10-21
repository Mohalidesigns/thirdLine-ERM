<?php

namespace Tests\Feature\Migration;

use App\Jobs\DeliverWebhookJob;
use App\Models\Risk;
use App\Observers\WebhookEventObserver;
use App\Observers\WorkflowTriggerObserver;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\MigrationWindow;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * DEVELOPMENT_STANDARD §16: an observer that turns a model save into an
 * outward event returns early while `MigrationWindow::isOpen()`.
 *
 * THE DEFECT. 02cf6df: the first production upgrade stopped at migration 25 of
 * 124 because `seed_units_and_period_calendars` saved `Period`s and
 * `WebhookEventObserver` queried `webhook_subscriptions`, a table twenty-one
 * migrations away; then `WorkflowTriggerObserver` fell the same way on
 * `workflow_definitions.trigger`. CI never saw it — a fresh database has no
 * organisations for a backfill to iterate — so nothing but a guard like this
 * one stops the NEXT outward observer from repeating it.
 *
 * WHAT IS ENUMERATED. Every listener the Eloquent dispatcher holds for an
 * `eloquent.*` event, after every model under app/ and packages/ has been
 * booted (so `#[ObservedBy]` and trait-registered hooks are present, not only
 * the providers' `::observe()` calls). Listeners whose code lives in vendor/
 * are out of scope. Two shapes:
 *   - an observer class, registered as `Class@method`. Only the methods that
 *     are actually registered are checked — those are what a save runs;
 *   - a closure (`static::created(fn ...)` in a model or trait).
 * NOT enumerated, so guard them by hand (DEVELOPMENT_STANDARD §16): custom
 * Eloquent events from `$dispatchesEvents` (they reach ordinary listeners, not
 * `eloquent.*`), and observers or listeners registered conditionally (behind a
 * flag that is off under test) or outside a booted provider (in a command, job
 * or service at runtime, or a deferred provider).
 *
 * RUNNING IT. Never start two `artisan test` runs on one database, and never
 * kill only the `artisan test` parent — the `phpunit` child survives and keeps
 * migrating. See §16.
 *
 * THE "OUTWARD-FACING" HEURISTIC. Static, over the observer's own source with
 * comments stripped (so a docblock that merely mentions a webhook is not a
 * hit), plus reflection over its constructor. A listener is outward if ANY of:
 *   1. its constructor injects a type that is, extends or implements one of
 *      OUTWARD_TYPES (webhook dispatch, workflow start, notification service,
 *      the bus, queue, mailer, notification dispatcher, HTTP client, event
 *      dispatcher, broadcaster), or lives in one of OUTWARD_NAMESPACES (jobs,
 *      notifications, mailables, webhooks, workflow, integrations);
 *   2. its source matches one of OUTWARD_PATTERNS: a facade call on Bus, Queue,
 *      Mail, Http, Notification, Event or Broadcast; `->notify(`; a global
 *      `event(`, `dispatch(`, `dispatch_sync(` or `broadcast(`; a static
 *      `::dispatch(` / `::dispatchSync(` on a job or event; a reference to
 *      WebhookDispatcher, WorkflowTriggerService or NotificationService; a
 *      container lookup by alias — `app('events'|'bus'|'queue'|'mailer'|
 *      'notifications'|…)`, `resolve(...)`, `->make(...)`, `app()['…']`;
 *      `GuzzleHttp\`; a `curl_*` / socket call; or `file_get_contents(` /
 *      `fopen(` (and kin) on an `http(s)://` literal;
 *   3. a class it NAMES in its source — `app(X::class)`, `resolve(X::class)`,
 *      `->make(X::class)`, `new X`, `X::` — resolves, through the file's own
 *      `use` imports and namespace, to one of OUTWARD_TYPES or
 *      OUTWARD_NAMESPACES. This is what catches `app(Dispatcher::class)` and
 *      an imported `new Client` that no literal pattern can see.
 * It is deliberately over-inclusive: a false positive costs one `if` at the
 * top of a method; a false negative costs a production upgrade. It is also
 * shallow — it reads the listener, not what the listener calls. A listener
 * that hides its dispatch behind an innocently named service is not caught;
 * name that service's namespace in OUTWARD_NAMESPACES or its type in
 * OUTWARD_TYPES when you write it.
 *
 * WHAT COUNTS AS GUARDED.
 *   - observer class: the class references `MigrationWindow`, and EVERY
 *     registered event method's FIRST statement is exactly
 *     `if (<window>->isOpen()) { return; }`, where <window> is a `$this->prop`
 *     whose declared type is `MigrationWindow`, or `app(MigrationWindow::class)`
 *     / `resolve(MigrationWindow::class)`. First, not merely present: the point
 *     of A3 was that `updated()` built its payload before the check — a
 *     migration must cost the observer nothing. Exactly, not merely
 *     containing `isOpen()`: a negated guard (`! …->isOpen()`), a comparison
 *     (`false === …`), a conjunction, or `isOpen()` on some other object
 *     returns early OUTSIDE migrations and runs INSIDE them — the inverse of
 *     the rule — and is rejected.
 *   - closure: its body references `MigrationWindow`.
 *
 * WHY NOT AN ALLOWLIST ALONE. KNOWN_OUTWARD names the two observers that exist
 * today, and the heuristic must classify both as outward (so it cannot rot
 * into catching nothing). But the failure mode is a NEW observer nobody added
 * to a list, so the main assertion is over everything the heuristic finds —
 * and the scratch-observer tests below prove it fails for exactly that case.
 */
class OutwardObserversHonourMigrationWindowTest extends TestCase
{
    /** The outward observers that exist today. The heuristic must find both. */
    private const KNOWN_OUTWARD = [
        WebhookEventObserver::class,
        WorkflowTriggerObserver::class,
    ];

    /** Injecting any of these (or a subtype) makes an observer outward. */
    private const OUTWARD_TYPES = [
        WebhookDispatcher::class,
        \App\Services\Workflow\WorkflowTriggerService::class,
        \App\Services\NotificationService::class,
        \Illuminate\Contracts\Bus\Dispatcher::class,
        \Illuminate\Contracts\Queue\Queue::class,
        \Illuminate\Contracts\Queue\Factory::class,
        \Illuminate\Contracts\Events\Dispatcher::class,
        \Illuminate\Contracts\Mail\Mailer::class,
        \Illuminate\Contracts\Mail\Factory::class,
        \Illuminate\Contracts\Notifications\Dispatcher::class,
        \Illuminate\Contracts\Notifications\Factory::class,
        \Illuminate\Contracts\Broadcasting\Factory::class,
        \Illuminate\Http\Client\Factory::class,
    ];

    /** Injecting anything from these namespaces makes an observer outward. */
    private const OUTWARD_NAMESPACES = [
        'App\\Jobs\\',
        'App\\Notifications\\',
        'App\\Mail\\',
        'App\\Services\\Webhooks\\',
        'App\\Services\\Workflow\\',
        'App\\Services\\Integrations\\',
        'GuzzleHttp\\',
        'Symfony\\Component\\HttpClient\\',
        'Illuminate\\Http\\Client\\',
        'Illuminate\\Mail\\',
        'Illuminate\\Notifications\\',
        'Illuminate\\Broadcasting\\',
    ];

    /** Source patterns (comments stripped) that make a listener outward. */
    private const OUTWARD_PATTERNS = [
        'facade dispatch' => '/\b(?:Bus|Queue|Mail|Http|Notification|Event|Broadcast)::/',
        '->notify()' => '/->notify(?:Now)?\s*\(/',
        'global helper' => '/(?<![\w$>:\\\\])(?:event|dispatch|dispatch_sync|broadcast)\s*\(/',
        'static dispatch' => '/::dispatch(?:Sync|If|Unless|AfterResponse)?\s*\(/',
        'outward service' => '/\b(?:WebhookDispatcher|WorkflowTriggerService|NotificationService)\b/',
        'container alias' => '/(?:\b(?:app|resolve|make|makeWith)\s*\(\s*|(?:\bapp\(\)|->app|\$app)\s*\[\s*)[\'"](?:events|bus|queue|queue\.connection|mailer|mail\.manager|notifications?|broadcast(?:\.manager)?|http)[\'"]/',
        'HTTP client' => '/\bGuzzleHttp\\\\/',
        'raw network call' => '/\b(?:curl_[a-z_]+|fsockopen|pfsockopen|stream_socket_client|socket_connect)\s*\(/',
        'URL stream' => '/\b(?:file_get_contents|fopen|readfile|get_headers|copy)\s*\(\s*[\'"](?:https?|ftp):\/\//i',
    ];

    /** Where a class NAME is taken from the source and resolved (signal 3). */
    private const CLASS_REFERENCES = [
        '/\b(?:app|resolve|make|makeWith)\s*\(\s*(\\\\?[A-Za-z_][\w\\\\]*)::class/',
        '/\bnew\s+(\\\\?[A-Za-z_][\w\\\\]*)/',
        '/(?<![\w\\\\$])(\\\\?[A-Z][\w\\\\]*)::/',
    ];

    /** @var array<string, array{namespace: string, imports: array<string, string>}> */
    private array $fileContext = [];

    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_outward_model_observer_returns_early_while_the_migration_window_is_open(): void
    {
        $listeners = $this->modelListeners();

        $this->assertNotEmpty($listeners, 'Enumeration found no model listeners at all — the scan is broken, not clean.');

        $violations = $this->violations($listeners);

        $this->assertSame([], $violations,
            "These listeners turn a model save into an outward event but do not return early while\n"
            ."MigrationWindow::isOpen(). A data migration that saves the model will fire them on an\n"
            ."upgrading database — 02cf6df stopped a production upgrade at migration 25 of 124 this way.\n"
            ."Put `if (\$this->migrations->isOpen()) { return; }` first in each method (DEVELOPMENT_STANDARD §16):\n  - "
            .implode("\n  - ", $violations));
    }

    #[Test]
    public function the_heuristic_still_recognises_the_known_outward_observers(): void
    {
        $observers = collect($this->modelListeners())
            ->where('kind', 'observer')
            ->pluck('class')
            ->unique()
            ->values();

        foreach (self::KNOWN_OUTWARD as $class) {
            $this->assertContains($class, $observers->all(),
                "{$class} is no longer registered against any model. If that is deliberate, remove it from KNOWN_OUTWARD.");
            $this->assertNotSame([], $this->outwardReasons(new ReflectionClass($class)),
                "The heuristic no longer classifies {$class} as outward, so it would no longer catch a missing guard on it either.");
        }
    }

    #[Test]
    public function a_new_unguarded_observer_that_injects_a_webhook_dispatcher_fails_the_guard(): void
    {
        $scratch = new class(app(WebhookDispatcher::class))
        {
            public function __construct(private WebhookDispatcher $dispatcher) {}

            public function created(Model $model): void
            {
                $this->dispatcher->dispatch('risk.created', $model, [], 1);
            }
        };

        Risk::observe($scratch);

        $this->assertViolationFor($scratch::class, 'created');
    }

    #[Test]
    public function a_new_unguarded_observer_that_queues_a_job_fails_the_guard(): void
    {
        $scratch = new class
        {
            public function updated(Model $model): void
            {
                Bus::dispatch(new DeliverWebhookJob(0));
            }
        };

        Risk::observe($scratch);

        $this->assertViolationFor($scratch::class, 'updated');
    }

    #[Test]
    public function a_guard_that_is_not_the_first_statement_still_fails(): void
    {
        // The A3 shape: the work happens, THEN the window is consulted.
        $scratch = new class(app(MigrationWindow::class))
        {
            public function __construct(private MigrationWindow $migrations) {}

            public function deleted(Model $model): void
            {
                $payload = $model->toArray();

                if ($this->migrations->isOpen()) {
                    return;
                }

                event('scratch.deleted', $payload);
            }
        };

        Risk::observe($scratch);

        $this->assertViolationFor($scratch::class, 'deleted');
    }

    #[Test]
    public function an_unguarded_outward_closure_fails_the_guard(): void
    {
        Risk::created(function (Risk $risk): void {
            event('scratch.closure', [$risk->getKey()]);
        });

        $closures = collect($this->violations($this->modelListeners()))
            ->filter(fn (string $v) => str_contains($v, 'closure') && str_contains($v, basename(__FILE__)));

        $this->assertCount(1, $closures, 'An outward closure registered without the guard must be reported.');
    }

    #[Test]
    public function an_unguarded_closure_that_dispatches_through_the_container_fails_the_guard(): void
    {
        Risk::updated(function (Risk $risk): void {
            app(BusDispatcher::class)->dispatch($risk);
        });

        $closures = collect($this->violations($this->modelListeners()))
            ->filter(fn (string $v) => str_contains($v, 'closure on eloquent.updated') && str_contains($v, basename(__FILE__)));

        $this->assertCount(1, $closures, 'A closure that resolves an outward type from the container must be reported.');
    }

    #[Test]
    public function a_correctly_guarded_scratch_observer_passes(): void
    {
        $scratch = new class(app(MigrationWindow::class), app(WebhookDispatcher::class))
        {
            public function __construct(
                private MigrationWindow $migrations,
                private WebhookDispatcher $dispatcher,
            ) {}

            public function created(Model $model): void
            {
                if ($this->migrations->isOpen()) {
                    return;
                }

                $this->dispatcher->dispatch('risk.created', $model, [], 1);
            }

            public function updated(Model $model): void
            {
                if (app(MigrationWindow::class)->isOpen()) {
                    return;
                }

                event('scratch.updated', [$model->getKey()]);
            }

            public function deleted(Model $model): void
            {
                if (resolve(\App\Support\MigrationWindow::class)->isOpen()) {
                    return;
                }

                app('events')->dispatch('scratch.deleted', [$model->getKey()]);
            }
        };

        Risk::observe($scratch);

        $mine = collect($this->violations($this->modelListeners()))
            ->filter(fn (string $v) => str_contains($v, $scratch::class));

        $this->assertSame([], $mine->all(), 'A guard that is the first statement must satisfy the check.');
    }

    /**
     * QA's false negatives on the first cut: each of these reaches the outside
     * world without naming a facade, a helper or an outward service, and each
     * passed the guard. One scratch observer per shape; each must now fail.
     *
     * @return array<string, array{Closure(): object}>
     */
    public static function indirectDispatchShapes(): array
    {
        return [
            "app('events')->dispatch()" => [fn () => new class
            {
                public function created(Model $model): void
                {
                    app('events')->dispatch('scratch.created', [$model]);
                }
            }],
            "app()['events'] array access" => [fn () => new class
            {
                public function created(Model $model): void
                {
                    app()['events']->dispatch('scratch.created', [$model]);
                }
            }],
            'app(Bus\Dispatcher::class) through an import alias' => [fn () => new class
            {
                public function created(Model $model): void
                {
                    app(BusDispatcher::class)->dispatch($model);
                }
            }],
            'resolve(fully qualified Mailer::class)' => [fn () => new class
            {
                public function created(Model $model): void
                {
                    resolve(\Illuminate\Contracts\Mail\Mailer::class)->raw('scratch', fn () => null);
                }
            }],
            "app('queue')->push()" => [fn () => new class
            {
                public function created(Model $model): void
                {
                    app('queue')->push('scratch-job', ['id' => $model->getKey()]);
                }
            }],
            "app('mailer')->raw()" => [fn () => new class
            {
                public function created(Model $model): void
                {
                    app('mailer')->raw('scratch', fn () => null);
                }
            }],
            "app('notifications')" => [fn () => new class
            {
                public function created(Model $model): void
                {
                    app('notifications')->send([], $model);
                }
            }],
            '(new \GuzzleHttp\Client)->post()' => [fn () => new class
            {
                public function created(Model $model): void
                {
                    (new \GuzzleHttp\Client)->post('https://receiver.example.test/hook');
                }
            }],
            'an imported Guzzle client' => [fn () => new class
            {
                public function created(Model $model): void
                {
                    (new GuzzleClient)->post('https://receiver.example.test/hook');
                }
            }],
            'curl_exec' => [fn () => new class
            {
                public function created(Model $model): void
                {
                    curl_exec(curl_init('https://receiver.example.test/hook'));
                }
            }],
            "file_get_contents('https://…')" => [fn () => new class
            {
                public function created(Model $model): void
                {
                    file_get_contents('https://receiver.example.test/hook?id='.$model->getKey());
                }
            }],
            "fopen('http://…')" => [fn () => new class
            {
                public function created(Model $model): void
                {
                    fopen('http://receiver.example.test/hook', 'r');
                }
            }],
        ];
    }

    #[Test]
    #[DataProvider('indirectDispatchShapes')]
    public function an_unguarded_observer_that_dispatches_indirectly_fails_the_guard(Closure $make): void
    {
        $scratch = $make();

        Risk::observe($scratch);

        $violation = $this->assertViolationFor($scratch::class, 'created');

        // Caught by a NEW signal, and by none of the five the first cut had —
        // which is what makes each of these a false negative QA found, now
        // closed, rather than a case that was always caught.
        $this->assertMatchesRegularExpression('/container alias|names |HTTP client|raw network call|URL stream/', $violation);
        $this->assertDoesNotMatchRegularExpression(
            '/facade dispatch|->notify\(\)|global helper|static dispatch|outward service/', $violation,
            'This shape was already caught before the indirect-dispatch signals were added; it proves nothing new.');
    }

    /**
     * Guards that LOOK like the guard and are not. The first two are inverted —
     * they return early on every live save and run during every migration — and
     * the third consults some other object's isOpen(). (Pint's yoda_style
     * rewrites `false === x` to `x === false`; both are rejected.)
     *
     * @return array<string, array{Closure(): object}>
     */
    public static function falseGuardShapes(): array
    {
        return [
            'negated: ! $this->migrations->isOpen()' => [fn () => new class(app(MigrationWindow::class))
            {
                public function __construct(private MigrationWindow $migrations) {}

                public function created(Model $model): void
                {
                    if (! $this->migrations->isOpen()) {
                        return;
                    }

                    event('scratch.created', [$model]);
                }
            }],
            'compared: app(MigrationWindow::class)->isOpen() === false' => [fn () => new class
            {
                public function created(Model $model): void
                {
                    if (app(MigrationWindow::class)->isOpen() === false) {
                        return;
                    }

                    event('scratch.created', [$model]);
                }
            }],
            'another object: $this->period->isOpen()' => [fn () => new class(app(MigrationWindow::class), new \App\Models\Period)
            {
                public function __construct(private MigrationWindow $migrations, private \App\Models\Period $period) {}

                public function created(Model $model): void
                {
                    if ($this->period->isOpen()) {
                        return;
                    }

                    event('scratch.created', [$model]);
                }
            }],
        ];
    }

    #[Test]
    #[DataProvider('falseGuardShapes')]
    public function a_guard_that_is_negated_or_on_the_wrong_object_fails(Closure $make): void
    {
        $scratch = $make();

        Risk::observe($scratch);

        $this->assertViolationFor($scratch::class, 'created');
    }

    /* ------------------------------------------------------------------ */
    /*  Enumeration */
    /* ------------------------------------------------------------------ */

    /**
     * Every `eloquent.*` listener whose code is ours.
     *
     * @return list<array{kind: 'observer'|'closure', event: string, class?: class-string, method?: string, closure?: Closure}>
     */
    private function modelListeners(): array
    {
        $this->bootEveryModel();

        $dispatcher = Model::getEventDispatcher();
        $this->assertNotNull($dispatcher);

        $raw = $dispatcher->getRawListeners();

        // Wildcard listeners (`eloquent.*`) are held apart and have no getter.
        $wildcards = (new \ReflectionProperty($dispatcher, 'wildcards'))->getValue($dispatcher);
        foreach ((array) $wildcards as $event => $listeners) {
            $raw[$event] = array_merge($raw[$event] ?? [], $listeners);
        }

        $found = [];

        foreach ($raw as $event => $listeners) {
            if (! str_starts_with((string) $event, 'eloquent.')) {
                continue;
            }

            foreach ($listeners as $listener) {
                if ($listener instanceof Closure) {
                    if (! $this->isOurs((string) (new ReflectionFunction($listener))->getFileName())) {
                        continue;
                    }
                    $found[] = ['kind' => 'closure', 'event' => (string) $event, 'closure' => $listener];

                    continue;
                }

                if (is_array($listener)) {
                    [$target, $method] = $listener;
                    $class = is_object($target) ? $target::class : (string) $target;
                } elseif (is_string($listener)) {
                    // `Class@method`. An anonymous class's name itself contains
                    // '@', so split on the LAST one.
                    $at = strrpos($listener, '@');
                    $class = $at === false ? $listener : substr($listener, 0, $at);
                    $method = $at === false ? 'handle' : substr($listener, $at + 1);
                } else {
                    continue;
                }

                if (! class_exists($class) || ! $this->isOurs((string) (new ReflectionClass($class))->getFileName())) {
                    continue;
                }

                $found[] = ['kind' => 'observer', 'event' => (string) $event, 'class' => $class, 'method' => $method];
            }
        }

        return $found;
    }

    /**
     * Instantiate every concrete model, so `#[ObservedBy]` observers and the
     * closures traits register in boot*() are on the dispatcher. The test's
     * fresh application cleared the booted-model cache, so this boots each
     * one against THIS dispatcher.
     */
    private function bootEveryModel(): void
    {
        $roots = [app_path(), ...glob(base_path('packages/*/src'), GLOB_ONLYDIR) ?: []];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                // Load only what declares itself a model. Autoloading every
                // class under app/ would execute every file, and one that does
                // not compile would take this test down for a reason that has
                // nothing to do with observers.
                $inModelsDir = str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR);
                $extendsAModel = preg_match('/\bextends\s+\\\\?(?:[\w\\\\]+\\\\)?(?:Model|Pivot|MorphPivot|Authenticatable|User|PersonalAccessToken)\b/', $source) === 1;

                if (! $inModelsDir && ! $extendsAModel) {
                    continue;
                }

                if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
                    || ! preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cls)) {
                    continue;
                }

                $class = $ns[1].'\\'.$cls[1];

                if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                    continue;
                }

                if ((new ReflectionClass($class))->isInstantiable()) {
                    new $class;
                }
            }
        }
    }

    private function isOurs(string $file): bool
    {
        return $file !== '' && ! str_starts_with($file, base_path('vendor').DIRECTORY_SEPARATOR);
    }

    /* ------------------------------------------------------------------ */
    /*  Classification */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<array{kind: 'observer'|'closure', event: string, class?: class-string, method?: string, closure?: Closure}>  $listeners
     * @return list<string>
     */
    private function violations(array $listeners): array
    {
        $violations = [];

        foreach (collect($listeners)->where('kind', 'observer')->groupBy('class') as $class => $entries) {
            $reflection = new ReflectionClass($class);
            $reasons = $this->outwardReasons($reflection);

            if ($reasons === []) {
                continue;
            }

            $classSource = $this->source($reflection->getFileName(), $reflection->getStartLine(), $reflection->getEndLine());
            $referencesWindow = str_contains($classSource, 'MigrationWindow');

            foreach ($entries->pluck('method')->unique() as $method) {
                if (! $reflection->hasMethod($method)) {
                    continue;
                }

                if (! $referencesWindow || ! $this->firstStatementIsTheGuard($reflection->getMethod($method))) {
                    $violations[] = "{$class}::{$method}() — outward (".implode(', ', $reasons).')';
                }
            }
        }

        foreach (collect($listeners)->where('kind', 'closure') as $entry) {
            $fn = new ReflectionFunction($entry['closure']);
            $body = $this->source($fn->getFileName(), $fn->getStartLine(), $fn->getEndLine());
            $reasons = $this->sourceSignals($body, (string) $fn->getFileName());

            if ($reasons !== [] && ! str_contains($body, 'MigrationWindow')) {
                $violations[] = sprintf('closure on %s at %s:%d — outward (%s)',
                    $entry['event'], basename((string) $fn->getFileName()), $fn->getStartLine(), implode(', ', $reasons));
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * Why this observer counts as outward; empty when it does not.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private function outwardReasons(ReflectionClass $class): array
    {
        $reasons = [];

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && $this->isOutwardClass($type->getName())) {
                $reasons[] = 'injects '.$type->getName();
            }
        }

        $source = $this->source($class->getFileName(), $class->getStartLine(), $class->getEndLine());

        return array_values(array_unique([...$reasons, ...$this->sourceSignals($source, (string) $class->getFileName())]));
    }

    /**
     * Signals 2 and 3: literal patterns, then class names resolved through the
     * file's imports.
     *
     * @return list<string>
     */
    private function sourceSignals(string $source, string $file): array
    {
        $hits = [];

        foreach (self::OUTWARD_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $hits[] = $label;
            }
        }

        foreach (self::CLASS_REFERENCES as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $name) {
                $resolved = $this->resolveClassName($name, $file);

                if ($this->isOutwardClass($resolved)) {
                    $hits[] = 'names '.$resolved;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * Is, extends or implements an OUTWARD_TYPE, or lives in an
     * OUTWARD_NAMESPACE. The namespace test runs first and needs no autoload,
     * so a class that does not compile is never loaded just to classify it.
     */
    private function isOutwardClass(string $name): bool
    {
        foreach (self::OUTWARD_NAMESPACES as $namespace) {
            if (str_starts_with($name, $namespace)) {
                return true;
            }
        }

        foreach (self::OUTWARD_TYPES as $outward) {
            if (is_a($name, $outward, true)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve a name as written in $file to its fully qualified form. */
    private function resolveClassName(string $name, string $file): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $context = $this->fileContext[$file] ??= $this->readFileContext($file);
        $first = strtolower(strtok($name, '\\') ?: $name);
        $rest = str_contains($name, '\\') ? substr($name, strpos($name, '\\')) : '';

        if (isset($context['imports'][$first])) {
            return $context['imports'][$first].$rest;
        }

        return ltrim($context['namespace'].'\\'.$name, '\\');
    }

    /** @return array{namespace: string, imports: array<string, string>} */
    private function readFileContext(string $file): array
    {
        $text = $file !== '' && is_file($file) ? (string) file_get_contents($file) : '';
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $text, $ns) === 1 ? trim($ns[1]) : '';
        $imports = [];

        // Top-level `use` only: a trait `use` inside a class body is indented.
        preg_match_all('/^use\s+(?!function\b|const\b)([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $text, $uses, PREG_SET_ORDER);

        foreach ($uses as $use) {
            $alias = $use[2] ?? '';
            $imports[strtolower($alias !== '' ? $alias : class_basename($use[1]))] = ltrim($use[1], '\\');
        }

        return ['namespace' => $namespace, 'imports' => $imports];
    }

    /**
     * True when the method's first statement is exactly
     * `if (<window>->isOpen()) { return; }` (braces optional), <window> being
     * a MigrationWindow-typed `$this->prop`, `app(MigrationWindow::class)` or
     * `resolve(MigrationWindow::class)`. Nothing else may sit inside the
     * condition: `! …->isOpen()`, `false === …->isOpen()` or `$x && …` would
     * return early OUTSIDE migrations, or only sometimes inside one.
     */
    private function firstStatementIsTheGuard(ReflectionMethod $method): bool
    {
        $source = $this->source($method->getFileName(), $method->getStartLine(), $method->getEndLine());
        $open = strpos($source, '{');

        if ($open === false) {
            return false;
        }

        $body = ltrim(substr($source, $open + 1));
        $window = '(?:\$this->(?<prop>\w+)|(?:app|resolve)\(\s*(?<cls>\\\\?[\w\\\\]+)::class\s*\))';

        if (preg_match('/^if\s*\(\s*'.$window.'->isOpen\(\)\s*\)\s*\{?\s*return\s*;/', $body, $m) !== 1) {
            return false;
        }

        $file = (string) $method->getFileName();

        if (($m['cls'] ?? '') !== '') {
            return $this->resolveClassName($m['cls'], $file) === MigrationWindow::class;
        }

        $class = $method->getDeclaringClass();

        if (! $class->hasProperty($m['prop'])) {
            return false;
        }

        $type = $class->getProperty($m['prop'])->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === MigrationWindow::class;
    }

    /**
     * Lines $from..$to of $file with every comment removed, so prose that
     * mentions a webhook or the window neither makes a listener outward nor
     * counts as its guard.
     */
    private function source(string|false $file, int|false $from, int|false $to): string
    {
        if ($file === false || $from === false || $to === false) {
            return '';
        }

        $lines = array_slice((array) file($file), $from - 1, $to - $from + 1);
        $code = '';

        foreach (token_get_all('<?php '.implode('', $lines)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return string the violation reported for $class::$method() */
    private function assertViolationFor(string $class, string $method): string
    {
        $violations = $this->violations($this->modelListeners());
        $mine = array_values(array_filter($violations, fn (string $v) => str_starts_with($v, "{$class}::{$method}()")));

        $this->assertNotEmpty($mine,
            "The guard must fail on a new outward observer without the MigrationWindow check; it reported:\n"
            .implode("\n", $violations));

        return $mine[0];
    }
}
