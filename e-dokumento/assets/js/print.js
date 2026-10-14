/* Certificate view: draw the verification QR code and open the print dialog when asked. */
(() => {
  'use strict';
  const qr = document.getElementById('qr');
  if (qr && window.QRCode) {
    try {
      new QRCode(qr, { text: qr.dataset.url, width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M });
    } catch (_) { /* the printed code and URL still allow manual verification */ }
  }
  const cert = document.querySelector('.certificate');
  if (cert && cert.dataset.autoprint === '1') {
    const url = new URL(window.location.href);
    url.searchParams.delete('autoprint');
    history.replaceState(null, '', url.toString());
    // Wait for the signature image (it loads through /files) so the official copy is never printed without it.
    const pending = Array.from(cert.querySelectorAll('img')).map((img) => img.decode().catch(() => {}));
    const timeout = new Promise((resolve) => setTimeout(resolve, 5000));
    Promise.race([Promise.all(pending), timeout]).then(() => setTimeout(() => window.print(), 500));
  }
})();
