<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Core\Config;
use Carl\Core\Database;
use Carl\Core\HttpClient;

/**
 * ZIP -> coordinates -> county -> region (handoff Section 8.3).
 *
 * Global reference data, so no user scoping. The Census table is looked up
 * first; a miss falls back to Zippopotam.us ONCE and stores what it learns,
 * so a second user from the same ZIP costs no call at all.
 */
final class ZctaRepository
{
    /**
     * State -> IANA timezone, with the split-state exceptions that actually
     * matter handled by longitude below (weather.md Section 6.3: never a
     * fixed offset -- these are real zone names).
     *
     * @var array<string,string>
     */
    private const STATE_TZ = [
        'AL' => 'America/Chicago',    'AK' => 'America/Anchorage',  'AZ' => 'America/Phoenix',
        'AR' => 'America/Chicago',    'CA' => 'America/Los_Angeles','CO' => 'America/Denver',
        'CT' => 'America/New_York',   'DE' => 'America/New_York',   'DC' => 'America/New_York',
        'FL' => 'America/New_York',   'GA' => 'America/New_York',   'HI' => 'Pacific/Honolulu',
        'ID' => 'America/Boise',      'IL' => 'America/Chicago',    'IN' => 'America/Indiana/Indianapolis',
        'IA' => 'America/Chicago',    'KS' => 'America/Chicago',    'KY' => 'America/New_York',
        'LA' => 'America/Chicago',    'ME' => 'America/New_York',   'MD' => 'America/New_York',
        'MA' => 'America/New_York',   'MI' => 'America/Detroit',    'MN' => 'America/Chicago',
        'MS' => 'America/Chicago',    'MO' => 'America/Chicago',    'MT' => 'America/Denver',
        'NE' => 'America/Chicago',    'NV' => 'America/Los_Angeles','NH' => 'America/New_York',
        'NJ' => 'America/New_York',   'NM' => 'America/Denver',     'NY' => 'America/New_York',
        'NC' => 'America/New_York',   'ND' => 'America/Chicago',    'OH' => 'America/New_York',
        'OK' => 'America/Chicago',    'OR' => 'America/Los_Angeles','PA' => 'America/New_York',
        'RI' => 'America/New_York',   'SC' => 'America/New_York',   'SD' => 'America/Chicago',
        'TN' => 'America/Chicago',    'TX' => 'America/Chicago',    'UT' => 'America/Denver',
        'VT' => 'America/New_York',   'VA' => 'America/New_York',   'WA' => 'America/Los_Angeles',
        'WV' => 'America/New_York',   'WI' => 'America/Chicago',    'WY' => 'America/Denver',
        'PR' => 'America/Puerto_Rico','VI' => 'America/St_Thomas',  'GU' => 'Pacific/Guam',
        'AS' => 'Pacific/Pago_Pago',  'MP' => 'Pacific/Saipan',
    ];

    /**
     * The handful of split states where the state-level guess is wrong for
     * part of the state, resolved on longitude. Handoff Section 8.3 says to
     * refine to a polygon lookup only if a user is ever placed wrong.
     *
     * @var array<string,array{lon:float,west:string}>
     */
    private const SPLIT_STATES = [
        'FL' => ['lon' => -85.0,  'west' => 'America/Chicago'],
        'TN' => ['lon' => -85.5,  'west' => 'America/Chicago'],
        'KY' => ['lon' => -85.5,  'west' => 'America/Chicago'],
        'MI' => ['lon' => -90.0,  'west' => 'America/Menominee'],
        'IN' => ['lon' => -87.3,  'west' => 'America/Chicago'],
        'ND' => ['lon' => -101.0, 'west' => 'America/Denver'],
        'SD' => ['lon' => -100.0, 'west' => 'America/Denver'],
        'NE' => ['lon' => -101.0, 'west' => 'America/Denver'],
        'KS' => ['lon' => -101.5, 'west' => 'America/Denver'],
        'TX' => ['lon' => -105.0, 'west' => 'America/Denver'],
        'OR' => ['lon' => -117.0, 'west' => 'America/Boise'],
        'ID' => ['lon' => -116.0, 'west' => 'America/Los_Angeles'],
    ];

