<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Reports\PdfBuilder;
use Carl\Support\Tokens;

/**
 * Reports: the JSON series a chart reads, and the PDF a chart ends up in
 * (handoff Sections 13.1 and 13.2).
 *
 * The pages themselves are not here. A plant report is `/plants/<id>` and a
 * garden report is `/gardens/<id>`, both server-rendered and both readable
 * with JavaScript off; what this controller adds is the data behind the
 * charts on them and the download at the bottom.
 *
 * Nothing here reaches a third party. A chart endpoint that fetched weather
 * would be exactly the regression Phase 3 handoff Section 5 rules out: the
 * weather is in the database because a cron put it there.
 */
final class ReportController extends Controller
{
    /**
     * `/api/plant/<id>/series` (handoff Section 13.1).
     *
     * Two statements of data -- one for weather, one for events -- plus the
     * one that loads the planting and proves it is this user's. The scope is
     * the repository base class's, not this file's: an endpoint that assembled
     * its own SQL is how a scoping bug gets in (handoff Section 5).
     */
    public function plantSeries(Request $request, array $params): Response
    {
        return $this->jsonSeries($this->series()->forPlanting(
            (int) $params['id'],
            $this->user()->weatherLocationId,
            $this->today()
        ));
    }

    /** `/api/garden/<id>/series` -- the same document for a whole garden. */
    public function gardenSeries(Request $request, array $params): Response
    {
        return $this->jsonSeries($this->series()->forGarden(
            (int) $params['id'],
            $this->user()->weatherLocationId,
            $this->today()
        ));
    }

    /**
     * `/report/plant/<id>/pdf` (handoff Section 13.2).
     *
     * The browser posts the chart canvases as PNG data URLs and this builds
     * the document around them. Everything in that POST is the browser's
     * word, so it goes through readCharts() before anything decodes it.
     */
    public function plantPdf(Request $request, array $params): Response
    {
        $plantingId = (int) $params['id'];
        $planting = $this->plantings()->findWithDetail($plantingId);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        $user = $this->user();
        $today = $this->today();

        $document = $this->builder()->plant(
            $this->series()->forPlantingRow($planting, $user->weatherLocationId, $today),
            $planting,
            $this->reference()->researchCard((int) $planting['plant_type_id'], $user->regionId),
            $this->events()->timeline($plantingId),
            $this->photos()->forPlanting($plantingId),
            $this->plantings()->yieldSummary($plantingId),
            $this->readCharts($request),
            $this->userId(),
            $today
        );

        return $this->pdf($document, 'carl-plant-' . $plantingId . '-' . $today . '.pdf');
    }

    /** `/report/garden/<id>/pdf` -- the same for a whole garden. */
    public function gardenPdf(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $garden = $this->gardens()->findOrFail($gardenId);

        $today = $this->today();

        $document = $this->builder()->garden(
            $this->series()->forGarden($gardenId, $this->user()->weatherLocationId, $today),
            $garden,
            $this->gardens()->rows($gardenId),
            $this->plantings()->listWithDetail(['garden_id' => $gardenId]),
            $this->events()->gardenTimeline($gardenId),
            $this->photos()->forGarden($gardenId),
            $this->readCharts($request),
            $this->userId(),
            $today
        );

        return $this->pdf($document, 'carl-garden-' . $gardenId . '-' . $today . '.pdf');
    }

    /**
     * @param array<string,mixed> $document
     */
    private function jsonSeries(array $document): Response
    {
        // Personal data. App::decorate() already defaults every response to
        // no-store; this is the same answer said out loud, because a series
        // is the one JSON response a browser would otherwise be tempted to
        // reuse across accounts on a shared device.
        return Response::json($document)->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Handoff Section 13.2 says to stream the file and keep nothing.
     *
     * "Keep nothing" is the part that matters and it holds: no report is
     * written to disk. It is NOT Response::streamed(), though, and the
     * difference is honesty rather than laziness -- FPDF assembles the whole
     * document in its own buffer and hands it over as one string at Output(),
     * so there is nothing to yield in pieces. Wrapping that in a producer
     * would claim a memory profile the library does not have. binary() sends
     * the same bytes and tells the browser their length.
     *
     * `var/reports/` was a directory the deploy created for a file this was
     * once going to write. Nothing writes there, so it is gone from
     * .cpanel.yml rather than left as decoration.
     */
    private function pdf(string $document, string $filename): Response
    {
        return Response::binary($document, 'application/pdf', $filename)
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * The chart canvases, as validated JPEG strings.
     *
     * Everything here is attacker-controlled in the sense that matters: it is
     * a string in a POST body that ends up in front of an image decoder. So
     * the size is capped before anything is decoded (a data URL is base64, so
     * the cap is on the encoded length, which is the cheap thing to check),
     * and Photos::chartJpeg() re-encodes through GD -- which is what turns an
     * SVG, a text file or a decompression bomb claiming to be a PNG into a
     * null rather than into a problem inside FPDF.
     *
     * A chart that fails any of this is simply absent from the report. The
     * tables around it are the same numbers.
     *
     * @return list<string> JPEG bytes, in panel order
     */
    private function readCharts(Request $request): array
    {
        // post_max_size is 8M for the whole request (hosting Section 4), and
        // Section 13.2 says each canvas is well under 2 MB. A base64 data URL
        // is about 4/3 of the bytes it carries.
        $maxEncoded = 2 * 1024 * 1024;

        $out = [];
        foreach (['chart_temp', 'chart_rain', 'chart_et0'] as $field) {
            $value = (string) ($request->post[$field] ?? '');
            if ($value === '' || \strlen($value) > $maxEncoded) {
                continue;
            }
            if (\preg_match('#^data:image/(png|jpeg|webp);base64,#', $value, $m) !== 1) {
                continue;
            }
            $binary = \base64_decode(\substr($value, \strlen($m[0])), true);
            if ($binary === false) {
                continue;
            }
            $jpeg = $this->photoService()->chartJpeg($binary);
            if ($jpeg !== null) {
                $out[] = $jpeg;
            }
        }
        return $out;
    }

    private function builder(): PdfBuilder
    {
        return new PdfBuilder(
            $this->photoService(),
            $this->app->units(),
            new Tokens($this->app->publicPath() . '/assets/css/tokens.css')
        );
    }
}
