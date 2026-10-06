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

  function buildCharts(s) {
    if (!window.Chart || role === 'resident') return;
    Chart.defaults.font.family = color('--font-sans');
    Chart.defaults.color = color('--ink-2');
    const main = document.getElementById('mainChart');
    if (main) {
      if (role === 'treasurer') {
        const rows = s.daily_collections || [];
        charts.main = new Chart(main, {
          type: 'bar',
          data: { labels: rows.map((r) => r.label), datasets: [{ label: 'Collected', data: rows.map((r) => Number(r.total)), backgroundColor: color('--carbon'), borderRadius: 4 }] },
          options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => peso.format(c.parsed.y) } } },
                     scales: { y: { beginAtZero: true, ticks: { callback: (v) => peso.format(v) }, grid: { color: color('--rule-soft') } }, x: { grid: { display: false } } } },
        });
      } else {
        const rows = s.by_month || [];
        charts.main = new Chart(main, {
          type: 'bar',
          data: {
            labels: rows.map((r) => r.label),
            datasets: [
              { label: 'Filed', data: rows.map((r) => r.submitted), backgroundColor: color('--carbon'), borderRadius: 4 },
              { label: 'Released', data: rows.map((r) => r.released), backgroundColor: color('--approve'), borderRadius: 4 },
              { label: 'Rejected', data: rows.map((r) => r.rejected), backgroundColor: color('--stamp'), borderRadius: 4 },
            ],
          },
          options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                     scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: color('--rule-soft') } }, x: { grid: { display: false } } } },
        });
      }
    }
    const typeEl = document.getElementById('typeChart');
    if (typeEl && (s.by_type || []).length) {
      const palette = [color('--carbon'), color('--manila-deep'), color('--approve'), '#7A3E9D', color('--amber'), color('--ink-3')];
      charts.type = new Chart(typeEl, {
        type: 'doughnut',
        data: { labels: s.by_type.map((t) => t.name), datasets: [{ data: s.by_type.map((t) => t.total), backgroundColor: s.by_type.map((_, i) => palette[i % palette.length]), borderWidth: 2, borderColor: '#fff' }] },
        options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } } },
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

  buildCharts(summary || {});

  // Refresh every 60 s while the tab is visible, so the board reflects new requests and payments
  async function refresh() {
    if (document.hidden) return;
    try {
      const res = await fetch('/api/dashboard', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (res.status === 401 || res.redirected) return;
      const body = await res.json();
      if (!body.ok) return;
      updateCards(body.summary);
      updateCharts(body.summary);
      const stamp = document.getElementById('dash-updated');
      if (stamp) stamp.textContent = 'Updated ' + new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    } catch (_) { /* keep the last numbers on network errors */ }
  }
  setInterval(refresh, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
