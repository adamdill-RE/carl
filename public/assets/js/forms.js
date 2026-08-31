/*
 * Form behaviour. No build step and no framework (hosting Section 3).
 *
 * Everything here is an enhancement: with JavaScript off, the inline
 * "+ Add new" input is simply always visible, every action's fieldset is
 * shown, and the forms still post correctly. Nothing below is load-bearing.
 */
(function () {
  'use strict';

  var basePath = document.currentScript
    ? document.currentScript.src.replace(/assets\/js\/[^/]*$/, '')
    : '/';

  function csrf() {
    var field = document.querySelector('input[name="_csrf"]');
    return field ? field.value : '';
  }

  /* ---- Inline "+ Add new ..." on every dropdown -------------------- */

  function wireSelectAdd(wrapper) {
    var select = wrapper.querySelector('select');
    var input = wrapper.querySelector('.new-item');
    if (!select || !input) { return; }

    var listType = wrapper.getAttribute('data-list-type') || '';

    function sync() {
      var adding = select.value === '__new';
      input.classList.toggle('hidden', !adding);
      if (adding) { input.focus(); }
    }

    select.addEventListener('change', sync);

    // Create it as soon as the field is left, so the option is really there
    // if the page is submitted or navigated. The server also accepts the
    // typed name directly, so a failure here loses nothing.
    input.addEventListener('blur', function () {
      var name = input.value.trim();
      if (!name || !listType || select.value !== '__new') { return; }

      var body = new URLSearchParams();
      body.set('_csrf', csrf());
      body.set('list_type', listType);
      body.set('name', name);

      fetch(basePath + 'lists/inline', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: body,
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { return; }
          var option = document.createElement('option');
          option.value = data.id;
          option.textContent = data.name;
          select.insertBefore(option, select.querySelector('option[value="__new"]'));
          select.value = String(data.id);
          input.value = '';
          sync();
        })
        .catch(function () { /* the typed name still posts with the form */ });
    });

    sync();
  }

  document.querySelectorAll('.select-add').forEach(wireSelectAdd);

  /* ---- Show only the chosen action's fields ------------------------ */

  var eventType = document.getElementById('event_type');
  var fieldsets = Array.prototype.slice.call(document.querySelectorAll('.event-fields'));

  if (eventType && fieldsets.length) {
    var showForAction = function () {
      var chosen = eventType.value;
      fieldsets.forEach(function (set) {
        var applies = (set.getAttribute('data-for') || '').split(/\s+/);
        set.classList.toggle('hidden', !chosen || applies.indexOf(chosen) === -1);
      });
    };
    eventType.addEventListener('change', showForAction);
    showForAction();
  }

  /* ---- Hardening schedule fills in its own duration ---------------- */

  var schedule = document.getElementById('hardening_schedule_id');
  var hardeningDays = document.getElementById('hardening_days');
  if (schedule && hardeningDays) {
    var applySchedule = function () {
      var option = schedule.options[schedule.selectedIndex];
      var days = option && option.getAttribute('data-days');
      if (days) { hardeningDays.value = days; }
    };
    schedule.addEventListener('change', applySchedule);
    applySchedule();
  }

  /* ---- Category filters the type list ------------------------------ */

  var category = document.getElementById('category');
  var plantType = document.getElementById('plant_type_id');
  var showAll = document.getElementById('show-all-types');

  if (category && plantType) {
    var allOptions = Array.prototype.slice.call(plantType.options).filter(function (o) {
      return o.value !== '';
    });

    var filterTypes = function () {
      var wanted = category.value;
      var everything = showAll ? showAll.checked : true;
      var kept = 0;

      plantType.innerHTML = '';
      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = wanted ? '-- choose a type --' : '-- choose a category first --';
      plantType.appendChild(placeholder);

      allOptions.forEach(function (option) {
        if (wanted && option.getAttribute('data-category') !== wanted) { return; }
        if (!everything && option.getAttribute('data-in-region') !== '1') { return; }
        plantType.appendChild(option);
        kept++;
      });

      // Never leave the list empty just because nothing is researched here.
      if (kept === 0 && wanted) {
        allOptions.forEach(function (option) {
          if (option.getAttribute('data-category') === wanted) { plantType.appendChild(option); }
        });
      }
    };

    category.addEventListener('change', filterTypes);
    if (showAll) { showAll.addEventListener('change', filterTypes); }
    filterTypes();
  }

  /* ---- The research card follows the chosen type ------------------- */

  var researchHolder = document.getElementById('research-card');
  var researchForm = document.querySelector('form[data-research-url]');

  if (researchHolder && plantType && researchForm) {
    var researchUrl = researchForm.getAttribute('data-research-url');
    plantType.addEventListener('change', function () {
      if (!plantType.value) { researchHolder.innerHTML = ''; return; }
      fetch(researchUrl + plantType.value, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      })
        .then(function (r) { return r.ok ? r.text() : ''; })
        .then(function (html) { researchHolder.innerHTML = html; })
        .catch(function () { researchHolder.innerHTML = ''; });
    });
  }

  /* ---- Rows follow the chosen garden ------------------------------- */

  var gardenSelect = document.getElementById('garden_id');
  var rowSelect = document.getElementById('garden_row_id');

  if (gardenSelect && rowSelect) {
    var allRows = Array.prototype.slice.call(rowSelect.options).filter(function (o) {
      return o.value !== '';
    });

    var filterRows = function () {
      var wanted = gardenSelect.value;
      rowSelect.innerHTML = '';
      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = '-- no particular row --';
      rowSelect.appendChild(placeholder);
      allRows.forEach(function (option) {
        if (!wanted || option.getAttribute('data-garden') === wanted) {
          rowSelect.appendChild(option);
        }
      });
    };

    gardenSelect.addEventListener('change', filterRows);
    filterRows();
  }

  /* ---- Crop rotation warning (Phase 5 handoff Section 3.4) ---------- */

  /*
   * A nudge, never a block, and an enhancement like everything else here:
   * with this script off, each row option still reads "Row 3 -- grew
   * Solanaceae in 2025", which is the fact. What the script adds is the
   * meaning for the plant actually chosen, which needs both selects and so
   * cannot live in either one's text.
   *
   * The data is already on the page -- data-family on each type, data-families
   * on each row -- because the alternative is a fetch on every change of
   * either select, for a fact the server already sent.
   */
  var typeSelect = document.getElementById('plant_type_id');
  var rotationBox = document.getElementById('rotation-warning');

  if (typeSelect && rowSelect && rotationBox) {
    var years = rowSelect.getAttribute('data-rotation-years') || '3';

    var chosenFamily = function () {
      var option = typeSelect.options[typeSelect.selectedIndex];
      return option ? (option.getAttribute('data-family') || '') : '';
    };

    var rowFamilies = function () {
      var option = rowSelect.options[rowSelect.selectedIndex];
      var raw = option ? (option.getAttribute('data-families') || '') : '';
      return raw === '' ? [] : raw.split(',');
    };

    var updateRotation = function () {
      var family = chosenFamily();
      var clash = family !== '' && rowFamilies().indexOf(family) !== -1;

      if (!clash) {
        rotationBox.hidden = true;
        rotationBox.textContent = '';
        return;
      }

      var rowOption = rowSelect.options[rowSelect.selectedIndex];
      /* textContent, not innerHTML: the row name and the family are the
       * user's data and the research set's, and this file is not where
       * either becomes markup. */
      rotationBox.textContent =
        'That bed has grown ' + family + ' within the last ' + years +
        ' years (' + (rowOption ? rowOption.textContent.trim() : '') + '). ' +
        'Rotating the family off it for a season or two reduces the soil-borne ' +
        'disease and pest pressure that builds up under a repeat. This is a ' +
        'hint, not a limit -- carry on if you have a reason to.';
      rotationBox.hidden = false;
    };

    typeSelect.addEventListener('change', updateRotation);
    rowSelect.addEventListener('change', updateRotation);
    /* The garden select re-parents the row options, which drops the
     * selection; re-check after it does. */
    if (gardenSelect) { gardenSelect.addEventListener('change', updateRotation); }
    updateRotation();
  }
}());
