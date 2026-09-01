/*
 * Report charts (handoff Section 13.1). No build step and no framework
 * (hosting Section 3); Chart.js is a vendored file at assets/vendor/.
 *
 * Four rules shape this file:
 *
 *  1. CSP is script-src 'self' and style-src 'self' with no nonce and no
 *     'unsafe-inline' (hosting Section 8.5). So there is no inline <script>
 *     and no JSON island: the data arrives over fetch from the series
 *     endpoint, and every visual state is a class, never an assignment to
 *     element.style. A violation here is silent in the browser console,
 *     which is exactly why it is written down.
 *  2. **Weather is context, not the subject** (weather.md Section 7.3). The
 *     plant's own numbers hold the left axis at full strength; the weather
 *     goes on the right, muted, thinner, behind. Until Phase 12 this file had
 *     it the other way round -- three weather panels with the plant reduced
 *     to identical triangles -- because until size (migration 024) the plant
 *     had almost nothing of its own to draw.
 *  3. **One subject series and one context series, never more.** Section
 *     7.3's Phase 4 annotation records that two WEATHER series overlaid were
 *     unreadable at 380 px: daily rainfall against a smooth ET0 curve read as
 *     a picket fence the eye could not separate from the curve. That finding
 *     is why the pickers offer one of each rather than a set of checkboxes.
 *     Growth is the one pair drawn together, because height and diameter
 *     share an axis and a unit and are the same measurement of one plant.
 *  4. Colours come from tokens.css through getComputedStyle. That file is
 *     the only one in the repository that names a colour (handoff Section
 *     13.5), and a chart palette hard-coded here would quietly become the
 *     second. Nothing here adds a colour: the subject borrows
 *     --carl-chart-event, which is already named for the plant's own record.
 *
 * Everything here is an enhancement. With JavaScript off the report is the
 * totals table and the timeline, which are the same numbers.
 */
