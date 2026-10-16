<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaExportJob;
use App\Services\Rcsa\RcsaExportService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `rcsa_export_jobs.user_agent` is `varchar(512)`. The export log is a control
 * (§10.2): a header that made the insert fail would have made the export itself
 * fail, or been dropped from the record, depending on the caller.
 */
class ExportLogUserAgentTest extends ReviewTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(['rcsa_export.bulk']);
    }

    /** @return array<string, array{string}> */
    public static function userAgents(): array
    {
        return [
            'odd-offset two-byte characters' => ['a'.str_repeat('é', 600)],
            'emoji straddling the limit' => [str_repeat('a', 511).str_repeat('😀', 50)],
            'invalid bytes' => ["Mozilla \xC3\x28"],
        ];
    }

    #[Test]
    #[DataProvider('userAgents')]
    public function a_hostile_user_agent_is_stored_valid_and_within_the_column(string $userAgent): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $userAgent]);

        $export = app(RcsaExportService::class)->log($this->actor, [], 0, $request);

        $stored = RcsaExportJob::query()->withoutGlobalScopes()->findOrFail($export->getKey())->user_agent;

        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertLessThanOrEqual(512, mb_strlen($stored, 'UTF-8'));
        $this->assertNotSame('', $stored);
    }
}
