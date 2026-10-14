/* Signature pad: draw with mouse, touch or stylus; posts a cropped transparent PNG as a data URL. */
(() => {
  'use strict';
  const canvas = document.getElementById('signature-pad');
  const form = canvas && canvas.closest('form');
  const field = document.getElementById('signature_data');
  const clearBtn = document.getElementById('signature-clear');
  const msg = document.getElementById('signature-msg');
  if (!canvas || !form || !field) return;

  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  const ctx = canvas.getContext('2d');
  let drawing = false;
  let last = null;
  let inked = false;

  const size = () => {
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.lineWidth = 2.6;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111';
    inked = false;
  };
  const point = (e) => {
    const r = canvas.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  };
  const say = (text) => { if (msg) msg.textContent = text; };

  canvas.addEventListener('pointerdown', (e) => {
    drawing = true;
    last = point(e);
    canvas.setPointerCapture(e.pointerId);
    ctx.beginPath();
    ctx.arc(last.x, last.y, 1.2, 0, Math.PI * 2);
    ctx.fillStyle = '#111';
    ctx.fill();
    inked = true;
    say('');
    e.preventDefault();
  });
  canvas.addEventListener('pointermove', (e) => {
    if (!drawing) return;
    const p = point(e);
    ctx.beginPath();
    ctx.moveTo(last.x, last.y);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    last = p;
    e.preventDefault();
  });
  const stop = () => { drawing = false; last = null; };
  canvas.addEventListener('pointerup', stop);
  canvas.addEventListener('pointercancel', stop);

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      ctx.save();
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.restore();
      inked = false;
      field.value = '';
      say('');
    });
  }

  /* Crop to the ink (plus a margin) so the stamp sits tight on the signature line. */
  const cropped = () => {
    const { width: w, height: h } = canvas;
    const data = ctx.getImageData(0, 0, w, h).data;
    let minX = w, minY = h, maxX = -1, maxY = -1, count = 0;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        if (data[(y * w + x) * 4 + 3] > 16) {
          count++;
          if (x < minX) minX = x;
          if (x > maxX) maxX = x;
          if (y < minY) minY = y;
          if (y > maxY) maxY = y;
        }
      }
    }
    if (count < 40) return null;
    const m = Math.round(12 * dpr);
    const sx = Math.max(0, minX - m);
    const sy = Math.max(0, minY - m);
    const sw = Math.min(w, maxX + m) - sx;
    const sh = Math.min(h, maxY + m) - sy;
    const out = document.createElement('canvas');
    out.width = sw;
    out.height = sh;
    out.getContext('2d').drawImage(canvas, sx, sy, sw, sh, 0, 0, sw, sh);
    return out.toDataURL('image/png');
  };

  form.addEventListener('submit', (e) => {
    if (e.submitter && e.submitter.value === 'remove') return;
    const url = inked ? cropped() : null;
    if (!url) {
      e.preventDefault();
      say('Draw your signature first.');
      return;
    }
    field.value = url;
  });

  window.addEventListener('load', size);
  size();
})();
