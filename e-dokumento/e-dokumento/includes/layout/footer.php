<?php /** @var array $pageScripts */ $flash = Flash::pull(); ?>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" aria-live="polite" aria-atomic="true"></div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="confirmTitle">Are you sure?</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p id="confirmMessage" class="mb-0"></p>
        <div id="confirmReasonWrap" class="mt-3 d-none">
          <label for="confirmReason" class="form-label">Reason <span class="text-secondary">(10 to 500 characters)</span></label>
          <textarea id="confirmReason" class="form-control" rows="3" maxlength="500"></textarea>
          <div class="invalid-feedback">Give a reason of at least 10 characters.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep it</button>
        <button type="button" class="btn btn-danger" id="confirmGo">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script type="application/json" id="flash-data"><?= json_encode($flash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
  <?php if (str_starts_with($script, 'https://')): ?>
<script src="<?= e($script) ?>"></script>
  <?php else: ?>
<script src="/assets/js/<?= e($script) ?>"></script>
  <?php endif; ?>
<?php endforeach; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
