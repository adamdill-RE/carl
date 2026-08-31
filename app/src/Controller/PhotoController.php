<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Support\Photos;
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
            $stored = $this->photos_service()->store($file, $this->userId());
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
            'taken_on' => $takenOn,
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

        $path = $this->photos_service()->path($this->userId(), $name);
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

    private function photos_service(): Photos
    {
        $config = $this->app->config();
        return new Photos(
            $this->app->varPath('photos'),
            $config->int('photos.max_bytes', 2097152),
            $config->int('photos.max_megapixels', 40),
            $config->int('photos.long_edge', 1920),
            $config->int('photos.thumb_edge', 320),
            $config->int('photos.jpeg_quality', 85),
        );
    }
}
