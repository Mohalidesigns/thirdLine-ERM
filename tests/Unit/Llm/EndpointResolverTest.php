<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\EndpointResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0015 §3 — endpoint selection, never entry, and the fallback fact the
 * settings screen renders as `stale_profile`.
 */
class EndpointResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);
    }

    #[Test]
    public function a_null_key_resolves_to_the_default_and_is_not_a_fallback(): void
    {
        $resolver = new EndpointResolver;

        $profile = $resolver->resolve(null);

        $this->assertSame('local-ollama', $profile->key);
        $this->assertFalse($resolver->wasFallback(null));
    }

    #[Test]
    public function an_unknown_key_resolves_to_the_default_and_is_reported_as_a_fallback(): void
    {
        $resolver = new EndpointResolver;

        $profile = $resolver->resolve('removed-profile');

        $this->assertSame('local-ollama', $profile->key);
        $this->assertTrue($resolver->wasFallback('removed-profile'));
    }

    #[Test]
    public function a_configured_key_resolves_to_itself(): void
    {
        config()->set('llm.profiles.secondary', [
            'label' => 'Secondary', 'endpoint' => 'http://box2:11434', 'model' => 'granite4:micro',
            'keep_alive' => '30m',
        ]);

        $resolver = new EndpointResolver;
        $profile = $resolver->resolve('secondary');

        $this->assertSame('secondary', $profile->key);
        $this->assertSame('http://box2:11434', $profile->endpoint);
        $this->assertFalse($resolver->wasFallback('secondary'));
    }

    #[Test]
    public function an_unpriced_profile_reports_no_price(): void
    {
        $resolver = new EndpointResolver;
        $profile = $resolver->resolve('local-ollama');

        $this->assertFalse($profile->isPriced());
        $this->assertNull($profile->unitCostPer1kTokensMinor);
        $this->assertNull($profile->currency);
    }

    #[Test]
    public function available_profiles_names_the_default(): void
    {
        $resolver = new EndpointResolver;

        $profiles = $resolver->availableProfiles();

        $this->assertCount(1, $profiles);
        $this->assertSame('local-ollama', $profiles[0]['key']);
        $this->assertTrue($profiles[0]['is_default']);
        $this->assertFalse($profiles[0]['priced']);
    }
}
