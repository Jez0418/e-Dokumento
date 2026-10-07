/* Dashboard charts and live refresh. All numbers come from /api/dashboard (Supabase). */
(() => {
  'use strict';
  const dataEl = document.getElementById('dashboard-data');
  if (!dataEl) return;
  const { role, summary } = JSON.parse(dataEl.textContent || '{}');
  const css = getComputedStyle(document.documentElement);
  const color = (name) => css.getPropertyValue(name).trim();
  const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
  const charts = {};

  // Emerald line with a soft gradient under it; the gradient follows the chart area
  function areaFill(hex, strength) {
    return (ctx) => {
      const { chart } = ctx;
      const a = chart.chartArea;
      if (!a) return 'transparent';
      const g = chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
      g.addColorStop(0, hex + strength);
      g.addColorStop(1, hex + '00');
      return g;
    };
  }
  const withAlpha = (hex, alpha) => hex + alpha; // hex is #RRGGBB

  function buildCharts(s) {
    if (!window.Chart || role === 'resident') return;
    const accent = color('--accent');
    const grid = color('--border-soft');
    const muted = color('--text-muted');
    Chart.defaults.font.family = color('--font-sans');
    Chart.defaults.color = muted;
    Chart.defaults.font.size = 12;
    const tooltip = { backgroundColor: color('--surface-raised'), titleColor: color('--text'), bodyColor: color('--text'), borderColor: color('--border-hi'), borderWidth: 1, padding: 10, cornerRadius: 10, boxPadding: 4, displayColors: true, usePointStyle: true };
    const line = (label, data, hex, fill) => ({
      type: 'line', label, data, borderColor: hex, borderWidth: 2, tension: 0.4, cubicInterpolationMode: 'monotone',
      fill: !!fill, backgroundColor: fill ? areaFill(hex, '40') : hex, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: hex, pointHoverBorderColor: color('--surface'), pointHoverBorderWidth: 2,
    });
    const scales = (money) => ({
      y: { beginAtZero: true, border: { display: false }, ticks: money ? { callback: (v) => peso.format(v), maxTicksLimit: 5 } : { precision: 0, maxTicksLimit: 5 }, grid: { color: grid, drawTicks: false } },
      x: { border: { display: false }, grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
    });
    const main = document.getElementById('mainChart');
    if (main) {
      if (role === 'treasurer') {
        const rows = s.daily_collections || [];
        charts.main = new Chart(main, {
          type: 'line',
          data: { labels: rows.map((r) => r.label), datasets: [line('Collected', rows.map((r) => Number(r.total)), accent, true)] },
          options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { display: false }, tooltip: { ...tooltip, callbacks: { label: (c) => ' ' + peso.format(c.parsed.y) } } }, scales: scales(true) },
        });
      } else {
        const rows = s.by_month || [];
        charts.main = new Chart(main, {
          type: 'line',
          data: {
            labels: rows.map((r) => r.label),
            datasets: [
              line('Filed', rows.map((r) => r.submitted), accent, true),
              line('Released', rows.map((r) => r.released), muted, false),
              { ...line('Rejected', rows.map((r) => r.rejected), color('--status-rejected'), false), borderDash: [4, 4], borderWidth: 1.5 },
            ],
          },
          options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 16, color: muted, generateLabels: (chart) => chart.data.datasets.map((d, i) => ({ text: d.label, fillStyle: d.borderColor, strokeStyle: d.borderColor, fontColor: muted, pointStyle: 'circle', hidden: !chart.isDatasetVisible(i), datasetIndex: i })) } }, tooltip }, scales: scales(false) },
        });
      }
    }
    const typeEl = document.getElementById('typeChart');
    if (typeEl && (s.by_type || []).length) {
      // Emerald ramp, then neutral greys: the legend carries the names
      const palette = [accent, withAlpha(accent, 'B3'), withAlpha(accent, '73'), muted, color('--text-subtle'), color('--border-hi')];
      charts.type = new Chart(typeEl, {
        type: 'doughnut',
        data: { labels: s.by_type.map((t) => t.name), datasets: [{ data: s.by_type.map((t) => t.total), backgroundColor: s.by_type.map((_, i) => palette[i % palette.length]), borderWidth: 2, borderColor: color('--surface') }] },
        options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 14, color: muted } }, tooltip } },
      });
    }
  }

  function updateCards(s) {
    const cards = s.cards || {};
    document.querySelectorAll('[data-card]').forEach((el) => {
      const v = cards[el.dataset.card];
      if (v === undefined) return;
      el.textContent = el.dataset.money ? peso.format(Number(v)) : String(v);
    });
  }

  function updateCharts(s) {
    if (charts.main) {
      if (role === 'treasurer') {
        charts.main.data.datasets[0].data = (s.daily_collections || []).map((r) => Number(r.total));
      } else {
        const rows = s.by_month || [];
        charts.main.data.labels = rows.map((r) => r.label);
        charts.main.data.datasets[0].data = rows.map((r) => r.submitted);
        charts.main.data.datasets[1].data = rows.map((r) => r.released);
        charts.main.data.datasets[2].data = rows.map((r) => r.rejected);
      }
      charts.main.update();
    }
    if (charts.type && s.by_type) {
      charts.type.data.labels = s.by_type.map((t) => t.name);
      charts.type.data.datasets[0].data = s.by_type.map((t) => t.total);
      charts.type.update();
    }
  }

  let latest = summary || {};
  buildCharts(latest);
  // Theme change: redraw with the new colours instead of reloading the page
  document.addEventListener('edk:themechange', () => {
    Object.keys(charts).forEach((k) => { charts[k].destroy(); delete charts[k]; });
    buildCharts(latest);
  });

  // Refresh every 60 s while the tab is visible, so the board reflects new requests and payments
  async function refresh() {
    if (document.hidden) return;
    try {
      const res = await fetch('/api/dashboard', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (res.status === 401 || res.redirected) return;
      const body = await res.json();
      if (!body.ok) return;
      latest = body.summary;
      updateCards(body.summary);
      updateCharts(body.summary);
      const stamp = document.getElementById('dash-updated');
      if (stamp) stamp.textContent = 'Updated ' + new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    } catch (_) { /* keep the last numbers on network errors */ }
  }
  setInterval(refresh, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
