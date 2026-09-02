<?php
/**
 * The destinations (handoff Section 4.2), each with its one-line hint.
 *
 * ONE LIST, RENDERED TWICE. The main menu draws these as tiles, and from
 * Phase 15 the Menu pill in the top bar opens the same list as rows on every
 * signed-in page. Two copies would be two lists, and the drawer is the one
 * that would quietly go stale the next time a screen was added -- so the
 * tiles and the rows are this file, and the container decides the shape.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 */
$e = $view->e(...);

$links = [
    ['plants/new',  'Start a New Plant',   'Seed start, direct sow or transplant'],
    ['log',         'Log Plant Activity',  'Water, yield, pests, cull'],
    ['plants',      'View Plants',         'Timeline and photos'],
    ['calendar',    'Calendar',            'The month ahead, and what is due'],
    ['gardens/new', 'Build Garden',        'Rows, zones and soil'],
    ['gardens',     'Garden Actions',      'Water a zone, mulch, fertilise'],
    ['lists',       'Lists',               'Your seeds, soils, fertilisers'],
    ['pests',       'Pests and diseases',  'What it is, what it costs, what to do'],
    ['reports',     'Reports',             'Charts, PDFs, recommendations and exports'],
    ['tags',        'Plant tags',          'Print QR stakes, scan one to log in two taps'],
];
?>
<?php foreach ($links as [$path, $title, $hint]): ?>
  <a href="<?= $e($app->url($path)) ?>"><?= $e($title) ?>
    <span class="hint"><?= $e($hint) ?></span></a>
<?php endforeach; ?>
