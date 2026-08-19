<?php
// ============================================================================
// APPLICATION SHELL — bottom half: closes the content region, then renders
// the mobile drawer, bottom navigation, command palette and toast region.
// ============================================================================
if (isLoggedIn()):
?>
            </main>

            <footer class="dfms-footer">
                <span>&copy; <?php echo date('Y'); ?> <?php echo e($dfms_org_name); ?></span>
                <span>DFM · Farm Management &amp; Intelligence</span>
            </footer>
        </div><!-- /.dfms-main -->
    </div><!-- /.dfms-shell -->

    <!-- ================= Mobile drawer ================= -->
    <div class="dfms-drawer-backdrop" data-drawer-close></div>
    <nav class="dfms-drawer" aria-label="Mobile navigation" aria-hidden="true">
        <div class="dfms-brand">
            <span class="dfms-brand-mark" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3v18M5 8c0 4 3 6 7 6M19 8c0 4-3 6-7 6"/>
                </svg>
            </span>
            <span class="dfms-brand-text">
                <span class="dfms-brand-name">DFM</span>
                <span class="dfms-brand-sub"><?php echo e($dfms_farm_name); ?></span>
            </span>
            <button type="button" class="dfms-icon-btn" data-drawer-close aria-label="Close menu" style="margin-left:auto;">
                <?php echo dfms_icon('close'); ?>
            </button>
        </div>

        <div class="dfms-nav">
            <?php foreach ($dfms_nav as $section): ?>
                <?php if (!empty($section['label'])): ?>
                    <div class="dfms-nav-section"><?php echo e($section['label']); ?></div>
                <?php endif; ?>
                <?php foreach ($section['items'] as $item): ?>
                    <?php dfms_render_nav_item($item, $dfms_active, $dfms_unread_alerts); ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div class="dfms-sidebar-footer">
            <div class="dfms-dropdown-header" style="border:none; padding-bottom:var(--space-2); margin-bottom:0;">
                <div class="dfms-dropdown-name"><?php echo e($dfms_username); ?></div>
                <div class="dfms-dropdown-meta"><?php echo e($dfms_role_label); ?></div>
            </div>
            <a href="<?php echo BASE_URL; ?>logout.php" class="dfms-nav-item">
                <?php echo dfms_icon('logout'); ?>Sign out
            </a>
        </div>
    </nav>

    <!-- ================= Mobile bottom navigation ================= -->
    <nav class="dfms-bottom-nav" aria-label="Quick navigation">
        <ul>
            <?php foreach ($dfms_bottom as $item): ?>
                <li>
                    <a href="<?php echo e(BASE_URL . $item['url']); ?>"
                       class="<?php echo $item['key'] === $dfms_active ? 'active' : ''; ?>"
                       <?php echo $item['key'] === $dfms_active ? 'aria-current="page"' : ''; ?>>
                        <?php echo dfms_icon($item['icon']); ?>
                        <span><?php echo e($item['label']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- ================= Command palette / global search =================
         Navigation-only for now: it moves between the sections this user can
         actually reach. Record search (cows, tags, cases) arrives with the
         search backend in a later version. -->
    <div class="dfms-palette-backdrop" data-palette-backdrop role="dialog" aria-modal="true" aria-label="Search and commands">
        <div class="dfms-palette">
            <div class="dfms-palette-input-row">
                <?php echo dfms_icon('search'); ?>
                <input type="text" class="dfms-palette-input" data-palette-input
                       placeholder="Jump to…" aria-label="Search navigation" autocomplete="off">
                <span class="dfms-kbd">Esc</span>
            </div>
            <div class="dfms-palette-results" data-palette-results>
                <div class="dfms-palette-group">Navigate</div>
                <?php foreach ($dfms_nav as $section): ?>
                    <?php foreach ($section['items'] as $item): ?>
                        <?php if (!empty($item['url']) && ($item['status'] ?? '') !== 'soon'): ?>
                            <a class="dfms-palette-item" data-palette-item
                               data-label="<?php echo e($item['label']); ?>"
                               href="<?php echo e(BASE_URL . $item['url']); ?>">
                                <?php echo dfms_icon($item['icon']); ?>
                                <span><?php echo e($item['label']); ?></span>
                                <span class="dfms-palette-hint"><?php echo e($section['label'] ?? 'General'); ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="dfms-palette-empty dfms-empty-state" data-palette-empty hidden style="padding:var(--space-8) var(--space-4);">
                    <div class="dfms-empty-title">No matches</div>
                    <p class="dfms-empty-text dfms-mb-0">Nothing here matches that search.</p>
                </div>
            </div>
            <div class="dfms-palette-footer">
                <span>&uarr;&darr; to navigate</span>
                <span>&crarr; to open</span>
                <span>Esc to close</span>
            </div>
        </div>
    </div>

    <div class="dfms-toast-region" data-toast-region aria-live="polite" aria-atomic="true"></div>

    <script src="<?php echo BASE_URL; ?>assets/js/main.js" defer></script>
<?php endif; ?>
</body>

</html>
