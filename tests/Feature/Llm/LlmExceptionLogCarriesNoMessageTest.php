<?php

namespace Tests\Feature\Llm;

use App\Enums\Llm\Outcome;
use App\Models\Organization;
use App\Services\Llm\CircuitBreaker;
use App\Services\Llm\LlmCall;
use App\Services\Llm\LlmGateway;
use App\Services\Llm\UsageRecorder;
use App\Services\LlmService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * NDPA: an exception on the LLM path must not copy its MESSAGE into the log.
 *
 * PREVIOUS BEHAVIOUR: LlmService, LlmGateway and UsageRecorder logged
 * `$e->getMessage()` on every exception path. HTTP-client and database
 * exception messages quote the request (the prompt, built from risks,
 * incidents and vendor records) or the bound values, so personal data landed
 * in laravel.log. The class and code are kept: they are what an operator needs.
 */
class LlmExceptionLogCarriesNoMessageTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 'PROMPT-FRAGMENT Assess the risk raised by jane@example.com';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.llm.enabled' => true,
            'services.llm.driver' => 'ollama',
            'services.llm.endpoint' => 'http://llm.test',
            'services.llm.model' => 'granite4:micro',
        ]);

        $this->logged = [];
        Log::listen(function ($event) {
            $this->logged[] = ['level' => $event->level, 'message' => (string) $event->message, 'context' => $event->context];
        });
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function throwingCalls(): array
    {
        $out = [];
        foreach (['complete', 'json', 'jsonWithUsage'] as $method) {
            $out[$method.' / connection'] = [$method, ConnectionException::class];
            $out[$method.' / generic'] = [$method, RuntimeException::class];
        }

        return $out;
    }

    #[Test]
    #[DataProvider('throwingCalls')]
    public function a_thrown_exception_logs_its_class_and_never_its_message(string $method, string $exceptionClass): void
    {
        Http::fake(function () use ($exceptionClass) {
            throw new $exceptionClass(self::SENTINEL);
        });

        $service = new LlmService;
        $service->{$method}('Assess the risk raised by jane@example.com');

        $everything = json_encode($this->logged, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PROMPT-FRAGMENT', $everything);
        $this->assertStringNotContainsString('jane@example.com', $everything);

        $entry = collect($this->logged)->first(fn ($e) => ($e['context']['exception'] ?? null) === $exceptionClass);
        $this->assertNotNull($entry, 'the exception class must still be logged, or this proves nothing');
        $this->assertArrayHasKey('code', $entry['context']);
        $this->assertSame('granite4:micro', $entry['context']['model'], 'the model is logged, as failureContext does');
        if ($method === 'jsonWithUsage' && $exceptionClass === ConnectionException::class) {
            $this->assertSame('connection', $entry['context']['kind']);
        }
        // The caller still gets the message through lastError(); only the log is minimised.
        $this->assertSame(self::SENTINEL, $service->lastError());
    }

    #[Test]
    public function a_timed_out_connection_is_logged_as_a_timeout_not_a_refusal(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds');
        });

        (new LlmService)->jsonWithUsage('prompt');

        $entry = collect($this->logged)->first(fn ($e) => $e['message'] === 'LlmService jsonWithUsage connection exception');
        $this->assertNotNull($entry);
        $this->assertSame('timeout', $entry['context']['kind']);
        $this->assertSame('granite4:micro', $entry['context']['model']);
    }

    #[Test]
    public function a_request_exception_from_the_http_client_logs_no_message(): void
    {
        Http::fake(function () {
            throw new RequestException(new Response(new Psr7Response(500, [], self::SENTINEL)));
        });

        $service = new LlmService;
        $service->jsonWithUsage('prompt');

        $this->assertStringContainsString('PROMPT-FRAGMENT', (string) $service->lastError(), 'fixture check: the exception message really embeds the sentinel');
        $this->assertStringNotContainsString('PROMPT-FRAGMENT', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function an_unexpected_gateway_exception_logs_its_class_and_never_its_message(): void
    {
        [$organization, $call] = $this->callFixture();

        $this->mock(CircuitBreaker::class, function ($mock) {
            $mock->shouldReceive('allows')->andThrow(new RuntimeException(self::SENTINEL));
        });

        $outcome = app(LlmGateway::class)->call($call);

        $this->assertFalse($outcome->succeeded());
        $entry = collect($this->logged)->first(fn ($e) => $e['message'] === 'LlmGateway::call() raised unexpectedly');
        $this->assertNotNull($entry);
        $this->assertSame(RuntimeException::class, $entry['context']['exception']);
        $this->assertSame($organization->id, $entry['context']['organization_id']);
        $this->assertStringNotContainsString('PROMPT-FRAGMENT', json_encode($this->logged, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('jane@example.com', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_usage_row_that_cannot_be_written_logs_its_class_and_never_its_message(): void
    {
        [, $call] = $this->callFixture();

        $recorder = Mockery::mock(UsageRecorder::class)->makePartial();
        $recorder->shouldReceive('record')->andThrow(new RuntimeException(self::SENTINEL));

        $this->assertNull($recorder->recordSafely($call, Outcome::HttpError));

        $entry = collect($this->logged)->first(fn ($e) => $e['message'] === 'LLM usage event write failed');
        $this->assertNotNull($entry);
        $this->assertSame(RuntimeException::class, $entry['context']['exception']);
        $this->assertStringNotContainsString('PROMPT-FRAGMENT', json_encode($this->logged, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('jane@example.com', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    /** @return array{Organization, LlmCall} */
    private function callFixture(): array
    {
        config([
            'features.tprm' => true,
            'tprm.ai.enabled' => true,
            'tprm.ai.services.evidence_extraction' => true,
            'tprm.ai.implemented_services' => ['evidence_extraction'],
            'services.llm.budgets.extraction' => ['max_tokens' => 2048, 'timeout' => 120],
            'llm.default_profile' => 'local-ollama',
            'llm.profiles' => ['local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://llm.test', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ]],
        ]);

        $organization = Organization::create([
            'name' => 'Kaduna Merchant Bank', 'short_name' => 'KMB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($organization->id);

        return [$organization, new LlmCall(
            organizationId: $organization->id,
            module: 'tprm',
            service: 'evidence_extraction',
            promptKey: 'soc2',
            promptVersion: 'soc2.v2',
            prompt: 'extract this',
            system: 'system prompt',
            budget: 'extraction',
        )];
    }
}
