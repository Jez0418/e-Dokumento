/* Theme: dark by default. The account menu offers Dark, Light and System; the sun/moon button
   flips between dark and light. The choice is remembered in this browser.
   Stored under edk_theme_v2: the old edk_theme key held a choice made under the previous
   light-first design, and honouring it would keep returning users on light. Loaded in <head>
   so the right theme is set before first paint. */
(() => {
  'use strict';
  const root = document.documentElement;
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const read = () => { try { return localStorage.getItem('edk_theme_v2'); } catch (e) { return null; } };
  const write = (v) => { try { localStorage.setItem('edk_theme_v2', v); } catch (e) { /* storage blocked: choice lasts for this page only */ } };
  const choice = () => { const v = read(); return v === 'light' || v === 'system' ? v : 'dark'; };
  const effective = () => { const c = choice(); return c === 'system' ? (media.matches ? 'dark' : 'light') : c; };
  const apply = (notify) => {
    const t = effective();
    const changed = root.getAttribute('data-bs-theme') !== t;
    root.setAttribute('data-bs-theme', t);
    document.querySelectorAll('[data-theme-toggle]').forEach((b) => {
      const label = t === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
      b.setAttribute('aria-label', label);
      b.setAttribute('title', label);
    });
    document.querySelectorAll('[data-theme-set]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.themeSet === choice() ? 'true' : 'false'));
    if (notify && changed) document.dispatchEvent(new CustomEvent('edk:themechange', { detail: { theme: t } }));
  };

  apply(false);
  document.addEventListener('DOMContentLoaded', () => apply(false));
  media.addEventListener('change', () => { if (choice() === 'system') apply(true); });
  document.addEventListener('click', (ev) => {
    const set = ev.target.closest('[data-theme-set]');
    if (set) { write(set.dataset.themeSet); apply(true); return; }
    if (!ev.target.closest('[data-theme-toggle]')) return;
    write(effective() === 'dark' ? 'light' : 'dark');
    apply(true);
  });

  // Paper is always light: print with the light theme, then restore.
  let before = null;
  window.addEventListener('beforeprint', () => { before = root.getAttribute('data-bs-theme'); root.setAttribute('data-bs-theme', 'light'); });
  window.addEventListener('afterprint', () => { if (before) root.setAttribute('data-bs-theme', before); before = null; });
})();
