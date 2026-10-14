/* e-Dokumento select menus.
 * Replaces the look of every <select class="form-select"> with a menu styled like the account
 * dropdown. The native <select> stays in the DOM (invisible, same size), so forms, validation,
 * labels and other scripts keep working. Opt out with data-native on the select. */
(() => {
  'use strict';

  const GAP = 6;
  const MENU_MAX = 288;
  let openState = null; // { select, trigger, menu }
  let uid = 0;

  function optionRows(select) {
    const rows = [];
    Array.from(select.children).forEach((child) => {
      if (child.tagName === 'OPTGROUP') {
        rows.push({ group: child.label });
        Array.from(child.children).forEach((o) => rows.push({ option: o, disabled: o.disabled || child.disabled }));
      } else if (child.tagName === 'OPTION') {
        rows.push({ option: child, disabled: child.disabled });
      }
    });
    return rows;
  }

  function enhance(select) {
    if (select.multiple || select.size > 1 || select.hasAttribute('data-native') || select.dataset.selectReady) return;
    if (select.closest('.input-group')) return;
    select.dataset.selectReady = '1';

    const wrap = document.createElement('div');
    wrap.className = 'select-wrap' + (select.classList.contains('w-100') ? ' w-100' : '');
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = select.className.replace('select-native', '').trim() + ' select-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    const value = document.createElement('span');
    value.className = 'select-value';
    trigger.appendChild(value);
    wrap.appendChild(trigger);

    select.classList.add('select-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    // The visible label points at the select; send its focus to the button instead
    const label = select.id ? document.querySelector('label[for="' + CSS.escape(select.id) + '"]') : null;
    if (label) {
      if (!label.id) label.id = 'sl-' + (++uid);
      trigger.setAttribute('aria-labelledby', label.id);
    }
    if (select.getAttribute('aria-label')) trigger.setAttribute('aria-label', select.getAttribute('aria-label'));
    select.addEventListener('focus', () => trigger.focus());

    let menu = null;
    let active = -1;
    let typed = '';
    let typedTimer = 0;

    function sync() {
      const sel = select.options[select.selectedIndex];
      value.textContent = sel ? sel.textContent.trim() : '';
      value.classList.toggle('is-placeholder', !!sel && sel.value === '' && select.required);
      trigger.disabled = select.disabled;
      trigger.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
      trigger.classList.toggle('is-valid', select.classList.contains('is-valid'));
    }

    function buildMenu() {
      menu = document.createElement('div');
      menu.className = 'select-menu';
      menu.id = 'sm-' + (++uid);
      menu.setAttribute('role', 'listbox');
      menu.tabIndex = -1;
      trigger.setAttribute('aria-controls', menu.id);
      menu.addEventListener('mousedown', (e) => e.preventDefault()); // keep focus on the button
      document.body.appendChild(menu);
    }

    function fillMenu() {
      menu.textContent = '';
      let n = 0;
      optionRows(select).forEach((row) => {
        if (row.group !== undefined) {
          const h = document.createElement('div');
          h.className = 'select-group';
          h.textContent = row.group;
          menu.appendChild(h);
          return;
        }
        const o = row.option;
        const el = document.createElement('div');
        el.className = 'select-option';
        el.id = menu.id + '-o' + n;
        el.setAttribute('role', 'option');
        el.setAttribute('aria-selected', o.selected ? 'true' : 'false');
        if (row.disabled) { el.setAttribute('aria-disabled', 'true'); el.classList.add('is-disabled'); }
        el.style.setProperty('--i', String(Math.min(n, 8)));
        const text = document.createElement('span');
        text.textContent = o.textContent.trim();
        el.appendChild(text);
        el.dataset.index = String(o.index);
        if (!row.disabled) {
          el.addEventListener('click', () => choose(o.index));
          el.addEventListener('mousemove', () => setActive(el, false));
        }
        menu.appendChild(el);
        n++;
      });
    }

    const items = () => Array.from(menu.querySelectorAll('.select-option:not(.is-disabled)'));

    function setActive(el, scroll = true) {
      menu.querySelectorAll('.select-option.is-active').forEach((x) => x.classList.remove('is-active'));
      if (!el) { trigger.removeAttribute('aria-activedescendant'); return; }
      el.classList.add('is-active');
      trigger.setAttribute('aria-activedescendant', el.id);
      if (scroll) el.scrollIntoView({ block: 'nearest' });
    }

    function place() {
      const r = trigger.getBoundingClientRect();
      const vw = document.documentElement.clientWidth;
      const vh = window.innerHeight;
      const width = Math.max(r.width, 176);
      menu.style.minWidth = width + 'px';
      menu.style.maxHeight = MENU_MAX + 'px';
      const h = Math.min(menu.scrollHeight, MENU_MAX);
      const below = vh - r.bottom - GAP - 8;
      const above = r.top - GAP - 8;
      const up = h > below && above > below;
      menu.classList.toggle('up', up);
      if (up) {
        menu.style.maxHeight = Math.min(MENU_MAX, above) + 'px';
        menu.style.top = 'auto';
        menu.style.bottom = (vh - r.top + GAP) + 'px';
      } else {
        menu.style.maxHeight = Math.min(MENU_MAX, Math.max(below, 120)) + 'px';
        menu.style.bottom = 'auto';
        menu.style.top = (r.bottom + GAP) + 'px';
      }
      const left = Math.min(Math.max(8, r.left), Math.max(8, vw - width - 8));
      menu.style.left = left + 'px';
    }

    function open() {
      if (select.disabled || openState?.select === select) return;
      closeOpen(false);
      if (!menu) buildMenu();
      fillMenu();
      place();
      const cur = menu.querySelector('.select-option[aria-selected="true"]') || items()[0];
      setActive(cur);
      requestAnimationFrame(() => menu.classList.add('open'));
      trigger.setAttribute('aria-expanded', 'true');
      openState = { select, trigger, menu, close };
    }

    function close(refocus = true) {
      if (!menu) return;
      menu.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
      trigger.removeAttribute('aria-activedescendant');
      if (openState?.menu === menu) openState = null;
      if (refocus) trigger.focus({ preventScroll: true });
    }

    function choose(index) {
      const changed = select.selectedIndex !== index;
      select.selectedIndex = index;
      sync();
      close(true);
      if (changed) {
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    function move(delta) {
      const list = items();
      if (!list.length) return;
      const i = list.findIndex((x) => x.classList.contains('is-active'));
      setActive(list[Math.min(list.length - 1, Math.max(0, (i < 0 ? 0 : i) + delta))]);
    }

    trigger.addEventListener('click', () => (openState?.select === select ? close(true) : open()));
    trigger.addEventListener('keydown', (e) => {
      const isOpen = openState?.select === select;
      switch (e.key) {
        case 'ArrowDown': case 'ArrowUp':
          e.preventDefault();
          if (!isOpen) open(); else move(e.key === 'ArrowDown' ? 1 : -1);
          break;
        case 'Home': case 'End':
          if (isOpen) { e.preventDefault(); const l = items(); setActive(l[e.key === 'Home' ? 0 : l.length - 1]); }
          break;
        case 'Enter': case ' ':
          if (isOpen) {
            e.preventDefault();
            const a = menu.querySelector('.select-option.is-active');
            if (a) choose(Number(a.dataset.index)); else close(true);
          } else if (e.key === ' ' || e.key === 'Enter') {
            e.preventDefault();
            open();
          }
          break;
        case 'Escape':
          if (isOpen) { e.preventDefault(); e.stopPropagation(); close(true); }
          break;
        case 'Tab':
          if (isOpen) close(false);
          break;
        default:
          if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            clearTimeout(typedTimer);
            typed += e.key.toLowerCase();
            typedTimer = setTimeout(() => { typed = ''; }, 600);
            const hit = Array.from(select.options).find((o) => !o.disabled && o.textContent.trim().toLowerCase().startsWith(typed));
            if (hit) {
              if (openState?.select === select) setActive(menu.querySelector('[data-index="' + hit.index + '"]'));
              else { select.selectedIndex = hit.index; sync(); select.dispatchEvent(new Event('change', { bubbles: true })); }
            }
          }
      }
    });

    select.addEventListener('change', sync);
    if (select.form) select.form.addEventListener('reset', () => setTimeout(sync, 0));
    new MutationObserver(sync).observe(select, { attributes: true, attributeFilter: ['class', 'disabled'], childList: true, subtree: true, characterData: true });
    sync();
  }

  function closeOpen(refocus) {
    if (openState) openState.close(refocus);
  }

  document.addEventListener('mousedown', (e) => {
    if (openState && !openState.menu.contains(e.target) && !openState.trigger.contains(e.target)) closeOpen(false);
  });
  window.addEventListener('resize', () => closeOpen(false));
  window.addEventListener('scroll', (e) => {
    if (openState && !openState.menu.contains(e.target)) closeOpen(false);
  }, true);

  function init(root) {
    (root || document).querySelectorAll('select.form-select').forEach(enhance);
  }
  window.edkSelectMenus = init;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
  else init();
})();
