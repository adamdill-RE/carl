<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\HttpResult;

/**
 * What WeatherSync needs from a weather API.
 *
 * An interface rather than a concrete client so the sync's own rules -- the
 * revision window, the gap fill, the year chunking, and which source wins on
 * a collision -- can be tested without calling a provider. That matters more
 * than usual here: the free tier is rate limited per IP, hourly as well as
 * daily, so a suite that called the API would both be flaky and spend the
 * quota the nightly job depends on. Two full syncs in one hour is enough to
 * trip the hourly limit.
 */
interface WeatherProvider
{
    public function archive(
        float $latitude,
        float $longitude,
        string $timezone,
        string $from,
        string $to,
    ): HttpResult;

    public function forecast(float $latitude, float $longitude, string $timezone): HttpResult;
}
