<?php

namespace Tests\Feature\Llm;

use App\Services\LlmService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NDPA: a failed LLM call must not copy the provider's response body into the log.
 *
 * PREVIOUS BEHAVIOUR: `LlmService::complete()` logged `['body' => $res->body()]`
 * on every non-2xx answer. Providers echo the prompt (or a fragment of it) in
 * their error bodies, and the prompts are built from risks, incidents and
 * vendor records, so personal data landed in laravel.log.
 */
class LlmServiceFailureLogCarriesNoBodyTest extends TestCase
{
    private const SENTINEL = 'SECRET-PROMPT-ECHO jane@example.com';

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

    /** @return array<string, array{string}> */
    public static function entryPoints(): array
    {
        return ['complete' => ['complete'], 'json' => ['json'], 'jsonWithUsage' => ['jsonWithUsage']];
    }

    #[Test]
    #[DataProvider('entryPoints')]
    public function a_500_with_an_echoing_body_logs_the_status_and_never_the_body(string $method): void
    {
        Http::fake(['llm.test/*' => Http::response(self::SENTINEL, 500)]);

        (new LlmService)->{$method}('Assess the risk raised by jane@example.com');

        $everything = json_encode($this->logged, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET-PROMPT-ECHO', $everything);
        $this->assertStringNotContainsString('jane@example.com', $everything);

        $failure = collect($this->logged)->first(fn ($e) => ($e['context']['status'] ?? null) === 500);
        $this->assertNotNull($failure, 'The failure log must carry the HTTP status as context.');
        $this->assertSame('ollama', $failure['context']['provider']);
        $this->assertSame('granite4:micro', $failure['context']['model']);
        $this->assertSame(strlen(self::SENTINEL), $failure['context']['body_length']);
        $this->assertArrayNotHasKey('body', $failure['context']);
    }

    #[Test]
    public function a_structured_error_logs_its_type_code_and_request_id_but_not_its_message(): void
    {
        Http::fake(['llm.test/*' => Http::response(
            ['error' => ['type' => 'context_length_exceeded', 'code' => 'E_CTX', 'message' => self::SENTINEL]],
            400,
            ['x-request-id' => 'req_abc123'],
        )]);

        (new LlmService)->complete('prompt');

        $failure = collect($this->logged)->first(fn ($e) => ($e['context']['status'] ?? null) === 400);
        $this->assertNotNull($failure);
        $this->assertSame('context_length_exceeded', $failure['context']['error_type']);
        $this->assertSame('E_CTX', $failure['context']['error_code']);
        $this->assertSame('req_abc123', $failure['context']['request_id']);
        $this->assertStringNotContainsString('SECRET-PROMPT-ECHO', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function an_ollama_style_free_text_error_string_is_not_treated_as_a_code(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => self::SENTINEL], 404)]);

        (new LlmService)->complete('prompt');

        $failure = collect($this->logged)->first(fn ($e) => ($e['context']['status'] ?? null) === 404);
        $this->assertNotNull($failure);
        $this->assertArrayNotHasKey('error_type', $failure['context']);
        $this->assertArrayNotHasKey('error_code', $failure['context']);
        $this->assertStringNotContainsString('SECRET-PROMPT-ECHO', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_code_that_is_free_text_or_an_address_is_dropped(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => ['type' => 'bad for jane@example.com', 'code' => self::SENTINEL]], 422)]);

        (new LlmService)->complete('prompt');

        $failure = collect($this->logged)->first(fn ($e) => ($e['context']['status'] ?? null) === 422);
        $this->assertNotNull($failure);
        $this->assertArrayNotHasKey('error_type', $failure['context']);
        $this->assertArrayNotHasKey('error_code', $failure['context']);
        $this->assertStringNotContainsString('jane@example.com', json_encode($this->logged, JSON_THROW_ON_ERROR));
    }
}
