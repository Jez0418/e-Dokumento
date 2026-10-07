<?php /** @var array|null $user */ /** @var string $pageTitle */ /** @var int $unread */ ?>
<header class="topbar">
  <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <span class="topbar-title d-md-none"><?= e($pageTitle) ?></span>
  <form class="topbar-search d-none d-md-flex" action="/requests" method="get" role="search">
    <i class="bi bi-search" aria-hidden="true"></i>
    <label class="visually-hidden" for="topbar-q">Find a request by control number or name</label>
    <input id="topbar-q" name="q" type="search" placeholder="Control number or name" autocomplete="off" maxlength="80">
  </form>
  <div class="topbar-right">
    <a href="/notifications" class="btn btn-icon position-relative" aria-label="Notifications<?= $unread ? ', ' . (int) $unread . ' unread' : '' ?>">
      <i class="bi bi-bell" aria-hidden="true"></i>
      <?php if ($unread > 0): ?><span class="dot-count"><?= $unread > 9 ? '9+' : (int) $unread ?></span><?php endif; ?>
    </a>
    <div class="dropdown">
      <button class="user-chip dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="visually-hidden">Account menu: </span>
        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? '?'), 0, 1))) ?></span>
        <span class="user-meta d-none d-sm-flex"><strong><?= e($user['full_name'] ?? '') ?></strong><small><?= e($user['role_name'] ?? '') ?></small></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li class="dropdown-header d-sm-none"><strong class="d-block text-body"><?= e($user['full_name'] ?? '') ?></strong><?= e($user['role_name'] ?? '') ?></li>
        <li class="d-sm-none"><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-2" aria-hidden="true"></i>Profile</a></li>
        <li><hr class="dropdown-divider"></li>
        <li>
          <form action="/logout" method="post" class="m-0">
            <?= csrf_field() ?>
            <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button>
          </form>
        </li>
      </ul>
    </div>
  </div>
</header>
