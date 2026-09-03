<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Throwable;

/**
 * Photo upload and serving (handoff Section 10).
 *
 * One photo per request, so a form never posts a photo with the other fields
 * -- which is also what keeps a garden's worth of rows away from
 * post_max_size and max_input_vars (hosting Section 4).
 *
 * Files live outside public_html and are served ONLY through here, after the
 * ownership check. There is no direct URL to a photo.
 */
final class PhotoController extends Controller
{
    public function upload(Request $request): Response
    {
        $file = $request->file('photo');
        if ($file === null) {
            return Response::json(['ok' => false, 'message' => 'No photo was attached.'], 400);
        }

        $plantingId = $request->intInput('planting_id');
        $gardenId = $request->intInput('garden_id');

        if ($plantingId !== null && !$this->plantings()->exists($plantingId)) {
            return Response::json(['ok' => false, 'message' => 'That is not one of your plants.'], 404);
        }
        if ($gardenId !== null && !$this->gardens()->exists($gardenId)) {
            return Response::json(['ok' => false, 'message' => 'That is not one of your gardens.'], 404);
        }

        try {
            $stored = $this->photoService()->store($file, $this->userId());
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], 400);
        }

        // taken_on comes from EXIF when the photo carried it, otherwise the
        // event date the user is filling in (handoff Section 5.3).
        $takenOn = $stored['taken_on']
            ?? $this->eventDate($request, 'event_date');

        $photoId = $this->photos()->insert([
            'planting_id' => $plantingId,
            'garden_id'   => $gardenId,
            'taken_on'    => $takenOn,
            'stored_name' => $stored['stored_name'],
            'thumb_name'  => $stored['thumb_name'],
            'width'       => $stored['width'],
            'height'      => $stored['height'],
            'bytes'       => $stored['bytes'],
            'caption'     => $request->nullable('caption'),
        ]);

        return Response::json([
            'ok'       => true,
            'id'       => $photoId,
            'thumb'    => $this->app->url('photos/' . $photoId . '/thumb'),
            'url'      => $this->app->url('photos/' . $photoId),
            'view'     => $this->app->url('photos/' . $photoId . '/view'),
            'taken_on' => $takenOn,
        ]);
    }

    /**
     * The photo, large, on a page of Carl's own (Phase 17).
     *
     * Every thumbnail used to link straight to the JPEG. In Safari that is
     * fine -- the back button is right there -- and in the app on an iPhone's
     * home screen it is a full-screen photograph with no browser chrome at
     * all: no back, no forward, no address bar, and the only way out is to
     * kill the app. So the thumbnail now opens THIS page, which has the
     * photo, the way back to the plant or the garden it belongs to, and the
     * way along to the photos beside it. gallery.js turns the same links
     * into an in-page viewer where it can; this is what works everywhere,
     * script or no script.
     *
     * The set a photo belongs to is its planting's photos, or its garden's,
     * in the order the plant page shows them: previous and next mean what
     * they mean on that page.
     */
    public function view(Request $request, array $params): Response
    {
        $photo = $this->photos()->findOrFail((int) $params['id']);

        $set = [$photo];
        $back = ['url' => $this->app->url(''), 'label' => 'the menu'];

        if ($photo['planting_id'] !== null) {
            $plantingId = (int) $photo['planting_id'];
            $set = $this->photos()->forPlanting($plantingId);
            $planting = $this->plantings()->findWithDetail($plantingId);
            $label = \trim((string) ($planting['label'] ?? ''));
            $name = $planting === null ? 'the plant'
                : ($label !== '' ? $label
                    : \trim((string) ($planting['category'] ?? '') . ' ' . (string) ($planting['type'] ?? '')));
            $back = ['url' => $this->app->url('plants/' . $plantingId), 'label' => $name];
        } elseif ($photo['garden_id'] !== null) {
            $gardenId = (int) $photo['garden_id'];
            $set = $this->photos()->forGarden($gardenId);
            $garden = $this->gardens()->find($gardenId);
            $back = ['url'   => $this->app->url('gardens/' . $gardenId),
                     'label' => (string) ($garden['name'] ?? 'the garden')];
        }

        $position = 0;
        foreach ($set as $i => $row) {
            if ((int) $row['id'] === (int) $photo['id']) {
                $position = $i;
                break;
            }
        }
        if ($set === []) {
            $set = [$photo];
            $position = 0;
        }

        return $this->render('photos/view', [
            'photo'     => $photo,
            'position'  => $position + 1,
            'count'     => \count($set),
            'prev'      => $set[$position - 1] ?? null,
            'next'      => $set[$position + 1] ?? null,
            'back'      => $back,
            'pageTitle' => 'Photo',
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        return $this->serve((int) $params['id'], false);
    }

    public function thumb(Request $request, array $params): Response
    {
        return $this->serve((int) $params['id'], true);
    }

    private function serve(int $photoId, bool $thumb): Response
    {
        // findOrFail is user-scoped, so another account's id is a 404 here
        // exactly as it is for a row that does not exist.
        $photo = $this->photos()->findOrFail($photoId);

        $name = $thumb && $photo['thumb_name'] !== null
            ? (string) $photo['thumb_name']
            : (string) $photo['stored_name'];

        $path = $this->photoService()->path($this->userId(), $name);
        if (!\is_file($path)) {
            throw HttpException::notFound('That photo file is missing.');
        }

        $body = \file_get_contents($path);
        if ($body === false) {
            throw HttpException::notFound();
        }

        // Personal data: private, and never a shared cache.
        return Response::binary($body, 'image/jpeg')
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withHeader('Content-Disposition', 'inline');
    }
}
