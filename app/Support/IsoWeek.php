<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class IsoWeek
{
    public static function startsAt(int|string $year, int|string $week): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))
            ->setISODate((int) $year, (int) $week)
            ->startOfDay();
    }
}
