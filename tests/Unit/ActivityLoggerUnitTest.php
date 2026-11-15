<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ActivityLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ActivityLoggerUnitTest extends TestCase
{
    #[Test]
    public function label_for_returns_name_attribute_when_present(): void
    {
        $user = new User;
        $user->name = 'Jane Doe';

        $this->assertSame('Jane Doe', ActivityLogger::labelFor($user));
    }

    #[Test]
    public function label_for_falls_back_to_class_basename_and_key(): void
    {
        $user = new User;
        $user->id = 42;

        $this->assertSame('User #42', ActivityLogger::labelFor($user));
    }
}
