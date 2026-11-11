/* Dashboard charts (Chart.js). Single-series charts in the brand teal; thin marks, recessive grid. */
(function () {
  if (!window.Chart || !window.DASH) return;
  var D = window.DASH;
  var TEAL = '#2a759a', INK = '#1e3a4c', MUTED = '#5f7383', GRID = '#e6eff4';
  Chart.defaults.font.family = '"Plus Jakarta Sans", system-ui, sans-serif';
  Chart.defaults.font.size = 11;
  Chart.defaults.color = MUTED;

  var tooltip = {
    backgroundColor: '#ffffff', titleColor: INK, bodyColor: INK, borderColor: '#d3e5ee', borderWidth: 1,
    padding: 10, displayColors: false, titleFont: { weight: '700' }
  };
  function axes(opts) {
    opts = opts || {};
    return {
      x: { grid: { display: false }, border: { color: '#cddfe8' }, ticks: { color: MUTED, maxRotation: 0, autoSkip: true } },
      y: { beginAtZero: true, grid: { color: GRID }, border: { display: false }, ticks: { precision: 0, color: MUTED, maxTicksLimit: 5 } }
    };
  }
  function unit(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

  var el = document.getElementById('chWeeks');
  if (el) new Chart(el, {
    type: 'line',
    data: { labels: D.weeks.labels, datasets: [{ data: D.weeks.values, borderColor: TEAL, backgroundColor: 'rgba(42,117,154,.08)', fill: true, borderWidth: 2, tension: .3, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: TEAL, pointBorderColor: '#fff', pointBorderWidth: 2 }] },
    options: {
      maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false }, tooltip: Object.assign({}, tooltip, { callbacks: { title: function (i) { return 'Week of ' + i[0].label; }, label: function (c) { return unit(c.parsed.y, 'appointment'); } } }) },
      scales: axes()
    }
  });

  el = document.getElementById('chServices');
  if (el) new Chart(el, {
    type: 'bar',
    data: { labels: D.services.labels, datasets: [{ data: D.services.values, backgroundColor: TEAL, borderRadius: 4, borderSkipped: 'start', barThickness: 14 }] },
    options: {
      indexAxis: 'y', maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: Object.assign({}, tooltip, { callbacks: { label: function (c) { return unit(c.parsed.x, 'booking'); } } }) },
      scales: {
        x: { beginAtZero: true, grid: { color: GRID }, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } },
        y: { grid: { display: false }, border: { color: '#cddfe8' }, ticks: { color: INK, autoSkip: false } }
      }
    }
  });

  el = document.getElementById('chDaily');
  if (el) new Chart(el, {
    type: 'bar',
    data: { labels: D.daily.labels, datasets: [{ data: D.daily.values, backgroundColor: TEAL, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 26 }] },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: Object.assign({}, tooltip, { callbacks: { label: function (c) { return unit(c.parsed.y, 'appointment'); } } }) },
      scales: axes()
    }
  });

  el = document.getElementById('chMonthly');
  if (el) new Chart(el, {
    type: 'bar',
    data: { labels: D.monthly.labels, datasets: [{ data: D.monthly.values, backgroundColor: TEAL, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22 }] },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: Object.assign({}, tooltip, { callbacks: { label: function (c) { return unit(c.parsed.y, 'patient'); } } }) },
      scales: axes()
    }
  });
})();