(function () {
  'use strict';

  if (typeof window.Chart !== 'function') { return; }   /* vendor file missing */

  /* The three panels the PDF posts (handoff Section 13.2). Drawn always,
   * hidden always, never tabbed: the same three views are one pick away in
   * the "Against" list, and six tabs do not fit across 380 px. */
  var PDF_PANELS = ['temp', 'rain', 'et0'];

  /* ---- What can be drawn ---------------------------------------------- */

  /* `need` is the key in doc.plant.has that decides whether the option is
   * offered at all. A picker that lists Height for a plant nobody has
   * measured is a menu of empty charts. */
  var PLANT_LAYERS = [
    { key: 'none',             label: 'Weather only',       need: null },
    { key: 'height',           label: 'Height',             need: 'height',   axis: 'size',   kind: 'line', pair: 'diameter' },
    { key: 'diameter',         label: 'Diameter',           need: 'diameter', axis: 'size',   kind: 'line' },
    { key: 'yield_cumulative', label: 'Harvest to date',    need: 'yield',    axis: 'weight', kind: 'line' },
    { key: 'yield',            label: 'Harvest, per pick', need: 'yield',    axis: 'weight', kind: 'bar' },
    { key: 'yield_count',      label: 'Fruit picked',       need: 'yield',    axis: 'count',  kind: 'bar' },
    { key: 'water_min',        label: 'Watering',           need: 'water',    axis: 'minutes', kind: 'bar' }
  ];

  var WEATHER_LAYERS = [
    { key: 'none',    label: 'Nothing',              kind: 'line' },
    { key: 'temp',    label: 'Temperature',          kind: 'band' },
    { key: 'rain',    label: 'Rainfall',             kind: 'bar' },
    { key: 'et0',     label: 'Evapotranspiration',   kind: 'line' },
    { key: 'balance', label: 'Water balance',        kind: 'line' },
    { key: 'gdd',     label: 'Growing degree days',  kind: 'line' }
  ];

  /* The presets: the "pertinent static charts" a reader should land on
   * without having built anything. Each is just a pair of picks, so a preset
   * can never show something the pickers cannot reproduce. */
  var PRESETS = [
    { key: 'growth',  label: 'Growth',  plant: 'height',           weather: 'temp', need: ['height', 'diameter'] },
    { key: 'harvest', label: 'Harvest', plant: 'yield_cumulative', weather: 'rain', need: ['yield'] },
    { key: 'water',   label: 'Water',   plant: 'water_min',        weather: 'rain', need: ['water'] },
    { key: 'weather', label: 'Weather', plant: 'none',             weather: 'temp', need: ['weather'] },
    { key: 'compare', label: 'Compare', compare: true,             need: ['height', 'yield'] }
  ];

  /* Read a design token, falling back to the delivered value rather than to
   * an invented one (handoff Section 13.5, built Phase 10). The fallbacks
   * below are the real palette, so a failed lookup degrades to the right
   * colour instead of to a scheme that no longer exists.
   *
   * The trim is load-bearing: getComputedStyle returns a custom property with
   * its leading whitespace intact, and Chart.js takes " #b8460b" as invalid
   * and silently draws its own default instead. */
  function token(name, fallback) {
    var value = getComputedStyle(document.documentElement).getPropertyValue(name);
    value = (value || '').trim();
    return value === '' ? fallback : value;
  }

  /* Chart.js wants rgba() for a translucent fill and tokens are hex. */
  function fade(hex, alpha) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex);
    if (!m) { return hex; }
    var h = m[1];
    if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
    return 'rgba(' + parseInt(h.slice(0, 2), 16) + ','
      + parseInt(h.slice(2, 4), 16) + ',' + parseInt(h.slice(4, 6), 16) + ',' + alpha + ')';
  }

  /* The series have their own tokens rather than borrowing the status ones
   * (handoff Section 13.5, Claude Design). Three things that bought:
   * --carl-accent is now ONLY the focus ring, where before it was also the
   * ET0 line and was being tuned against two incompatible briefs; a warm day
   * is no longer painted in --carl-error, which said "failure" about weather;
   * and low temperature and rainfall are no longer the same colour, which
   * they had been since Phase 4 without anybody deciding it.
   *
   * The set is built from Okabe-Ito and checked under deuteranope and
   * protanope simulation, so DO NOT substitute a status token back in here
   * for convenience -- and if a sixth series is ever needed, add a
   * --carl-chart-* rather than borrowing --carl-accent again.
   *
   * PHASE 12 ADDS NO COLOUR. The subject line is --carl-chart-event, which is
   * already the token named for the plant's own record, and the second
   * subject series -- diameter beside height -- is --carl-primary. A
   * dedicated --carl-chart-subject would be tidier and is a design ask, not
   * something to invent here.
   *
   * The fallbacks are the delivered palette, so a failed getComputedStyle
   * degrades to the right colours rather than to the 2026 placeholder. */
  function palette() {
    return {
      hot:     token('--carl-chart-hot', '#b8460b'),
      cold:    token('--carl-chart-cold', '#00509a'),
      water:   token('--carl-chart-water', '#0e8577'),
      et0:     token('--carl-chart-et0', '#8d5bb5'),
      event:   token('--carl-chart-event', '#2f3a33'),
      subject: token('--carl-chart-event', '#2f3a33'),
      second:  token('--carl-primary', '#265c37'),
      grid:    token('--carl-chart-grid', '#d3d1c5'),
      text:    token('--carl-chart-axis', '#545a52')
    };
  }

  /* "2026-04-15" -> "15 Apr". Deliberately not toLocaleDateString: the server
   * writes every other date on the page with Units::shortDate(), and two date
   * formats on one screen reads as a bug. */
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function shortDate(ymd) {
    var parts = String(ymd).split('-');
    if (parts.length !== 3) { return ymd; }
    return parseInt(parts[2], 10) + ' ' + (MONTHS[parseInt(parts[1], 10) - 1] || '');
  }

  /* Events land on the day they happened. A plant can have several on one
   * day (watered and fertilised), so they are collapsed to one marker whose
   * tooltip lists them -- forty dots stacked on one x is not information. */
  function eventsByDate(events) {
    var byDate = {};
    for (var i = 0; i < events.length; i++) {
      var e = events[i];
      if (!byDate[e.date]) { byDate[e.date] = []; }
      byDate[e.date].push(e.label + (e.note ? ' -- ' + e.note : ''));
    }
    return byDate;
  }

  function find(list, key) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].key === key) { return list[i]; }
    }
    return null;
  }

  /*
   * Provisional days (weather.md Section 6.2, handoff Section 13.1). A day
   * inside the revision window can still change, and a chart that does not
   * say so is claiming more than it knows.
   *
   * Marked on EVERY panel, not only on the temperature one: the note printed
   * under the chart says the provisional days are marked, and that note sits
   * under whichever panel is showing. A line gets a hollow point, a bar gets
   * a faded fill. The count in words is beside it, because a point style is
   * not a sentence.
   */
  function pointFill(flags, colour) {
    return flags.map(function (p) { return p ? 'transparent' : colour; });
  }

  function pointSize(flags) {
    return flags.map(function (p) { return p ? 2.5 : 0; });
  }

  function barFill(flags, colour) {
    var faded = fade(colour, 0.35);
    return flags.map(function (p) { return p ? faded : colour; });
  }

  /* ---- Axis titles ----------------------------------------------------- */

  function axisTitle(kind, doc) {
    var u = (doc.plant && doc.plant.units) || {};
    var w = doc.units || {};
    return {
      size:    u.size || '',
      weight:  u.weight || '',
      count:   'fruit',
      minutes: 'minutes',
      temp:    w.temperature || '',
      rain:    w.rain || '',
      et0:     w.rain || '',
      balance: w.rain || '',
      gdd:     'GDD, base ' + (u.gdd_base || '')
    }[kind] || '';
  }

  /* ---- The datasets ---------------------------------------------------- */

  /* The subject: full strength, left axis, drawn last so it is on top.
   * `order` is Chart.js's z-order and it is inverted -- a LOWER order draws
   * in front -- which is the sort of thing that looks like a bug when the
   * weather ends up over the plant. */
  /*
   * A point where a reading was TAKEN, and nowhere else.
   *
   * Height and diameter are null on the days nobody measured, so Chart.js
   * already draws nothing there. `yield_cumulative` is not: it carries a
   * value every day of the season because that is what "to date" means, and
   * a point on every one of ninety days turns a step line into a caterpillar.
   * The days that are a harvest are the days the per-pick series is above
   * zero, which is the same list the bars draw.
   */
  function subjectPointRadius(layer, plant) {
    if (layer.key !== 'yield_cumulative') { return 3; }
    return plant.yield.map(function (v) { return v > 0 ? 3 : 0; });
  }

  function subjectDatasets(layer, plant, colours) {
    if (!layer || layer.key === 'none') { return []; }

    var flags = plant.provisional || [];
    var keys = [layer.key];
    var labels = [layer.label];
    var colourList = [colours.subject];

    /* Height and diameter together: the one pair that shares an axis and a
     * unit, and the only place two subject series are drawn at once. */
    if (layer.pair && plant.has && plant.has[layer.pair]) {
      keys.push(layer.pair);
      labels.push(find(PLANT_LAYERS, layer.pair).label);
      colourList.push(colours.second);
    }

    return keys.map(function (key, i) {
      var colour = colourList[i];
      if (layer.kind === 'bar') {
        return {
          type: 'bar', label: labels[i], data: plant[key], yAxisID: 'y', order: 0,
          backgroundColor: barFill(flags, colour), borderWidth: 0,
          barPercentage: 1, categoryPercentage: 1
        };
      }
      return {
        type: 'line', label: labels[i], data: plant[key], yAxisID: 'y', order: 0,
        borderColor: colour, backgroundColor: fade(colour, 0.10),
        borderWidth: 2, tension: 0.25, fill: false, spanGaps: true,
        /* A measurement is a reading taken on a day, not a continuous curve,
         * so every point it has is drawn -- unlike a weather line, where a
         * point per day is 1,100 dots. */
        pointRadius: subjectPointRadius(layer, plant), pointHoverRadius: 5,
        pointBorderColor: colour, pointBackgroundColor: colour
      };
    });
  }

  /* The context: right axis when there is a subject, left when there is not.
   * Muted, thinner and behind, per weather.md Section 7.3 -- "never competing
   * with the performance line for attention". */
  function contextDatasets(layer, plant, colours, muted) {
    if (!layer || layer.key === 'none') { return []; }

    var flags = plant.provisional || [];
    var axis = muted ? 'y1' : 'y';
    var order = muted ? 3 : 1;
    var alpha = muted ? 0.45 : 1;
    var width = muted ? 1 : 1.5;

    function line(key, label, colour, dash) {
      return {
        type: 'line', label: label, data: plant[key], yAxisID: axis, order: order,
        borderColor: muted ? fade(colour, alpha) : colour,
        backgroundColor: fade(colour, muted ? 0.06 : 0.12),
        borderWidth: width, borderDash: dash || [], tension: 0.2,
        fill: muted ? false : 'origin', spanGaps: true,
        pointRadius: muted ? 0 : pointSize(flags), pointStyle: 'circle',
        pointBorderColor: colour, pointBackgroundColor: pointFill(flags, colour)
      };
    }

    if (layer.key === 'temp') {
      /* The band. Muted it is two hairlines with a wash between them, which
       * is exactly the "background band" Section 7.3 asks for; unmuted it is
       * the temperature chart this file has drawn since Phase 4. */
      var high = line('temp_max', 'High', colours.hot);
      var low = line('temp_min', 'Low', colours.cold);
      /* The wash is BETWEEN the two lines and nowhere else: '+1' fills the
       * high line down to the next dataset, which is the low one. Filling the
       * low line to the origin as well paints a second block under it that
       * says nothing -- the band is the day's range, and the space below it
       * is not part of the day. */
      high.fill = '+1';
      high.backgroundColor = fade(colours.hot, muted ? 0.08 : 0.12);
      low.fill = false;
      return [high, low];
    }
    if (layer.key === 'rain') {
      return [{
        type: 'bar', label: 'Rain', data: plant.rain, yAxisID: axis, order: order + 1,
        backgroundColor: muted
          ? plant.provisional.map(function (p) { return fade(colours.water, p ? 0.15 : 0.30); })
          : barFill(flags, colours.water),
        borderWidth: 0, barPercentage: 1, categoryPercentage: 1
      }];
    }
    if (layer.key === 'et0') {
      /* Dashed on Claude Design's ask, and kept: it distinguishes the one
       * series that is not a temperature at a glance. */
      return [line('et0', 'ET₀', colours.et0, [5, 4])];
    }
    if (layer.key === 'balance') {
      return [line('balance', 'Rain minus ET₀', colours.water, [2, 3])];
    }
    if (layer.key === 'gdd') {
      return [line('gdd', 'GDD to date', colours.et0)];
    }
    return [];
  }

  /* ---- Options --------------------------------------------------------- */

  function options(colours, labels, byDate, leftTitle, rightTitle) {
    var scales = {
      x: {
        ticks: {
          color: colours.text, font: { size: 10 },
          maxRotation: 0, autoSkip: true, maxTicksLimit: 6,
          callback: function (value) { return shortDate(labels[value] || ''); }
        },
        grid: { display: false }
      },
      y: {
        position: 'left',
        title: { display: leftTitle !== '', text: leftTitle, color: colours.text, font: { size: 10 } },
        ticks: { color: colours.text, font: { size: 10 }, maxTicksLimit: 5 },
        grid: { color: colours.grid }
      }
    };

    if (rightTitle !== null) {
      scales.y1 = {
        position: 'right',
        title: { display: true, text: rightTitle, color: colours.text, font: { size: 10 } },
        ticks: { color: colours.text, font: { size: 10 }, maxTicksLimit: 5 },
        /* No second grid. Two sets of horizontal rules at different spacings
         * is the thing that makes a dual-axis chart unreadable, and the
         * context series is not being read off a gridline anyway. */
        grid: { drawOnChartArea: false }
      };
    }

    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      normalized: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: true,
          /* usePointStyle, or the 'Logged' swatch is a square while the
           * markers on the chart are triangles -- a legend that does not
           * match the marks is worse than none. */
          labels: { usePointStyle: true, boxWidth: 10, color: colours.text, font: { size: 11 } }
        },
        tooltip: {
          callbacks: {
            afterBody: function (items) {
              if (!items.length) { return ''; }
              var date = labels[items[0].dataIndex];
              var here = byDate[date];
              return here ? here : '';
            }
          }
        }
      },
      scales: scales
    };
  }

  /* ---- The layered panel ----------------------------------------------- */

  function drawBuild(host, doc, state, charts) {
    var canvas = host.querySelector('[data-chart="build"]');
    if (!canvas) { return; }

    var colours = palette();
    var plant = doc.plant;
    var labels = plant.dates;
    var byDate = eventsByDate(doc.events || []);

    var plantLayer = find(PLANT_LAYERS, state.plant);
    var weatherLayer = find(WEATHER_LAYERS, state.weather);
    var hasSubject = plantLayer !== null && plantLayer.key !== 'none';

    var datasets = subjectDatasets(plantLayer, plant, colours)
      .concat(contextDatasets(weatherLayer, plant, colours, hasSubject));

    if (datasets.length === 0) {
      /* Both pickers on "nothing" is a legitimate thing to do by accident and
       * an empty canvas says nothing about why. */
      datasets = contextDatasets(find(WEATHER_LAYERS, 'temp'), plant, colours, false);
    }

    /* Logged actions, as a rug along the FLOOR of the subject's axis.
     *
     * They used to sit on the series itself -- a triangle at the day's
     * temperature -- which worked when the series was the weather and had a
     * value every single day. A subject does not: a plant is measured eight
     * times in a season, so a marker placed on the height line appears only
     * on the days that already have a dot, and every watering, every
     * fertilising and every pest treatment is silently dropped. On the floor
     * they are all there, they line up under the day they happened, and the
     * tooltip says what each one was. */
    if (hasSubject) {
      var floor = 0;
      var carrier = plant[plantLayer.key];
      for (var f = 0; f < carrier.length; f++) {
        if (carrier[f] !== null && carrier[f] < floor) { floor = carrier[f]; }
      }
      datasets.push({
        type: 'line', label: 'Logged',
        data: labels.map(function (d) { return byDate[d] ? floor : null; }),
        yAxisID: 'y', order: 2,
        borderColor: 'transparent', backgroundColor: colours.event,
        pointRadius: 4, pointHoverRadius: 6, pointStyle: 'triangle', showLine: false
      });
    }

    var leftTitle = hasSubject ? axisTitle(plantLayer.axis, doc)
      : axisTitle(weatherLayer && weatherLayer.key !== 'none' ? weatherLayer.key : 'temp', doc);
    var rightTitle = hasSubject && weatherLayer && weatherLayer.key !== 'none'
      ? axisTitle(weatherLayer.key, doc) : null;

    if (charts.build) { charts.build.destroy(); }
    charts.build = new window.Chart(canvas.getContext('2d'), {
      /* A category axis with pre-formatted labels, not a time axis: a time
       * axis needs a date adapter, which would be a SECOND vendored library,
       * and handoff Section 17 says to ask before adding one. */
      type: 'line',
      data: { labels: labels.map(shortDate), datasets: datasets },
      options: options(colours, labels, byDate, leftTitle, rightTitle)
    });
  }

  /* ---- Compare: one measurement against the weather before it ----------- */

  /*
   * A time-series overlay shows two things moving together. It does not show
   * whether they move together, because the eye reads any two rising lines as
   * agreement. This does: each point is one harvest (or one measurement)
   * against the weather over the days LEADING UP TO IT.
   *
   * The lag is the point, and it is adjustable rather than fixed at seven
   * days -- weather.md Section 7.2: "different responses have different
   * memories -- heat stress shows in days, water stress in weeks."
   */
  function windowValue(plant, key, index, lag) {
    var from = Math.max(0, index - lag);
    var n = 0;
    var sum = 0;

    if (key === 'gdd') {
      /* Accumulated, so the window is a difference and not a sum of a sum. */
      var end = plant.gdd[index];
      var start = plant.gdd[from];
      return (end === null || start === null) ? null : Math.round((end - start) * 10) / 10;
    }

    for (var i = from; i <= index; i++) {
      var day = key === 'temp'
        ? (plant.temp_max[i] === null || plant.temp_min[i] === null
            ? null : (plant.temp_max[i] + plant.temp_min[i]) / 2)
        : plant[key][i];
      if (day === null || day === undefined) { continue; }
      sum += day;
      n++;
    }
    if (n === 0) { return null; }
    /* A mean for a temperature, a total for anything that accumulates. */
    return key === 'temp' ? Math.round((sum / n) * 10) / 10 : Math.round(sum * 100) / 100;
  }

  /* Pearson's r. Printed with n beside it and a sentence saying what six
   * points are worth, because a coefficient with no n is a claim. */
  function pearson(points) {
    var n = points.length;
    if (n < 3) { return null; }
    var sx = 0, sy = 0;
    points.forEach(function (p) { sx += p.x; sy += p.y; });
    var mx = sx / n, my = sy / n;
    var num = 0, dx = 0, dy = 0;
    points.forEach(function (p) {
      num += (p.x - mx) * (p.y - my);
      dx += (p.x - mx) * (p.x - mx);
      dy += (p.y - my) * (p.y - my);
    });
    if (dx === 0 || dy === 0) { return null; }
    return num / Math.sqrt(dx * dy);
  }

  function drawCompare(host, doc, state, charts) {
    var canvas = host.querySelector('[data-chart="compare"]');
    if (!canvas) { return; }

    var colours = palette();
    var plant = doc.plant;
    var note = host.querySelector('[data-chart-note]');

    var plantLayer = find(PLANT_LAYERS, state.plant);
    var weatherLayer = find(WEATHER_LAYERS, state.weather);
    if (!plantLayer || plantLayer.key === 'none' || !weatherLayer || weatherLayer.key === 'none') {
      if (note) { note.textContent = 'Pick something to show and something to compare it against.'; }
      return;
    }

    var lag = parseInt(state.lag, 10) || 0;
    var values = plant[plantLayer.key] || [];
    var points = [];

    for (var i = 0; i < values.length; i++) {
      var y = values[i];
      /* Zero is a real harvest day only on the cumulative line; on a per-pick
       * bar it is a day nothing was picked, and plotting three hundred zeroes
       * against the weather is a cloud that says nothing. */
      if (y === null || y === undefined) { continue; }
      if (y === 0 && plantLayer.key !== 'yield_cumulative') { continue; }
      var x = windowValue(plant, weatherLayer.key, i, lag);
      if (x === null) { continue; }
      points.push({ x: x, y: y, date: plant.dates[i] });
    }

    if (charts.compare) { charts.compare.destroy(); }
    charts.compare = new window.Chart(canvas.getContext('2d'), {
      type: 'scatter',
      data: {
        datasets: [{
          label: plantLayer.label + ' vs ' + weatherLayer.label,
          data: points,
          backgroundColor: colours.subject,
          borderColor: colours.subject,
          pointRadius: 5, pointHoverRadius: 7
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (item) {
                var p = points[item.dataIndex];
                return shortDate(p.date) + ': ' + p.y + ' ' + axisTitle(plantLayer.axis, doc)
                  + ' after ' + p.x + ' ' + axisTitle(weatherLayer.key, doc);
              }
            }
          }
        },
        scales: {
          x: {
            title: {
              display: true, color: colours.text, font: { size: 10 },
              text: weatherLayer.label + ' ' + (lag === 0 ? 'that day' : 'over ' + lag + ' days')
                + ' (' + axisTitle(weatherLayer.key, doc) + ')'
            },
            ticks: { color: colours.text, font: { size: 10 }, maxTicksLimit: 6 },
            grid: { color: colours.grid }
          },
          y: {
            title: {
              display: true, text: plantLayer.label + ' (' + axisTitle(plantLayer.axis, doc) + ')',
              color: colours.text, font: { size: 10 }
            },
            ticks: { color: colours.text, font: { size: 10 }, maxTicksLimit: 5 },
            grid: { color: colours.grid }
          }
        }
      }
    });

    if (note) {
      var r = pearson(points);
      if (points.length < 3) {
        note.textContent = points.length + ' point'
          + (points.length === 1 ? '' : 's') + ' is not a shape. Log a few more and come back.';
      } else if (r === null) {
        note.textContent = points.length + ' points, but one of the two never varies, '
          + 'so there is nothing to correlate.';
      } else {
        /* The caveat is not decoration. A garden season is a handful of
         * harvests, every one of them under weather that is itself
         * correlated with the time of year, so r here is a shape worth
         * looking at and never a finding. Saying so is the honest version of
         * printing the number at all. */
        note.textContent = 'r = ' + r.toFixed(2) + ' across ' + points.length + ' points. '
          + 'With this few, and with the season moving underneath them, that is a shape '
          + 'to look at rather than a result.';
      }
    }
  }

  /* ---- The three the PDF posts ----------------------------------------- */

  function drawPdfPanels(host, doc, charts) {
    var colours = palette();
    var plant = doc.plant;
    var labels = plant.dates;
    var byDate = eventsByDate(doc.events || []);

    PDF_PANELS.forEach(function (key) {
      var canvas = host.querySelector('[data-chart="' + key + '"]');
      if (!canvas) { return; }
      var datasets = contextDatasets(find(WEATHER_LAYERS, key), plant, colours, false);
      datasets.push({
        type: 'line', label: 'Logged',
        data: labels.map(function (d) { return byDate[d] ? 0 : null; }),
        borderColor: 'transparent', backgroundColor: colours.event,
        pointRadius: 4, pointHoverRadius: 6, pointStyle: 'triangle', showLine: false
      });
      charts[key] = new window.Chart(canvas.getContext('2d'), {
        type: key === 'rain' ? 'bar' : 'line',
        data: { labels: labels.map(shortDate), datasets: datasets },
        options: options(colours, labels, byDate, axisTitle(key, doc), null)
      });
    });
  }

  /* ---- The controls ---------------------------------------------------- */

  function fillSelect(select, list, has, chosen) {
    select.textContent = '';
    var offered = 0;
    list.forEach(function (layer) {
      if (layer.need && !(has && has[layer.need])) { return; }
      var option = document.createElement('option');
      option.value = layer.key;
      option.textContent = layer.label;
      if (layer.key === chosen) { option.selected = true; }
      select.appendChild(option);
      offered++;
    });
    return offered;
  }

  function buildTabs(host, doc, presets, activate) {
    var row = host.querySelector('[data-chart-tabs]');
    if (!row) { return; }
    row.textContent = '';
    presets.forEach(function (preset, i) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'chart-tab' + (i === 0 ? ' is-active' : '');
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
      button.setAttribute('data-chart-tab', preset.key);
      button.textContent = preset.label;
      button.addEventListener('click', function () { activate(preset.key); });
      row.appendChild(button);
    });
  }

  /* ---- Boot ------------------------------------------------------------ */

  function start(host) {
    var url = host.getAttribute('data-series-url');
    if (!url) { return; }

    var status = host.querySelector('[data-chart-status]');

    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      if (!response.ok) { throw new Error('series ' + response.status); }
      return response.json();
    }).then(function (doc) {
      var plant = doc.plant;
      if (!plant || !plant.dates || plant.dates.length === 0) {
        if (status) { status.textContent = 'There is nothing to chart for these dates yet.'; }
        return;
      }
      host.classList.add('is-ready');
      if (status) { status.textContent = ''; }
      wire(host, doc);
    }).catch(function () {
      /* The totals above this are the same data, so the chart failing is a
       * missing picture, not a missing report. Say so and stop. */
      if (status) { status.textContent = 'The chart could not be loaded. The totals above are the same data.'; }
    });
  }

  function wire(host, doc) {
    var plant = doc.plant;
    var has = plant.has || {};
    var charts = {};

    /* A preset is offered when the subject has at least one of the things it
     * draws. A garden has no height and no harvest, so it lands on Weather
     * and the Growth tab is simply not there. */
    var presets = PRESETS.filter(function (preset) {
      return preset.need.some(function (key) { return has[key]; });
    });
    if (presets.length === 0) { return; }

    var plantSelect = host.querySelector('[data-chart-pick="plant"]');
    var weatherSelect = host.querySelector('[data-chart-pick="weather"]');
    var lagSelect = host.querySelector('[data-chart-pick="lag"]');
    var lagField = host.querySelector('[data-chart-lag]');
    var layers = host.querySelector('[data-chart-layers]');
    var note = host.querySelector('[data-chart-note]');

    var state = {
      plant: presets[0].plant || 'none',
      weather: presets[0].weather || 'temp',
      lag: lagSelect ? lagSelect.value : '7',
      panel: 'build'
    };

    fillSelect(plantSelect, PLANT_LAYERS, has, state.plant);
    fillSelect(weatherSelect, WEATHER_LAYERS, has, state.weather);
    if (layers) { layers.hidden = false; }

    function showPanel(key) {
      var panels = host.querySelectorAll('[data-chart-panel]');
      for (var i = 0; i < panels.length; i++) {
        panels[i].classList.toggle('is-active',
          panels[i].getAttribute('data-chart-panel') === key);
      }
    }

    function markTab(key) {
      var tabs = host.querySelectorAll('[data-chart-tab]');
      for (var i = 0; i < tabs.length; i++) {
        var on = tabs[i].getAttribute('data-chart-tab') === key;
        tabs[i].classList.toggle('is-active', on);
        tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
      }
    }

    function redraw() {
      if (lagField) { lagField.hidden = state.panel !== 'compare'; }
      /* Nothing on a scatter is faded, so the note that says some days are
       * would be describing a chart that is not on screen. */
      var provisional = host.querySelector('[data-chart-provisional]');
      if (provisional) { provisional.hidden = state.panel === 'compare'; }
      if (state.panel === 'compare') {
        showPanel('compare');
        drawCompare(host, doc, state, charts);
      } else {
        showPanel('build');
        if (note) { note.textContent = ''; }
        drawBuild(host, doc, state, charts);
      }
    }

    function activate(key) {
      var preset = find(presets, key);
      if (!preset) { return; }
      markTab(key);
      state.panel = preset.compare ? 'compare' : 'build';
      if (!preset.compare) {
        state.plant = preset.plant;
        state.weather = preset.weather;
      } else if (state.plant === 'none') {
        /* Compare needs a subject. Land on the first one the plant has
         * rather than on an empty scatter with a scolding under it. */
        state.plant = has.yield ? 'yield' : (has.height ? 'height' : 'none');
      }
      if (plantSelect) { plantSelect.value = state.plant; }
      if (weatherSelect) { weatherSelect.value = state.weather; }
      redraw();
    }

    buildTabs(host, doc, presets, activate);

    /* Changing a picker moves off the preset unless it lands back on one:
     * a tab left highlighted while the chart shows something else is a lie
     * about what is on screen. */
    function pickerChanged() {
      state.plant = plantSelect ? plantSelect.value : state.plant;
      state.weather = weatherSelect ? weatherSelect.value : state.weather;
      var matched = null;
      presets.forEach(function (preset) {
        if (!preset.compare && preset.plant === state.plant && preset.weather === state.weather) {
          matched = preset.key;
        }
      });
      markTab(state.panel === 'compare' ? 'compare' : matched);
      redraw();
    }

    if (plantSelect) { plantSelect.addEventListener('change', pickerChanged); }
    if (weatherSelect) { weatherSelect.addEventListener('change', pickerChanged); }
    if (lagSelect) {
      lagSelect.addEventListener('change', function () {
        state.lag = lagSelect.value;
        redraw();
      });
    }

    drawPdfPanels(host, doc, charts);
    activate(presets[0].key);
    wirePdf(host, charts);
  }

  /* ---- "Download PDF": post the canvases up (handoff Section 13.2) ----- */

  function wirePdf(host, charts) {
    var form = host.querySelector('[data-chart-pdf]');
    if (!form) { return; }

    form.addEventListener('submit', function () {
      /* The three weather panels, not whatever is on screen: the report is a
       * fixed document and a reader of a PDF is not toggling tabs. They are
       * hidden by visibility and still have a laid-out canvas, so each one
       * has really been painted.
       *
       * PNG, and well under 2 MB each (Section 13.2) -- a 3x canvas at this
       * size is about 40 KB, and post_max_size is 8M for all of them plus
       * the form. */
      PDF_PANELS.forEach(function (key) {
        var chart = charts[key];
        var field = form.querySelector('[name="chart_' + key + '"]');
        if (!chart || !field) { return; }
        try {
          field.value = chart.canvas.toDataURL('image/png');
        } catch (e) {
          field.value = '';   /* a tainted or zero-sized canvas: send nothing */
        }
      });
    });
  }

  var hosts = document.querySelectorAll('[data-charts]');
  for (var i = 0; i < hosts.length; i++) { start(hosts[i]); }
})();
