/* Cashiering: fill the payment modal from the row that opened it. */
(() => {
  'use strict';
  const modal = document.getElementById('payModal');
  if (!modal) return;
  const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
  modal.addEventListener('show.bs.modal', (ev) => {
    const b = ev.relatedTarget;
    if (!b) return;
    modal.querySelector('#pay-id').value = b.dataset.payId;
    modal.querySelector('#pay-amount').value = b.dataset.payAmount;
    modal.querySelector('#pay-control').textContent = b.dataset.payControl;
    modal.querySelector('#pay-name').textContent = b.dataset.payName;
    modal.querySelector('#pay-amount-text').textContent = peso.format(Number(b.dataset.payAmount));
    const form = modal.querySelector('form');
    form.classList.remove('was-validated');
    form.querySelector('#pay-or').value = '';
  });
  modal.addEventListener('shown.bs.modal', () => modal.querySelector('#pay-or').focus());
})();
