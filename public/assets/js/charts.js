/*
 * Report charts (handoff Section 13.1). No build step and no framework
 * (hosting Section 3); Chart.js is a vendored file at assets/vendor/.
 *
 * Three rules shape this file:
 *
 *  1. CSP is script-src 'self' and style-src 'self' with no nonce and no
 *     'unsafe-inline' (hosting Section 8.5). So there is no inline <script>
 *     and no JSON island: the data arrives over fetch from the series
 *     endpoint, and every visual state is a class, never an assignment to
 *     element.style. A violation here is silent in the browser console,
 *     which is exactly why it is written down.
 *  2. ONE weather series visible at a time (weather.md Section 7.3 -- four
 *     overlaid weather variables are unreadable at 380 px and nobody will
 *     pinch-zoom it). All three are drawn, because the PDF wants all three;
 *     the inactive ones are laid out and hidden by CSS, which keeps their
 *     canvases sized so toDataURL() returns a picture rather than a blank.
 *  3. Colours come from tokens.css through getComputedStyle. That file is
 *     the only one in the repository that names a colour (handoff Section
 *     13.5), and a chart palette hard-coded here would quietly become the
 *     second.
 *
 * Everything here is an enhancement. With JavaScript off the report is the
 * totals table and the timeline, which are the same numbers.
 */
