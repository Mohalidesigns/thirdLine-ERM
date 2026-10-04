<?php

namespace Tests\Feature;

use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\HttpSecurityScheme;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

/**
 * `php artisan config:cache` writes the whole configuration with `var_export`,
 * so every value in it must survive that round trip. A live object (or a
 * closure) does not: it has no `__set_state()`, and the command throws
 * "the value at <key> is non-serializable".
 *
 * `scripts/deploy.sh` runs `config:cache` under `set -e`, so one such value
 * aborts EVERY deploy — before `route:cache`, preflight and the restart.
 * `config/scramble.php` held exactly one (`SecurityScheme::http('bearer')`),
 * and nothing in the suite noticed, because the suite never caches its config.
 *
 * This test mirrors the command's check without running it: running
 * `config:cache` here would write bootstrap/cache/config.php, which every other
 * test (parallel runs included) would then read.
 */
class ConfigIsSerializableTest extends TestCase
{
    #[Test]
    public function every_config_value_survives_var_export(): void
    {
        $offenders = [];

        // The same probe ConfigCacheCommand runs to name the culprit: export each
        // value and evaluate it back. An object without `__set_state()` (a
        // SecurityScheme, a Closure) throws; a DateInterval, which has one,
        // round-trips and is legitimately in config (permission.cache).
        foreach (Arr::dot(config()->all()) as $key => $value) {
            try {
                eval('return '.var_export($value, true).';');
            } catch (Throwable $e) {
                $offenders[] = $key.' ('.(is_object($value) ? get_class($value) : gettype($value)).')';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'config:cache cannot serialize these values, so every deploy would abort at that step. '
            .'Store a class-string or a scalar and build the object where it is used: '.implode(', ', $offenders)
        );
    }

    /**
     * The scramble `scheme` object was removed from config because the
     * strategy's own default is the same object. Prove the default still
     * documents a bearer scheme, so the removal is a no-op in fact and not in
     * the comment.
     */
    #[Test]
    public function the_api_docs_security_strategy_still_documents_a_bearer_scheme(): void
    {
        $strategy = (new GeneratorConfig(config: config('scramble')))->securityStrategy();

        $this->assertInstanceOf(MiddlewareAuthSecurityStrategy::class, $strategy);

        $scheme = (new ReflectionProperty($strategy, 'scheme'))->getValue($strategy);

        $this->assertInstanceOf(HttpSecurityScheme::class, $scheme);
        $this->assertSame('http', $scheme->type);
        $this->assertSame('bearer', $scheme->toArray()['scheme']);
    }
}
