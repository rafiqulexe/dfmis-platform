<?php if (isLoggedIn()): ?>
            </main>

            <footer style="background:var(--heading); color:#d1fae5; padding:1.5rem; margin-top:auto; font-size:0.8rem; text-align:center;">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($dfms_farm_name); ?> · Powered by DFMS
            </footer>
        </div>
    </div>

    <!-- Mobile drawer -->
    <div class="dfms-drawer-backdrop" data-drawer-close></div>
    <nav class="dfms-drawer">
        <div class="dfms-sidebar-brand">
            <?php echo htmlspecialchars($dfms_farm_name); ?>
            <button type="button" data-drawer-close aria-label="Close menu" style="margin-left:auto; background:none; border:none; font-size:1.2rem;">&times;</button>
        </div>
        <div class="dfms-nav">
            <?php foreach (array_merge($primary_nav, $secondary_nav) as $url => $label): ?>
                <a href="<?php echo BASE_URL . $url; ?>" class="<?php echo dfms_is_active($current_page, $url) ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
            <a href="<?php echo BASE_URL; ?>logout.php">Logout</a>
        </div>
    </nav>

    <!-- Mobile bottom nav -->
    <nav class="dfms-bottom-nav">
        <ul>
            <?php foreach ($bottom_nav as $url => $label): ?>
                <li>
                    <a href="<?php echo BASE_URL . $url; ?>" class="<?php echo dfms_is_active($current_page, $url) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($label); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <script src="<?php echo BASE_URL; ?>assets/js/main.js"></script>
<?php endif; ?>
</body>

</html>
