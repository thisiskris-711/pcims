        </div><!-- /.content-wrapper -->
    </main><!-- /.main-content -->
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Modal Overlay -->
    <div class="modal-overlay" id="modalOverlay"></div>
    
    <!-- App Scripts -->
    <script src="<?= APP_URL ?>/assets/js/app.js"></script>
    <?php if (isset($pageScripts)): ?>
        <?php foreach ($pageScripts as $script): ?>
        <script src="<?= APP_URL ?>/assets/js/<?= $script ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script>
        // Initialize Lucide icons
        lucide.createIcons();
        
        // CSRF token for AJAX requests
        window.CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        window.APP_URL = '<?= APP_URL ?>';
    </script>
</body>
</html>
