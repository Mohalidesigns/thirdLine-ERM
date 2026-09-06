<?php

namespace ThirdLine\Platform\Tests;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use ThirdLine\Platform\PlatformServiceProvider;
use ThirdLine\Platform\Tenancy\TenancyConfig;

/**
 * The package's own coverage is deliberately thin, and this is the piece worth
 * having: the one decision the package cannot make for its consumer.
 *
 * Behavioural coverage of tenancy — that no query crosses a tenant boundary
 * over HTTP — belongs to the consuming application, because that is where the
 * boundary exists. See tests/Feature/TenancyIsolationTest.php in the risk
 * product, 141 assertions.
 */
class TenancyConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PlatformServiceProvider::class];
    }

    #[Test]
    public function an_unconfigured_organization_model_throws_and_names_the_key(): void
    {
        config(['platform.tenancy.organization_model' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/platform\.tenancy\.organization_model/');

        TenancyConfig::organizationModel();
    }

    #[Test]
    public function a_model_class_that_does_not_exist_throws_rather_than_being_returned(): void
    {
        // The failure this catches is a typo in a consumer's config, which
        // would otherwise surface as a relation to a missing class on the
        // first ->organization call, far from the cause.
        config(['platform.tenancy.organization_model' => 'App\\Models\\Organisation']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        TenancyConfig::organizationModel();
    }

    #[Test]
    public function a_configured_class_is_returned(): void
    {
        config(['platform.tenancy.organization_model' => self::class]);

        $this->assertSame(self::class, TenancyConfig::organizationModel());
    }
}
