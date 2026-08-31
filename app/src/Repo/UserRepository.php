<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Auth\Password;
use Carl\Core\Database;

/**
 * Accounts. Not scoped by user_id -- this table IS the user -- so it does not
 * extend Repository. Everything that reaches it is either the account acting
 * on itself or an admin route, both checked before the call.
 */
final class UserRepository
{
    public const STEP_PROFILE = 'profile';
    public const STEP_GARDEN  = 'garden';
    public const STEP_PLANT   = 'plant';
    public const STEP_DONE    = 'done';

    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM `user` WHERE `id` = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM `user` WHERE `username` = :username', ['username' => $username]);
    }

    public function usernameTaken(string $username): bool
    {
        return $this->db->value(
            'SELECT 1 FROM `user` WHERE `username` = :username',
            ['username' => $username]
        ) !== null;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.email, u.name, u.role, u.zip, u.county_fips,'
            . ' u.must_reset_password, u.onboarded_at, u.created_at, u.last_login_at,'
            . ' r.label AS region_label, r.research_status'
            . ' FROM `user` u LEFT JOIN `region` r ON r.id = u.region_id'
            . ' ORDER BY u.created_at DESC'
        );
    }

    /**
     * Admin: create a user with a temporary password shown once on screen.
     * Email delivery is Phase 3 (handoff Section 4.10); until then the admin
     * reads the password off the page and passes it on.
     *
     * @return array{id:int,temporary_password:string}
     */
    public function createWithTemporaryPassword(
        string $username,
        string $email,
        string $name,
        Password $passwords,
        string $role = 'user',
    ): array {
        $temporary = Password::temporary();

        $this->db->run(
            'INSERT INTO `user` (username, email, name, role, password_hash, must_reset_password,'
            . ' email_unsubscribe_token, onboarding_step, created_at, updated_at)'
            . ' VALUES (:username, :email, :name, :role, :hash, 1, :token, :step,'
            . ' UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => $username,
                'email'    => $email,
                'name'     => $name,
                'role'     => $role === 'admin' ? 'admin' : 'user',
                'hash'     => $passwords->hash($temporary),
                'token'    => \bin2hex(\random_bytes(32)),
                'step'     => self::STEP_PROFILE,
            ]
        );

        return ['id' => $this->db->insertId(), 'temporary_password' => $temporary];
    }

    /**
     * Onboarding step one: name, county and ZIP resolve to the coordinates,
     * timezone and region everything else hangs off (handoff Section 4.1).
     * One location per user; gardens inherit it.
     */
    public function saveProfile(
        int $userId,
        string $name,
        string $zip,
        ?float $latitude,
        ?float $longitude,
        ?string $countyFips,
        ?string $timezone,
        ?int $regionId,
        ?int $weatherLocationId,
    ): void {
        $this->db->run(
            'UPDATE `user` SET `name` = :name, `zip` = :zip, `latitude` = :lat, `longitude` = :lon,'
            . ' `county_fips` = :county, `timezone` = :tz, `region_id` = :region,'
            . ' `weather_location_id` = :location, `onboarding_step` = :step, `updated_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id',
            [
                'name'     => $name,
                'zip'      => $zip,
                'lat'      => $latitude,
                'lon'      => $longitude,
                'county'   => $countyFips,
                'tz'       => $timezone,
                'region'   => $regionId,
                'location' => $weatherLocationId,
                'step'     => self::STEP_GARDEN,
                'id'       => $userId,
            ]
        );
    }

    public function setOnboardingStep(int $userId, string $step): void
    {
        $this->db->run(
            'UPDATE `user` SET `onboarding_step` = :step, `updated_at` = UTC_TIMESTAMP() WHERE `id` = :id',
            ['step' => $step, 'id' => $userId]
        );
    }

    /**
     * The wizard can be skipped from any step after the profile, and resumed
     * from the main menu until it is complete (handoff Section 4.1).
     */
    public function completeOnboarding(int $userId): void
    {
        $this->db->run(
            'UPDATE `user` SET `onboarded_at` = COALESCE(`onboarded_at`, UTC_TIMESTAMP()),'
            . ' `onboarding_step` = :step, `updated_at` = UTC_TIMESTAMP() WHERE `id` = :id',
            ['step' => self::STEP_DONE, 'id' => $userId]
        );
    }

    public function setWeatherLocation(int $userId, int $locationId): void
    {
        $this->db->run(
            'UPDATE `user` SET `weather_location_id` = :location, `updated_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id',
            ['location' => $locationId, 'id' => $userId]
        );
    }

    /**
     * The account behind an unsubscribe token (handoff Section 12).
     *
     * The token is 64 hex characters from random_bytes and is unique in the
     * schema, so the lookup is an index hit rather than a scan. It is the
     * whole credential for that one route, which is why it is long: it has to
     * survive being in a URL in an inbox.
     *
     * @return array<string,mixed>|null
     */
    public function findByUnsubscribeToken(string $token): ?array
    {
        if (\preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM `user` WHERE `email_unsubscribe_token` = :token',
            ['token' => $token]
        );
    }

    public function setDigestEnabled(int $userId, bool $enabled): void
    {
        $this->db->run(
            'UPDATE `user` SET `email_digest_enabled` = :enabled, `updated_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id',
            ['enabled' => $enabled ? 1 : 0, 'id' => $userId]
        );
    }

    /**
     * Every user whose region became researched, or whose county has no
     * region row at all, keeps working -- this just re-points them once a
     * research import lands (handoff Section 9.4).
     *
     * @return int users re-pointed
     */
    public function relinkRegions(): int
    {
        return $this->db->run(
            'UPDATE `user` u'
            . ' JOIN `region` r ON r.region_key = CONCAT(:prefix, u.county_fips)'
            . ' SET u.region_id = r.id, u.updated_at = UTC_TIMESTAMP()'
            . ' WHERE u.county_fips IS NOT NULL'
            . '   AND (u.region_id IS NULL OR u.region_id <> r.id)',
            ['prefix' => 'US-']
        )->rowCount();
    }

    /** @return list<array<string,mixed>> active users with a weather location */
    public function withWeatherLocations(): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.timezone, u.weather_location_id, u.email, u.name,'
            . ' u.email_digest_enabled, u.region_id'
            . ' FROM `user` u WHERE u.weather_location_id IS NOT NULL'
        );
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM `user`', [], 0);
    }
}
