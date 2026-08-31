<?php
/**
 * Garden fields, shared by the onboarding wizard and Build Garden.
 * @var Carl\Core\View $view @var array<string,string> $values @var array<string,string> $soilTypes
 */
$e = $view->e(...);
?>
<div class="field">
  <label for="name">Garden name</label>
  <input type="text" id="name" name="name" value="<?= $e($values['name']) ?>" maxlength="120" required>
</div>

<div class="row">
  <div class="field grow">
    <label for="ns_ft">North-south (ft)</label>
    <input type="number" step="0.1" min="0" id="ns_ft" name="ns_ft" value="<?= $e($values['ns_ft']) ?>">
  </div>
  <div class="field grow">
    <label for="ew_ft">East-west (ft)</label>
    <input type="number" step="0.1" min="0" id="ew_ft" name="ew_ft" value="<?= $e($values['ew_ft']) ?>">
  </div>
</div>

<div class="row">
  <div class="field grow">
    <label for="row_count">Number of rows</label>
    <input type="number" min="0" max="150" id="row_count" name="row_count" value="<?= $e($values['row_count']) ?>">
    <p class="help">Rows are created for you and can be renamed afterwards.</p>
  </div>
  <div class="field grow">
    <label for="row_orientation">Rows run</label>
    <select id="row_orientation" name="row_orientation">
      <option value="ns" <?= $values['row_orientation'] === 'ns' ? 'selected' : '' ?>>North-south</option>
      <option value="ew" <?= $values['row_orientation'] === 'ew' ? 'selected' : '' ?>>East-west</option>
    </select>
  </div>
</div>

<div class="field">
  <label for="soil_type">Soil</label>
  <select id="soil_type" name="soil_type">
    <option value="">-- not sure --</option>
<?php foreach ($soilTypes as $key => $label): ?>
    <option value="<?= $e($key) ?>" <?= $values['soil_type'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
  </select>
  <p class="help">Feeds the watering model later on. A rough answer is better than none.</p>
</div>

<div class="field">
  <label for="notes">Notes</label>
  <textarea id="notes" name="notes"><?= $e($values['notes']) ?></textarea>
</div>
