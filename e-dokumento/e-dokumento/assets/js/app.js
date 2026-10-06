/* e-Dokumento shared behaviour: toasts, confirmations, validation, uploads. */
(() => {
  'use strict';

  // ---- Toasts (flash messages from PHP arrive as JSON) -------------------
  const toastBox = document.getElementById('toasts');
  function toast(type, message) {
    if (!toastBox || !window.bootstrap) return;
    const el = document.createElement('div');
    el.className = 'toast align-items-center bg-white toast-' + (type === 'error' ? 'error' : 'success');
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const icon = type === 'error' ? 'bi-x-octagon text-danger' : 'bi-check-circle text-success';
    el.innerHTML = '<div class="d-flex"><div class="toast-body d-flex gap-2"><i class="bi ' + icon + '" aria-hidden="true"></i><span></span></div>' +
      '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    el.querySelector('span').textContent = message;
    toastBox.appendChild(el);
    new bootstrap.Toast(el, { delay: type === 'error' ? 9000 : 5000 }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }
  window.edkToast = toast;
  try {
    const flash = JSON.parse(document.getElementById('flash-data')?.textContent || '[]');
    flash.forEach((f) => toast(f.type, f.message));
  } catch (_) { /* ignore malformed flash */ }

  // ---- Password helpers ----------------------------------------------------
  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.togglePassword);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.querySelector('i')?.classList.toggle('bi-eye-slash', show);
    });
  });
  document.querySelectorAll('[data-match]').forEach((input) => {
    const other = document.getElementById(input.dataset.match);
    const check = () => input.setCustomValidity(other && input.value !== other.value ? 'The passwords do not match.' : '');
    input.addEventListener('input', check);
    other?.addEventListener('input', check);
  });

  // ---- Payment method shows/hides the reference number ----------------------
  document.querySelectorAll('[data-toggle-ref]').forEach((select) => {
    const sync = () => {
      const wrap = document.getElementById(select.dataset.toggleRef);
      if (!wrap) return;
      const needs = select.value !== 'cash';
      wrap.hidden = !needs;
      wrap.querySelectorAll('input').forEach((i) => { i.required = needs; if (!needs) i.value = ''; });
    };
    select.addEventListener('change', sync);
    sync();
  });

  // ---- Upload size checks (Vercel rejects request bodies over ~4.5 MB) -------
  function fmtMB(bytes) { return (bytes / 1048576).toFixed(1) + ' MB'; }
  document.addEventListener('change', (ev) => {
    const input = ev.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.dataset.maxBytes) return;
    const max = Number(input.dataset.maxBytes);
    const file = input.files && input.files[0];
    if (file && file.size > max) {
      input.setCustomValidity('This file is ' + fmtMB(file.size) + '. The limit is ' + fmtMB(max) + '.');
      toast('error', file.name + ' is ' + fmtMB(file.size) + '. Files must be 2 MB or smaller.');
    } else {
      input.setCustomValidity('');
    }
  });

  // ---- Confirmation modal ------------------------------------------------------
  const modalEl = document.getElementById('confirmModal');
  const modal = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;
  let pendingForm = null;
  function askConfirm(form) {
    if (!modal) return window.confirm(form.dataset.confirm);
    pendingForm = form;
    const needsReason = form.dataset.confirmReason === '1';
    document.getElementById('confirmMessage').textContent = form.dataset.confirm || 'Are you sure?';
    const wrap = document.getElementById('confirmReasonWrap');
    const reason = document.getElementById('confirmReason');
    wrap.classList.toggle('d-none', !needsReason);
    reason.value = '';
    reason.classList.remove('is-invalid');
    modal.show();
    if (needsReason) setTimeout(() => reason.focus(), 300);
    return false;
  }
  document.getElementById('confirmGo')?.addEventListener('click', () => {
    if (!pendingForm) return;
    if (pendingForm.dataset.confirmReason === '1') {
      const reason = document.getElementById('confirmReason');
      const text = reason.value.trim();
      if (text.length < 10 || text.length > 500) { reason.classList.add('is-invalid'); reason.focus(); return; }
      const field = pendingForm.querySelector('input[name="reason"]');
      if (field) field.value = text;
    }
    pendingForm.dataset.confirmed = '1';
    modal.hide();
    const form = pendingForm;
    pendingForm = null;
    form.requestSubmit ? form.requestSubmit() : form.submit();
  });

  // ---- One submit pipeline: validate -> total size -> confirm -> busy ---------
  document.addEventListener('submit', (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.classList.contains('needs-validation') && !form.checkValidity()) {
      ev.preventDefault();
      ev.stopPropagation();
      form.classList.add('was-validated');
      form.querySelector(':invalid')?.focus();
      return;
    }
    if (form.dataset.maxTotal) {
      let total = 0;
      form.querySelectorAll('input[type=file]:not(:disabled)').forEach((i) => { for (const f of i.files || []) total += f.size; });
      if (total > Number(form.dataset.maxTotal)) {
        ev.preventDefault();
        toast('error', 'These files add up to ' + fmtMB(total) + '. Keep the total under 4 MB, for example by uploading smaller photos.');
        return;
      }
    }
    if (form.dataset.confirm && form.dataset.confirmed !== '1') {
      ev.preventDefault();
      askConfirm(form);
      return;
    }
    delete form.dataset.confirmed;
    const btn = ev.submitter || form.querySelector('[type=submit]');
    if (btn && btn.dataset.loadingText) {
      btn.setAttribute('aria-busy', 'true');
      btn.dataset.originalText = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + btn.dataset.loadingText;
    }
  });
  // Restore buttons when the page comes back from the browser cache
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('[aria-busy="true"]').forEach((b) => { b.removeAttribute('aria-busy'); if (b.dataset.originalText) b.innerHTML = b.dataset.originalText; });
  });

  // ---- Clickable table rows ------------------------------------------------------
  document.addEventListener('click', (ev) => {
    const row = ev.target.closest('tr[data-href]');
    if (!row || ev.target.closest('a, button, input, select, textarea, form, label, summary')) return;
    window.location.href = row.dataset.href;
  });

  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
})();
