<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\HttpResult;

/**
 * What AlertPoller needs from the National Weather Service.
 *
 * An interface for the same reason WeatherProvider is one: the poller's own
 * rules -- which event classes are kept, how an alert stops being active,
 * what a run row says when the fetch fails -- have to be testable without
 * calling a government API from a test suite.
 */
interface AlertProvider
{
    public function activeAt(float $latitude, float $longitude): HttpResult;
}
