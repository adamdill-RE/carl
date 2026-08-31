<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\SoilType;
use Carl\Repo\UserRepository;
use Carl\Repo\ZctaRepository;
use Carl\Support\Clock;

/**
 * The onboarding wizard (handoff Section 4.1).
 *
 * Profile -> first garden -> optional first plant. "Skip to main menu" is
 * available on every step after the profile is submitted, and the wizard can
 * be resumed from the main menu until it is complete.
 */
final class OnboardingController extends Controller
{
    /**
     * The MOTD wants the last three days the moment the first sync lands, so
     * a new location asks for a fortnight rather than starting at today.
     */
    private const INITIAL_BACKFILL_DAYS = 14;

    public function index(Request $request): Response
    {
        $user = $this->user();
        if ($user->isOnboarded()) {
            return $this->redirect('/');
        }
        if ($user->onboardingStep === UserRepository::STEP_GARDEN) {
            return $this->redirect('/onboarding/garden');
        }
        if ($user->onboardingStep === UserRepository::STEP_PLANT) {
            return $this->redirect('/onboarding/plant');
        }

        return $this->render('onboarding/profile', [
            'step'     => 'profile',
            'name'     => $user->name,
            'zip'      => $user->zip ?? '',
            'county'   => '',
            'resolved' => null,
            'errors'   => [],
        ]);
    }

    /**
     * Progressive enhancement: confirm the ZIP before submitting the form.
     * The POST below re-resolves it anyway, so this is convenience, not trust.
     */
    public function lookupZip(Request $request): Response
    {
        $zip = $request->input('zip', '') ?? '';
        $resolved = $this->zcta()->resolve($zip);

        if ($resolved === null) {
            return Response::json(['found' => false, 'message' => 'That ZIP code was not recognised.']);
        }

        return Response::json([
            'found'    => true,
            'place'    => $this->describePlace($resolved),
            'timezone' => $resolved['timezone'],
            'region'   => $this->reference()->regionIdForCounty($resolved['county_fips']) !== null,
        ]);
    }

