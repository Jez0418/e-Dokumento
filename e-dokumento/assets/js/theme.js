/* Theme: dark by default. The choice made with the toggle is remembered in this browser.
   Loaded in <head> so the right theme is set before first paint. */
(() => {
  'use strict';
  const root = document.documentElement;
  const read = () => { try { return localStorage.getItem('edk_theme'); } catch (e) { return null; } };
  const write = (v) => { try { localStorage.setItem('edk_theme', v); } catch (e) { /* storage blocked: choice lasts for this page only */ } };
  const current = () => (read() === 'light' ? 'light' : 'dark');
  const apply = (t) => {
    root.setAttribute('data-bs-theme', t);
    document.querySelectorAll('[data-theme-toggle]').forEach((b) => {
      const label = t === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
      b.setAttribute('aria-label', label);
      b.setAttribute('title', label);
      b.setAttribute('aria-pressed', t === 'light' ? 'true' : 'false');
    });
  };

  apply(current());
  document.addEventListener('DOMContentLoaded', () => apply(current()));
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
