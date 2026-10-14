<?php

namespace Tests\Unit\Configuration;

use App\Models\ConfigBundle;
use App\Services\Configuration\ConfigurationExporter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0026: a bundle's checksum recipe is chosen by `format_version`, and the
 * two recipes never move.
 *
 * No database. A failure in a pin test means somebody changed a recipe, which
 * makes every bundle sealed under it read as altered. Read the ADR before you
 * "fix" the literal.
 */
class BundleChecksumRecipeTest extends TestCase
{
    /** The fixed format-1 payload. Doubles are exactly representable, so the pin does not depend on serialize_precision. */
    private function v1Payload(): array
    {
        return [
            'format_version' => 1,
            'exported_from' => 'testing',
            'sections' => [
                'scoring_profiles' => [[
                    'name' => 'Café / Ltd',
                    'code' => 'x',
                    'dimension_weights' => ['b' => 0.25, 'a' => 0.5, 'n' => 7, 'whole' => 100.0],
                ]],
                'object_types' => [],
            ],
        ];
    }

    private function v2Payload(): array
    {
        return array_replace($this->v1Payload(), ['format_version' => 2]);
    }

    /** ADR 0026 test A1's payload. */
    private function richPayload(): array
    {
        return [
            'format_version' => 2,
            'exported_from' => 'testing',
            'sections' => ['scoring_profiles' => [[
                'code' => 'x',
                'name' => 'Café / Ltd',
                'dimension_weights' => ['ratio' => 478 / 53, 'big' => 1.0e25, 'sum' => 0.1 + 0.2, 'whole' => 100.0, 'n' => 7],
            ]]],
        ];
    }

    private function v2(array $payload): string
    {
        return ConfigurationExporter::checksum(['format_version' => 2] + $payload);
    }

    #[Test]
    public function the_v1_recipe_is_pinned_byte_for_byte(): void
    {
        // Computed on the code as it stood before ADR 0026 (commit 056ec5a).
        $this->assertSame(
            'e2f73342aa83aca089b6bf86cc9f195f1e13c75c3ce6a761745bd982374fad34',
            ConfigurationExporter::checksum($this->v1Payload()),
            'The v1 recipe changed. Every bundle exported before ADR 0026 now reads as altered.'
        );

        // Absent format_version is v1 as well.
        $bare = $this->v1Payload();
        unset($bare['format_version']);
        $this->assertNotSame(ConfigurationExporter::checksum($this->v1Payload()), ConfigurationExporter::checksum($bare));
        $this->assertSame(
            hash('sha256', json_encode(ConfigurationExporter::canonicalise($bare), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ConfigurationExporter::checksum($bare)
        );
    }

    #[Test]
    public function the_v2_recipe_is_pinned(): void
    {
        $this->assertSame(
            'c652ae804cee9d3e8686be59fcaaf5fc21698889e7bfb591aff75b4843cb90e6',
            ConfigurationExporter::checksum($this->v2Payload()),
            'The v2 recipe changed. Every bundle exported under ADR 0026 now reads as altered.'
        );
    }

    #[Test]
    public function v2_is_tagged_so_it_cannot_equal_a_v1_digest_of_the_same_text(): void
    {
        $payload = $this->v2Payload();

        $this->assertSame(
            hash('sha256', 'config_bundles/v2|'.\App\Support\CanonicalJson::encode($payload)),
            ConfigurationExporter::checksum($payload)
        );
        $this->assertNotSame(
            hash('sha256', \App\Support\CanonicalJson::encode($payload)),
            ConfigurationExporter::checksum($payload)
        );
    }

    #[Test]
    public function v2_tells_apart_values_that_v1_could_not(): void
    {
        $this->assertNotSame($this->v2(['x' => 1]), $this->v2(['x' => 1.0]), '1 and 1.0');
        $this->assertNotSame($this->v2(['x' => 72.5]), $this->v2(['x' => '72.5']), '72.5 and "72.5"');
        $this->assertNotSame($this->v2(['x' => true]), $this->v2(['x' => 1]), 'true and 1');
        $this->assertNotSame($this->v2(['x' => ['a', 'b']]), $this->v2(['x' => ['b', 'a']]), 'a re-ordered list');
    }

    #[Test]
    public function v2_does_not_depend_on_key_order_including_mixed_int_and_string_keys(): void
    {
        $this->assertSame(
            $this->v2(['k' => ['p' => 1, 'q' => [2, 3]], 'a' => 1]),
            $this->v2(['a' => 1, 'k' => ['q' => [2, 3], 'p' => 1]]),
        );

        $orders = [
            [10 => 'x', '1a' => 'y', 9 => 'z'],
            ['1a' => 'y', 9 => 'z', 10 => 'x'],
            [9 => 'z', 10 => 'x', '1a' => 'y'],
        ];

        $digests = array_map(fn (array $keys) => $this->v2(['m' => $keys]), $orders);

        $this->assertCount(1, array_unique($digests), 'ksort over mixed keys is not transitive; v2 must not use it');
    }

    #[Test]
    public function v2_does_not_depend_on_the_serialize_precision_it_is_computed_under(): void
    {
        $payload = $this->richPayload();
        $before = ConfigurationExporter::checksum($payload);

        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $this->assertSame($before, ConfigurationExporter::checksum($payload));
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    #[Test]
    public function an_unknown_format_is_refused_not_guessed(): void
    {
        $payload = array_replace($this->v2Payload(), ['format_version' => 3]);

        try {
            ConfigurationExporter::checksum($payload);
            $this->fail('format 3 must not be checksummed');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('format 3', $error->getMessage());
            $this->assertStringContainsString('formats 1 and 2', $error->getMessage());
        }

        $bundle = new ConfigBundle(['payload' => $payload, 'checksum' => str_repeat('a', 64)]);

        $this->assertFalse($bundle->isIntact());
    }

    #[Test]
    public function a_file_that_says_2_but_was_sealed_under_1_does_not_verify(): void
    {
        $payload = $this->v2Payload();
        $v1Digest = hash('sha256', json_encode(ConfigurationExporter::canonicalise($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertNotSame($v1Digest, ConfigurationExporter::checksum($payload));
    }

    #[Test]
    public function sealing_and_unsealing_round_trips_exactly(): void
    {
        $payload = $this->richPayload();

        $sealed = ConfigBundle::sealPayload($payload);
        $back = ConfigBundle::unsealPayload($sealed);

        // Sealing sorts keys (canonical text); the VALUES and their types are what must survive.
        $this->assertSame(\App\Support\CanonicalJson::normalise($payload), $back);
        $this->assertSame(100.0, $back['sections']['scoring_profiles'][0]['dimension_weights']['whole']);
        $this->assertSame(478 / 53, $back['sections']['scoring_profiles'][0]['dimension_weights']['ratio']);
        $this->assertSame(7, $back['sections']['scoring_profiles'][0]['dimension_weights']['n']);

        // The column holds a JSON string whose content is the canonical text.
        $this->assertIsString(json_decode($sealed, true));
        $this->assertSame(\App\Support\CanonicalJson::encode($payload), json_decode($sealed, true));
    }

    #[Test]
    public function a_legacy_object_row_still_reads_and_anything_else_throws(): void
    {
        $legacy = '{"format_version":1,"sections":{"a":[{"n":1}]}}';

        $this->assertSame(
            ['format_version' => 1, 'sections' => ['a' => [['n' => 1]]]],
            ConfigBundle::unsealPayload($legacy)
        );

        $this->assertNull(ConfigBundle::unsealPayload(null));

        $this->expectException(\UnexpectedValueException::class);
        ConfigBundle::unsealPayload('42');
    }
}
