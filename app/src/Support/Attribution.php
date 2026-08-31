<?php

declare(strict_types=1);

namespace Carl\Support;

/**
 * The weather credit line (weather.md Section 10).
 *
 * Attribution is required and non-optional, and it is generated from the
 * `source_model` on the rows actually shown rather than hard-coded -- so a
 * report that happens to hold no NCEI rows does not credit NOAA, and one that
 * does cannot forget to.
 *
 * It lives here rather than in the view partial because Phase 4 needs the
 * same sentences in three places: the HTML footer, the JSON series endpoint,
 * and the PDF. Three copies of a licence obligation is two too many.
 *
 * Each credit is split around its link rather than carrying markup, because
 * one of the three consumers is a PDF and one is JSON. The view assembles the
 * anchor; nothing else has to know what an anchor is.
 */
final class Attribution
{
    /**
     * Every model that is not an `ncei:` one is an Open-Meteo model. Written
     * as "not NCEI" rather than as a list of Open-Meteo model names on
     * purpose: the archive changes model names (`era5_seamless`,
     * `best_match`, ...) and a whitelist would silently drop the credit on
     * the day a new one appeared.
     */
    private const NCEI_PREFIX = 'ncei:';

    /**
     * @param list<string> $sourceModels distinct `weather_daily.source_model`
     * @return list<array{before:string,link_text:?string,url:?string,after:string,text:string}>
     */
    public static function of(array $sourceModels): array
    {
        $hasOpenMeteo = false;
        $hasNcei = false;

        foreach ($sourceModels as $model) {
            $model = (string) $model;
            if (\str_starts_with($model, self::NCEI_PREFIX)) {
                $hasNcei = true;
            } elseif ($model !== '') {
                $hasOpenMeteo = true;
            }
        }

        $out = [];
        if ($hasOpenMeteo) {
            $out[] = self::credit(
                'Weather data by ',
                'Open-Meteo.com',
                'https://open-meteo.com/',
                ' (CC BY 4.0), based on ERA5 reanalysis from Copernicus / ECMWF.'
            );
        }
        if ($hasNcei) {
            $out[] = self::credit('Station observations from NOAA NCEI GHCNd (public domain).');
        }
        return $out;
    }

    /**
     * The same credits as plain sentences -- what a PDF footer and a JSON
     * document want. The URL follows the sentence, because neither can click.
     *
     * @param list<string> $sourceModels
     * @return list<string>
     */
    public static function lines(array $sourceModels): array
    {
        return \array_map(
            static fn (array $credit): string => $credit['url'] === null
                ? $credit['text']
                : $credit['text'] . ' (' . $credit['url'] . ')',
            self::of($sourceModels)
        );
    }

    /**
     * @return array{before:string,link_text:?string,url:?string,after:string,text:string}
     */
    private static function credit(
        string $before,
        ?string $linkText = null,
        ?string $url = null,
        string $after = '',
    ): array {
        return [
            'before'    => $before,
            'link_text' => $linkText,
            'url'       => $url,
            'after'     => $after,
            'text'      => $before . ($linkText ?? '') . $after,
        ];
    }
}
