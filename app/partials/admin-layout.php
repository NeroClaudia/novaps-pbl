<?php
declare(strict_types=1);

function render_admin_header(string $staffName): void
{
    $name = htmlspecialchars(
        $staffName,
        ENT_QUOTES,
        'UTF-8'
    );
    ?>
    <header class="admin-topbar">
        <a href="/admin/dashboard.php"
           class="admin-brand"
           aria-label="NovaPS Dashboard">
            <span class="admin-brand-icon">
                <svg width="20" height="20"
                     viewBox="0 0 24 24"
                     fill="none" stroke="#0f172a"
                     stroke-width="2"
                     stroke-linecap="round"
                     stroke-linejoin="round">
                    <rect x="2" y="6" width="20"
                          height="12" rx="3"/>
                    <path d="M6 12h4M8 10v4"/>
                    <circle cx="15.5" cy="11" r=".6"/>
                    <circle cx="17.5" cy="13" r=".6"/>
                </svg>
            </span>
            <span>Nova<span class="admin-brand-accent">PS</span></span>
        </a>

        <div class="admin-account">
            <button type="button"
                    id="adminBtn"
                    class="admin-account-button"
                    aria-haspopup="true"
                    aria-expanded="false">
                <span><?= $name ?></span>
                <svg width="20" height="20"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.6"
                     aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>
                </svg>
            </button>

            <div id="account-menu"
                 class="admin-account-menu">
                <a href="/index.php">View website</a>
                <button type="button"
                        id="adminLogout">
                    Logout
                </button>
            </div>
        </div>
    </header>
    <?php
}

function render_admin_sidebar(string $active): void
{
    $menus = [
        'dashboard' => ['Dashboard', '/admin/dashboard.php'],
        'orders' => ['Orders', '/admin/orders.php'],
        'time' => ['Time Remaining', '/admin/time-remaining.php'],
        'units' => ['Unit', '/admin/units.php'],
        'users' => ['Users', null],
    ];
    ?>
    <aside class="admin-sidebar">
        <nav class="admin-nav" aria-label="Admin menu">
            <?php foreach ($menus as $key => [$label, $url]): ?>
                <?php if ($url === null): ?>
                    <span class="admin-nav-link is-disabled"
                          aria-disabled="true">
                        <?= htmlspecialchars($label) ?>
                    </span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($url) ?>"
                       class="admin-nav-link"
                       <?= $active === $key
                           ? 'aria-current="page"'
                           : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </aside>
    <?php
}