(function () {
  'use strict';

  if (typeof window.Chart !== 'function') { return; }   /* vendor file missing */

  var PANELS = [
    { key: 'temp', label: 'Temperature' },
    { key: 'rain', label: 'Rainfall' },
    { key: 'et0',  label: 'ET₀' }
  ];

  /* Read a design token. Falls back to a neutral grey rather than to a
   * colour of its own: an invented colour is a palette, and the palette is
   * Claude Design's to deliver. */
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

  function palette() {
    return {
      hot:    token('--carl-error', '#a32020'),
      cold:   token('--carl-info', '#1f4f77'),
      water:  token('--carl-info', '#1f4f77'),
      et0:    token('--carl-accent', '#7a5a1f'),
      event:  token('--carl-primary', '#2f6b3f'),
      grid:   token('--carl-border', '#d6d6cf'),
      text:   token('--carl-text-muted', '#5c635d')
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

  function commonOptions(colours, labels, byDate, unit) {
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
      scales: {
        x: {
          ticks: {
            color: colours.text, font: { size: 10 },
            maxRotation: 0, autoSkip: true, maxTicksLimit: 6,
            callback: function (value) { return shortDate(labels[value] || ''); }
          },
          grid: { display: false }
        },
        y: {
          title: { display: true, text: unit, color: colours.text, font: { size: 10 } },
          ticks: { color: colours.text, font: { size: 10 }, maxTicksLimit: 5 },
          grid: { color: colours.grid }
        }
      }
    };
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
  function pointFill(days, colour) {
    return days.map(function (d) { return d.provisional ? 'transparent' : colour; });
  }

  function pointSize(days) {
    return days.map(function (d) { return d.provisional ? 2.5 : 0; });
  }

  function barFill(days, colour) {
    var faded = fade(colour, 0.35);
    return days.map(function (d) { return d.provisional ? faded : colour; });
  }

  function build(host, doc) {
    var colours = palette();
    var days = doc.days || [];
    var labels = days.map(function (d) { return d.date; });
    var byDate = eventsByDate(doc.events || []);
    var charts = {};

    /* Events as markers: one point per day that has any, sitting on the
     * series so it reads as "this happened while it was this hot". */
    function eventPoints(valueAt) {
      return days.map(function (d) {
        return byDate[d.date] ? valueAt(d) : null;
      });
    }

    var datasets = {
      temp: [
        {
          label: 'High', data: days.map(function (d) { return d.temp_max; }),
          borderColor: colours.hot, backgroundColor: fade(colours.hot, 0.12),
          fill: '+1', borderWidth: 1.5, tension: 0.2,
          pointRadius: pointSize(days),
          pointStyle: 'circle',
          pointBorderColor: colours.hot,
          pointBackgroundColor: pointFill(days, colours.hot),
          spanGaps: true
        },
        {
          label: 'Low', data: days.map(function (d) { return d.temp_min; }),
          borderColor: colours.cold, backgroundColor: fade(colours.cold, 0.10),
          fill: false, borderWidth: 1.5, tension: 0.2,
          pointRadius: pointSize(days),
          pointBorderColor: colours.cold,
          pointBackgroundColor: pointFill(days, colours.cold),
          spanGaps: true
        },
        {
          type: 'line', label: 'Logged', data: eventPoints(function (d) { return d.temp_max; }),
          borderColor: 'transparent', backgroundColor: colours.event,
          pointRadius: 4, pointHoverRadius: 6, pointStyle: 'triangle', showLine: false
        }
      ],
      rain: [
        {
          type: 'bar', label: 'Rain', data: days.map(function (d) { return d.rain; }),
          backgroundColor: barFill(days, colours.water),
          borderWidth: 0, barPercentage: 1, categoryPercentage: 1
        },
        {
          type: 'line', label: 'Logged', data: eventPoints(function () { return 0; }),
          borderColor: 'transparent', backgroundColor: colours.event,
          pointRadius: 4, pointHoverRadius: 6, pointStyle: 'triangle', showLine: false
        }
      ],
      /* ET0 alone. Rain was overlaid here as a dashed line at first, and at
       * 380px it read as a picket fence of spikes across the panel that the
       * eye could not separate from the ET0 curve -- weather.md Section 7.3's
       * "unreadable at 380 px", seen rather than predicted. The comparison it
       * was for is the water balance, which the totals above the chart give
       * as one number. */
      et0: [
        {
          label: 'ET₀', data: days.map(function (d) { return d.et0; }),
          borderColor: colours.et0, backgroundColor: fade(colours.et0, 0.12),
          fill: 'origin', borderWidth: 1.5, tension: 0.2,
          pointRadius: pointSize(days), pointStyle: 'circle',
          pointBorderColor: colours.et0,
          pointBackgroundColor: pointFill(days, colours.et0),
          spanGaps: true
        }
      ]
    };

    var units = doc.units || {};
    var axisUnit = { temp: units.temperature || '', rain: units.rain || '', et0: units.rain || '' };

    PANELS.forEach(function (panel) {
      var canvas = host.querySelector('[data-chart="' + panel.key + '"]');
      if (!canvas) { return; }
      /* A category axis with pre-formatted labels, not a time axis: a time
       * axis needs a date adapter, which would be a SECOND vendored library,
       * and handoff Section 17 says to ask before adding one. */
      var options = commonOptions(colours, labels, byDate, axisUnit[panel.key]);
      charts[panel.key] = new window.Chart(canvas.getContext('2d'), {
        type: panel.key === 'rain' ? 'bar' : 'line',
        data: { labels: labels.map(shortDate), datasets: datasets[panel.key] },
        options: options
      });
    });

    return charts;
  }

  /* ---- Tabs: one series visible, the rest laid out and hidden ---------- */

  function wireTabs(host) {
    var tabs = host.querySelectorAll('[data-chart-tab]');
    function activate(key) {
      for (var i = 0; i < tabs.length; i++) {
        var on = tabs[i].getAttribute('data-chart-tab') === key;
        tabs[i].classList.toggle('is-active', on);
        tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
      }
      var panels = host.querySelectorAll('[data-chart-panel]');
      for (var j = 0; j < panels.length; j++) {
        panels[j].classList.toggle('is-active',
          panels[j].getAttribute('data-chart-panel') === key);
      }
    }
    for (var k = 0; k < tabs.length; k++) {
      tabs[k].addEventListener('click', function (event) {
        event.preventDefault();
        activate(this.getAttribute('data-chart-tab'));
      });
    }
  }

  /* ---- "Download PDF": post the canvases up (handoff Section 13.2) ----- */

  function wirePdf(host, charts) {
    var form = host.querySelector('[data-chart-pdf]');
    if (!form) { return; }

    form.addEventListener('submit', function () {
      /* Every drawn chart, not only the visible one: the reader of a PDF is
       * not toggling tabs. The inactive panels are hidden by visibility and
       * still have a laid-out canvas, so each one has really been painted.
       *
       * PNG, and well under 2 MB each (Section 13.2) -- a 3x canvas at this
       * size is about 40 KB, and post_max_size is 8M for all of them plus
       * the form. */
      PANELS.forEach(function (panel) {
        var chart = charts[panel.key];
        var field = form.querySelector('[name="chart_' + panel.key + '"]');
        if (!chart || !field) { return; }
        try {
          field.value = chart.canvas.toDataURL('image/png');
        } catch (e) {
          field.value = '';   /* a tainted or zero-sized canvas: send nothing */
        }
      });
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
      if (!doc.days || doc.days.length === 0) {
        if (status) { status.textContent = 'No weather has been fetched for these dates yet.'; }
        return;
      }
      host.classList.add('is-ready');
      if (status) { status.textContent = ''; }
      var charts = build(host, doc);
      wireTabs(host);
      wirePdf(host, charts);
    }).catch(function () {
      /* The totals above this are the same data, so the chart failing is a
       * missing picture, not a missing report. Say so and stop. */
      if (status) { status.textContent = 'The chart could not be loaded. The totals above are the same data.'; }
    });
  }

  var hosts = document.querySelectorAll('[data-charts]');
  for (var i = 0; i < hosts.length; i++) { start(hosts[i]); }
})();
