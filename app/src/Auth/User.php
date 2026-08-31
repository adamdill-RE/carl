<?php

declare(strict_types=1);

namespace Carl\Auth;

/**
 * The signed-in account. Handoff Section 0.4: one location per user -- the
 * zip given at onboarding is both the weather location and the region, and
 * gardens inherit it. A person with two zips makes two accounts.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $name,
        public readonly string $role,
        public readonly bool $mustResetPassword,
        public readonly ?string $zip,
        public readonly ?string $countyFips,
        public readonly ?int $regionId,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $timezone,
        public readonly ?int $weatherLocationId,
        public readonly bool $emailDigestEnabled,
        public readonly ?string $onboardedAt,
        public readonly string $onboardingStep,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['name'],
            (string) $row['role'],
            (bool) $row['must_reset_password'],
            $row['zip'] === null ? null : (string) $row['zip'],
            $row['county_fips'] === null ? null : (string) $row['county_fips'],
            $row['region_id'] === null ? null : (int) $row['region_id'],
            $row['latitude'] === null ? null : (float) $row['latitude'],
            $row['longitude'] === null ? null : (float) $row['longitude'],
            $row['timezone'] === null ? null : (string) $row['timezone'],
            $row['weather_location_id'] === null ? null : (int) $row['weather_location_id'],
            (bool) $row['email_digest_enabled'],
            $row['onboarded_at'] === null ? null : (string) $row['onboarded_at'],
            (string) ($row['onboarding_step'] ?? 'profile'),
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOnboarded(): bool
    {
        return $this->onboardedAt !== null;
    }

    /** The IANA name every "today" for this user is computed in (handoff 6). */
    public function tz(): string
    {
        return $this->timezone ?? 'UTC';
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->username;
    }

    /**
     * A user whose county has no researched region still gets the global
     * plant catalog and DTM countdowns; what they lose is windows, the
     * "recommended" marker and guidance lines (handoff Section 9.4).
     */
    public function hasRegion(): bool
    {
        return $this->regionId !== null;
    }
}
