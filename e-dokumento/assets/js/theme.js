/* Theme: follows the system setting until the user picks one in the account menu.
   Loaded in <head> so the right theme is set before first paint. */
(() => {
  'use strict';
  const root = document.documentElement;
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const read = () => { try { return localStorage.getItem('edk_theme'); } catch (e) { return null; } };
  const write = (v) => { try { localStorage.setItem('edk_theme', v); } catch (e) { /* storage blocked: choice lasts for this page only */ } };
  const current = () => { const t = read(); return t === 'dark' || t === 'light' ? t : (media.matches ? 'dark' : 'light'); };
  const apply = (t) => {
    root.setAttribute('data-bs-theme', t);
    document.querySelectorAll('[data-theme-toggle]').forEach((b) => {
      const dark = t === 'dark';
      const label = b.querySelector('[data-theme-label]');
      const icon = b.querySelector('i');
      if (label) label.textContent = dark ? 'Light mode' : 'Dark mode';
      if (icon) icon.className = 'bi me-2 ' + (dark ? 'bi-sun' : 'bi-moon');
    });
  };

  apply(current());
  document.addEventListener('DOMContentLoaded', () => apply(current()));
  media.addEventListener('change', () => { if (!read()) apply(current()); });
  document.addEventListener('click', (ev) => {
    if (!ev.target.closest('[data-theme-toggle]')) return;
    const next = current() === 'dark' ? 'light' : 'dark';
    write(next);
    apply(next);
    if (window.Chart) location.reload(); // charts read their colours once; reload to repaint them
  });

  // Paper is always light: print with the light theme, then restore.
  let before = null;
  window.addEventListener('beforeprint', () => { before = root.getAttribute('data-bs-theme'); root.setAttribute('data-bs-theme', 'light'); });
  window.addEventListener('afterprint', () => { if (before) root.setAttribute('data-bs-theme', before); before = null; });
})();
