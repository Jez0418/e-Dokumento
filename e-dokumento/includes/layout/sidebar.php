<?php
/** @var array|null $user */ /** @var string $active */
$groups = nav_for_role($user['role'] ?? null);
?>
<nav class="sidebar offcanvas-lg offcanvas-start" id="sidebar" tabindex="-1" aria-label="Main navigation">
  <div class="sidebar-brand">
    <img src="/assets/img/logo.svg" alt="" width="34" height="34">
    <div>
      <span class="brand-name">e-Dokumento</span>
      <span class="brand-sub">Barangay <?= e(barangay_name()) ?></span>
    </div>
    <button type="button" class="btn-close d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
  </div>
  <div class="sidebar-scroll">
    <?php foreach ($groups as [$label, $items]): ?>
      <div class="nav-group">
        <p class="nav-group-label"><?= e($label) ?></p>
        <ul>
          <?php foreach ($items as [$key, $href, $icon, $text]): ?>
            <li>
              <a href="<?= e($href) ?>" class="nav-link-item<?= $key === $active ? ' active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span><?= e($text) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="sidebar-foot">
    <span><?= e(setting('office_hours', 'Office hours not set')) ?></span>
  </div>
</nav>
