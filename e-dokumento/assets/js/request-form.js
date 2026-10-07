/* New request form: show the chosen document's requirements and keep the summary current. */
(() => {
  'use strict';
  const form = document.querySelector('.request-form');
  if (!form) return;
  const radios = form.querySelectorAll('input[name="document_type_id"]');
  const copies = form.querySelector('#copies');
  const waived = form.querySelector('#fee_waived');
  const reason = form.querySelector('#waiver_reason');
  const business = form.querySelector('.business-fields');
  const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

  function selected() { return form.querySelector('input[name="document_type_id"]:checked'); }

  function sync() {
    const r = selected();
    form.querySelector('.choose-first')?.toggleAttribute('hidden', !!r);
    form.querySelectorAll('.req-group').forEach((g) => {
      const on = !!r && g.dataset.type === r.value;
      g.hidden = !on;
      g.querySelectorAll('input[type=file]').forEach((i) => {
        i.disabled = !on;                       // only the chosen document's files are sent
        i.required = on && i.dataset.required === '1';
      });
    });
    const isBusiness = !!r && r.dataset.template === 'business';
    if (business) {
      business.hidden = !isBusiness;
      ['#business_name', '#business_address'].forEach((s) => { const el = form.querySelector(s); if (el) el.required = isBusiness; });
    }
    if (r && copies) {
      copies.max = r.dataset.max;
      if (Number(copies.value) > Number(r.dataset.max)) copies.value = r.dataset.max;
    }
    if (reason && waived) reason.required = waived.checked;
    const label = r ? r.closest('.doc-choice').querySelector('.doc-choice-name').textContent : '—';
    const n = Math.max(1, Number(copies?.value || 1));
    const fee = r ? (waived && waived.checked ? 0 : Number(r.dataset.fee) * n) : null;
    document.getElementById('sum-doc').textContent = label;
    document.getElementById('sum-copies').textContent = String(n);
    document.getElementById('sum-fee').textContent = fee === null ? '—' : (fee === 0 ? 'Free' : peso.format(fee));
  }

  radios.forEach((r) => r.addEventListener('change', sync));
  copies?.addEventListener('input', sync);
  waived?.addEventListener('change', sync);
  sync();
})();
