<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `TprmAuditable` must still write its row when the request's User-Agent,
 * correlation id or the actor's name would have defeated the old byte-wise
 * `substr()`.
 *
 * "Auditing never fails the write" turns a refused insert into a log line and a
 * missing row, and on the vendor portal the User-Agent is written by the person
 * being audited. So the property is asserted on the stored row, on both engines,
 * and not on the absence of an exception.
 */
class TprmAuditHostileInputTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function userAgents(): array
    {
        return [
            '600 bytes of two-byte characters' => [str_repeat('é', 300)],
            '1200 bytes of two-byte characters' => [str_repeat('é', 600)],
            'three-byte characters past the limit' => [str_repeat('€', 600)],
            'four-byte emoji straddling the limit' => [str_repeat('a', 499).str_repeat('😀', 50)],
            'emoji from character 1' => [str_repeat('😀', 600)],
            'invalid bytes' => ["\xC3\x28"],
            'invalid bytes inside a long header' => [str_repeat('é', 400)."\xC3\x28".str_repeat('😀', 200)],
        ];
    }

    #[Test]
    #[DataProvider('userAgents')]
    public function a_hostile_user_agent_still_writes_a_valid_intact_row(string $userAgent): void
    {
        $this->requestWith(['HTTP_USER_AGENT' => $userAgent]);

        $vendor = $this->vendor('hostile-ua');

        $row = $this->rowFor($vendor, 'created');

        $this->assertNotNull($row, 'the audit row must be written, not silently lost');
        $this->assertNotNull($row->user_agent);
        $this->assertTrue(mb_check_encoding($row->user_agent, 'UTF-8'));
        $this->assertLessThanOrEqual(500, mb_strlen($row->user_agent, 'UTF-8'));
        $this->assertTrue($row->isIntact());
    }

    #[Test]
    public function an_invalid_byte_is_stored_scrubbed(): void
    {
        $this->requestWith(['HTTP_USER_AGENT' => "Mozilla \xC3\x28"]);

        $row = $this->rowFor($this->vendor('scrubbed'), 'created');

        $this->assertNotNull($row);
        $this->assertSame('Mozilla ?(', $row->user_agent);
        $this->assertTrue($row->isIntact());
    }

    #[Test]
    public function an_ordinary_user_agent_is_stored_verbatim(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';
        $this->requestWith(['HTTP_USER_AGENT' => $ua]);

        $row = $this->rowFor($this->vendor('plain-ua'), 'created');

        $this->assertSame($ua, $row?->user_agent);
        $this->assertTrue($row->isIntact());
    }

    #[Test]
    public function the_chain_holds_across_a_hostile_row_and_the_rows_around_it(): void
    {
        $this->requestWith(['HTTP_USER_AGENT' => 'first']);
        $first = $this->vendor('chain-1');

        $this->requestWith(['HTTP_USER_AGENT' => 'a'.str_repeat('é', 300)."\xC3\x28"]);
        $second = $this->vendor('chain-2');

        $this->requestWith(['HTTP_USER_AGENT' => 'third']);
        $third = $this->vendor('chain-3');

        $rows = AuditLog::query()->withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->whereIn('auditable_id', [$first->id, $second->id, $third->id])
            ->where('event', 'created')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $rows);

        // Read back from the database, so the second engine's own handling of
        // the stored text is what the digest is re-checked against.
        foreach ($rows as $row) {
            $this->assertTrue($row->isIntact(), "row {$row->id} no longer verifies");
        }

        $this->assertSame($rows[0]->hash, $rows[1]->previous_hash);
        $this->assertSame($rows[1]->hash, $rows[2]->previous_hash);
    }

    #[Test]
    public function an_overlong_or_invalid_correlation_id_does_not_lose_the_row(): void
    {
        $this->requestWith([
            'HTTP_USER_AGENT' => 'ok',
            'HTTP_X_CORRELATION_ID' => str_repeat('é', 200)."\xC3\x28",
        ]);

        $row = $this->rowFor($this->vendor('corr'), 'created');

        $this->assertNotNull($row);
        $this->assertTrue(mb_check_encoding($row->correlation_id, 'UTF-8'));
        $this->assertLessThanOrEqual(64, mb_strlen($row->correlation_id, 'UTF-8'));
        $this->assertTrue($row->isIntact());
    }

    #[Test]
    public function an_actor_name_longer_than_the_label_column_does_not_lose_the_row(): void
    {
        $user = User::create([
            'name' => str_repeat('é', 255), 'email' => 'long@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $this->requestWith(['HTTP_USER_AGENT' => 'ok']);
        $this->actingAs($user);

        $row = $this->rowFor($this->vendor('long-actor'), 'created');

        $this->assertNotNull($row);
        $this->assertSame('user', $row->actor_type);
        $this->assertSame(200, mb_strlen($row->actor_label, 'UTF-8'));
        $this->assertTrue($row->isIntact());
    }

    /** @param array<string, string> $server */
    private function requestWith(array $server): void
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], $server));
    }

    private function vendor(string $slug): ThirdParty
    {
        return ThirdParty::create([
            'legal_name' => 'Vendor '.$slug,
            'slug' => $slug,
            'entity_type' => 'company',
        ]);
    }

    private function rowFor(ThirdParty $vendor, string $event): ?AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', ThirdParty::class)
            ->where('auditable_id', $vendor->id)
            ->where('event', $event)
            ->first();
    }
}
