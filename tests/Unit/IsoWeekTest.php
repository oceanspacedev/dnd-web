<?php

namespace Tests\Unit;

use App\Support\IsoWeek;
use Tests\TestCase;

class IsoWeekTest extends TestCase
{
    public function test_it_returns_the_monday_at_the_start_of_an_iso_week(): void
    {
        $this->assertSame('2024-01-01 00:00:00', IsoWeek::startsAt(2024, 1)->toDateTimeString());
        $this->assertSame('2024-12-30 00:00:00', IsoWeek::startsAt('2025', '1')->toDateTimeString());
    }
}
