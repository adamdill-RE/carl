<?php
/**
 * Attribution is required and non-optional (weather.md Section 10), and is
 * generated from source_model on the rows actually shown rather than
 * hard-coded, which keeps it honest.
 *
 * @var Carl\Core\View $view
 * @var list<string> $models
 */
$e = $view->e(...);

$hasOpenMeteo = false;
$hasNcei = false;
foreach ($models as $model) {
    if (\str_starts_with($model, 'ncei:')) {
        $hasNcei = true;
    } else {
        $hasOpenMeteo = true;
    }
}
if ($models === []) {
    return;
}
?>
<p class="tiny">
<?php if ($hasOpenMeteo): ?>
  Weather data by <a href="https://open-meteo.com/" rel="noopener">Open-Meteo.com</a>
  (CC BY 4.0), based on ERA5 reanalysis from Copernicus / ECMWF.
<?php endif; ?>
<?php if ($hasNcei): ?>
  Station observations from NOAA NCEI GHCNd (public domain).
<?php endif; ?>
</p>