    public function saveProfile(Request $request): Response
    {
        $user = $this->user();

        $name = \trim((string) $request->input('name', ''));
        $zip = (string) $request->input('zip', '');
        $county = \trim((string) $request->input('county', ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'Tell Carl what to call you.';
        }
        if (\strlen($name) > 120) {
            $errors[] = 'That name is longer than 120 characters.';
        }

        $resolved = null;
        if (ZctaRepository::normalise($zip) === null) {
            $errors[] = 'Enter a five-digit ZIP code.';
        } else {
            $resolved = $this->zcta()->resolve($zip);
            if ($resolved === null) {
                $errors[] = 'That ZIP code was not recognised. Check it and try again.';
            }
        }

        if ($errors !== [] || $resolved === null) {
            return $this->render('onboarding/profile', [
                'step'     => 'profile',
                'name'     => $name,
                'zip'      => $zip,
                'county'   => $county,
                'resolved' => $resolved,
                'errors'   => $errors,
            ]);
        }

        $regionId = $this->reference()->regionIdForCounty($resolved['county_fips']);

        // No researched region for this county: flag it for the admin queue so
        // the owner has something to bring to Claude (handoff Section 9.4).
        if ($regionId === null && $resolved['county_fips'] !== null) {
            $regionId = $this->reference()->noteUnresearchedCounty(
                $resolved['county_fips'],
                $resolved['state'],
                $resolved['county_name'] ?? ($county !== '' ? $county : null),
            );
        }

        // One weather location per distinct set of coordinates, shared by
        // every user at that ZIP (weather.md Section 7.2).
        $locationId = $this->weather()->ensureLocation(
            $this->describePlace($resolved),
            $resolved['zip'],
            $resolved['latitude'],
            $resolved['longitude'],
            $resolved['timezone'],
            (string) Clock::addDays(
                $this->app->clock()->todayFor($resolved['timezone']),
                -self::INITIAL_BACKFILL_DAYS
            ),
        );

        $this->accounts()->saveProfile(
            $user->id,
            $name,
            $resolved['zip'],
            $resolved['latitude'],
            $resolved['longitude'],
            $resolved['county_fips'],
            $resolved['timezone'],
            $regionId,
            $locationId,
        );

        // Every account gets an Indoor Garden at signup: it is the default
        // location for indoor seed starts (handoff Section 4.1).
        $this->gardens()->ensureIndoorGarden();

        // Seed the lists that must not be empty the first time they open
        // (handoff Section 5.6).
        $this->lists()->seedForNewUser();

        $region = $regionId === null ? null : $this->reference()->findRegion($regionId);
        if ($region === null || (string) $region['research_status'] !== 'researched') {
            $this->flash(
                'Your area is not researched yet, so Carl is using general guidance for now. '
                . 'Everything still records; planting windows and local advice arrive when the '
                . 'research for your county is loaded.',
                'info'
            );
        }

        return $this->redirect('/onboarding/garden');
    }

    public function garden(Request $request): Response
    {
        $user = $this->user();
        if ($user->isOnboarded()) {
            return $this->redirect('/');
        }
        if ($user->onboardingStep === UserRepository::STEP_PROFILE) {
            return $this->redirect('/onboarding');
        }

        return $this->render('onboarding/garden', [
            'step'      => 'garden',
            'soilTypes' => SoilType::options(),
            'errors'    => [],
            'values'    => ['name' => '', 'ns_ft' => '', 'ew_ft' => '', 'row_count' => '3',
                            'row_orientation' => 'ns', 'soil_type' => '', 'notes' => ''],
        ]);
    }

    public function saveGarden(Request $request): Response
    {
        $user = $this->user();

        $values = [
            'name'            => \trim((string) $request->input('name', '')),
            'ns_ft'           => (string) $request->input('ns_ft', ''),
            'ew_ft'           => (string) $request->input('ew_ft', ''),
            'row_count'       => (string) $request->input('row_count', '0'),
            'row_orientation' => $this->choice($request, 'row_orientation', ['ns', 'ew'], 'ns'),
            'soil_type'       => (string) $request->input('soil_type', ''),
            'notes'           => (string) $request->input('notes', ''),
        ];

        $errors = [];
        if ($values['name'] === '') {
            $errors[] = 'Give the garden a name.';
        }
        $rowCount = (int) $values['row_count'];
        if ($rowCount < 0 || $rowCount > 200) {
            $errors[] = 'Rows must be between 0 and 200.';
        }
        if ($values['soil_type'] !== '' && !SoilType::isValid($values['soil_type'])) {
            $errors[] = 'Pick a soil type from the list.';
        }

        if ($errors !== []) {
            return $this->render('onboarding/garden', [
                'step'      => 'garden',
                'soilTypes' => SoilType::options(),
                'errors'    => $errors,
                'values'    => $values,
            ]);
        }

        $gardenId = $this->gardens()->insert([
            'name'            => $values['name'],
            'is_indoor'       => 0,
            'ns_ft'           => $values['ns_ft'] === '' ? null : (float) $values['ns_ft'],
            'ew_ft'           => $values['ew_ft'] === '' ? null : (float) $values['ew_ft'],
            'row_count'       => $rowCount,
            'row_orientation' => $values['row_orientation'],
            'soil_type'       => $values['soil_type'] === '' ? null : $values['soil_type'],
            'notes'           => $values['notes'] === '' ? null : $values['notes'],
        ]);
        $this->gardens()->syncRows($gardenId, $rowCount);

        $this->accounts()->setOnboardingStep($user->id, UserRepository::STEP_PLANT);
        $this->flash('Garden created' . ($rowCount > 0 ? ' with ' . $rowCount . ' rows.' : '.'));

        return $this->redirect('/onboarding/plant');
    }

    public function plant(Request $request): Response
    {
        $user = $this->user();
        if ($user->isOnboarded()) {
            return $this->redirect('/');
        }
        return $this->render('onboarding/plant', [
            'step'    => 'plant',
            'gardens' => $this->gardens()->activeGardens(),
        ]);
    }

    /** "Skip to main menu" from any step after the profile. */
    public function finish(Request $request): Response
    {
        $this->accounts()->completeOnboarding($this->userId());
        $this->flash('You are set up. Everything is on the main menu.');
        return $this->redirect('/');
    }

    /** @param array<string,mixed> $resolved */
    private function describePlace(array $resolved): string
    {
        $parts = [];
        if (!empty($resolved['place_name'])) {
            $parts[] = (string) $resolved['place_name'];
        }
        if (!empty($resolved['county_name'])) {
            $parts[] = (string) $resolved['county_name'];
        }
        if (!empty($resolved['state'])) {
            $parts[] = (string) $resolved['state'];
        }
        $label = \implode(', ', $parts);
        return $label === '' ? (string) $resolved['zip'] : $label . ' ' . $resolved['zip'];
    }
}
