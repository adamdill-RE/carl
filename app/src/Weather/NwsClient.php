<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\Config;
use Carl\Core\HttpClient;
use Carl\Core\HttpResult;

/**
 * api.weather.gov active alerts (handoff Section 8.4).
 *
 * Reachable from this host at 165 ms, measured on 2026-08-31 (Phase 3 handoff
 * Section 1.2) -- which is what unblocked this feature.
 *
 * The service asks for a descriptive User-Agent identifying the application
 * and a contact, and returns 403 to a generic one. `config/app.php` already
 * carries the string, because Open-Meteo wants the same courtesy.
 *
 * Coordinates are rounded to four decimals. The service rejects more
 * precision than that with a 400 whose message names the parameter, and four
 * decimals is about eleven metres, which is far finer than a county-wide
 * alert polygon needs.
 */
final class NwsClient implements AlertProvider
{
    public function __construct(private HttpClient $http, private Config $config)
    {
    }

    public function activeAt(float $latitude, float $longitude): HttpResult
    {
        return $this->http->getJson(
            $this->config->string('weather.alerts_url'),
            ['point' => \sprintf('%.4f,%.4f', $latitude, $longitude)]
        );
    }
}