    public function __construct(
        private Database $db,
        private Config $config,
        private ?HttpClient $http = null,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(string $zip): ?array
    {
        $zip = self::normalise($zip);
        if ($zip === null) {
            return null;
        }
        return $this->db->one('SELECT * FROM `zcta` WHERE `zip` = :zip', ['zip' => $zip]);
    }

    public static function normalise(string $zip): ?string
    {
        $zip = \trim($zip);
        // Accept ZIP+4 and keep the five that the ZCTA table is keyed on.
        if (\preg_match('/^(\d{5})(?:-\d{4})?$/', $zip, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /**
     * Resolve a ZIP, calling out only when the table does not already know it.
     *
     * @return array{
     *   zip:string, latitude:float, longitude:float, county_fips:?string,
     *   state:?string, county_name:?string, place_name:?string,
     *   timezone:string, source:string, needs_admin:bool
     * }|null
     */
    public function resolve(string $zip, bool $allowRemote = true): ?array
    {
        $normalised = self::normalise($zip);
        if ($normalised === null) {
            return null;
        }

        $row = $this->find($normalised);
        if ($row === null && $allowRemote) {
            $row = $this->fetchFromZippopotam($normalised);
        }
        if ($row === null) {
            return null;
        }

        $state = $row['state'] === null ? null : (string) $row['state'];
        $longitude = (float) $row['longitude'];

        return [
            'zip'         => (string) $row['zip'],
            'latitude'    => (float) $row['latitude'],
            'longitude'   => $longitude,
            'county_fips' => $row['county_fips'] === null ? null : (string) $row['county_fips'],
            'state'       => $state,
            'county_name' => $row['county_name'] === null ? null : (string) $row['county_name'],
            'place_name'  => $row['place_name'] === null ? null : (string) $row['place_name'],
            'timezone'    => self::timezoneFor($state, $longitude),
            'source'      => (string) $row['source'],
            // A Zippopotam row has no county, so no region can be resolved and
            // the admin has to fill the gap (handoff Section 8.3).
            'needs_admin' => $row['county_fips'] === null,
        ];
    }

    public static function timezoneFor(?string $state, float $longitude): string
    {
        if ($state === null || !isset(self::STATE_TZ[$state])) {
            return 'America/Chicago';
        }
        $split = self::SPLIT_STATES[$state] ?? null;
        if ($split !== null && $longitude < $split['lon']) {
            return $split['west'];
        }
        return self::STATE_TZ[$state];
    }

    /**
     * One call to Zippopotam.us, result stored so it is never repeated.
     * This is the only outbound call the app makes outside cron, and it is
     * bounded to once per unknown ZIP for the life of the install.
     *
     * @return array<string,mixed>|null
     */
    private function fetchFromZippopotam(string $zip): ?array
    {
        if ($this->http === null) {
            return null;
        }

        $result = $this->http->getJson($this->config->string('weather.zip_api_url') . $zip);
        if (!$result->ok() || $result->json === null) {
            \error_log('[carl] zip lookup failed for ' . $zip . ': ' . ($result->error ?? 'unknown'));
            return null;
        }

        $places = $result->json['places'] ?? null;
        if (!\is_array($places) || $places === []) {
            return null;
        }
        $place = $places[0];

        $latitude = isset($place['latitude']) ? (float) $place['latitude'] : null;
        $longitude = isset($place['longitude']) ? (float) $place['longitude'] : null;
        if ($latitude === null || $longitude === null) {
            return null;
        }

        $this->db->run(
            'INSERT INTO `zcta` (zip, latitude, longitude, county_fips, state, county_name,'
            . ' place_name, source, created_at)'
            . ' VALUES (:zip, :lat, :lon, NULL, :state, NULL, :place, :source, UTC_TIMESTAMP())'
            . ' ON DUPLICATE KEY UPDATE `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`)',
            [
                'zip'    => $zip,
                'lat'    => $latitude,
                'lon'    => $longitude,
                'state'  => isset($place['state abbreviation']) ? (string) $place['state abbreviation'] : null,
                'place'  => isset($place['place name']) ? \substr((string) $place['place name'], 0, 120) : null,
                'source' => 'zippopotam',
            ]
        );

        return $this->find($zip);
    }

    /** @return list<array<string,mixed>> ZIPs the fallback created, for the admin */
    public function needingCounty(): array
    {
        return $this->db->all(
            "SELECT * FROM `zcta` WHERE `source` = 'zippopotam' AND `county_fips` IS NULL ORDER BY `zip`"
        );
    }
}
