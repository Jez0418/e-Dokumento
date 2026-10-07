/* Sign-in helpers: Supabase Auth returns results in the URL fragment, which PHP never sees. */
(() => {
  'use strict';
  const hash = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  const clearHash = () => history.replaceState(null, '', window.location.pathname + window.location.search);

  // Login page: email confirmation links land here
  const msg = document.getElementById('hash-message');
  if (msg && window.location.hash) {
    const error = hash.get('error_description');
    if (error) {
      msg.className = 'alert alert-danger';
      msg.textContent = error.replace(/\+/g, ' ') + '. Request a new link and try again.';
    } else if (['signup', 'email', 'magiclink'].includes(hash.get('type') || '')) {
      msg.className = 'alert alert-success';
      msg.textContent = 'Email confirmed. Sign in to continue.';
    }
    clearHash();
  }

  // Reset page: the recovery link carries the access token in the fragment
  const resetForm = document.getElementById('reset-form');
  if (resetForm) {
    const tokenField = document.getElementById('access_token');
    const token = hash.get('access_token');
    if (token && (hash.get('type') === 'recovery' || !hash.get('type'))) {
      tokenField.value = token;
      clearHash();
    }
    if (!tokenField.value) {
      document.getElementById('reset-missing')?.classList.remove('d-none');
      resetForm.querySelectorAll('input, button').forEach((el) => { if (el.type !== 'hidden') el.disabled = true; });
    }
  }

  // Live password hint
  document.querySelectorAll('[data-strength]').forEach((input) => {
    const hint = document.getElementById(input.dataset.strength);
    input.addEventListener('input', () => {
      const v = input.value;
      const ok = v.length >= 8 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v);
      if (hint) hint.classList.toggle('text-success', ok);
      input.setCustomValidity(v === '' || ok ? '' : 'Use at least 8 characters with an uppercase letter, a lowercase letter and a number.');
    });
  });
})();
