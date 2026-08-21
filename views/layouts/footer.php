        </div><!-- /.content-wrapper -->
    </main><!-- /.main-content -->
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Modal Overlay -->
    <div class="modal-overlay" id="modalOverlay"></div>
    
    <!-- Logout Confirmation Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Logout</h3>
            <button class="modal-close" onclick="closeModal('logoutModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to log out?</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('logoutModal')">Cancel</button>
            <a href="<?= APP_URL ?>/logout" class="btn btn-primary">
                <i data-lucide="log-out" style="width:16px;height:16px;"></i> Logout
            </a>
        </div>
    </div>

    <!-- Session Timeout Warning Modal -->
    <div class="modal" id="sessionTimeoutModal" data-backdrop="static">
        <div class="modal-header">
            <h3 class="modal-title" style="color: var(--accent-primary);"><i data-lucide="alert-triangle" style="width:20px;height:20px;margin-right:8px;vertical-align:text-bottom;"></i> Session Expiring</h3>
            <button class="modal-close" onclick="resetInactivityTimer()"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
        </div>
        <div class="modal-body">
            <p>Your session will expire in <strong id="sessionTimeoutCountdown">60</strong> seconds due to inactivity.</p>
            <p class="text-muted" style="margin-top: 10px; font-size: 0.9rem;">Please interact with the page or click below to stay logged in.</p>
        </div>
        <div class="modal-footer">
            <a href="<?= APP_URL ?>/logout?reason=timeout" class="btn btn-secondary">Logout Now</a>
            <button class="btn btn-primary" onclick="resetInactivityTimer()">Stay Logged In</button>
        </div>
    </div>

    <!-- App Scripts -->
    <script src="<?= APP_URL ?>/assets/js/app.js?v=<?= filemtime(ROOT_PATH . '/public/assets/js/app.js') ?>"></script>
    <?php if (isset($pageScripts)): ?>
        <?php foreach ($pageScripts as $script): ?>
        <script src="<?= APP_URL ?>/assets/js/<?= $script ?>?v=<?= filemtime(ROOT_PATH . '/public/assets/js/' . $script) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        window.CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        window.APP_URL = '<?= APP_URL ?>';
        window.APP_NAME = '<?= sanitize(APP_NAME) ?>';
    </script>
</body>
</html>
