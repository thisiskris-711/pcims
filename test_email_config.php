<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('admin');

$page_title = 'Email Configuration Test';
$diagnostic = [];
$test_result = null;

// Run diagnostics
$diagnostic['Configuration'] = [
  'SMTP Host' => SMTP_HOST,
  'SMTP Port' => SMTP_PORT,
  'SMTP User' => SMTP_USER ? substr(SMTP_USER, 0, 5) . '***' : '(empty)',
  'Encryption' => SMTP_ENCRYPTION ?: 'None',
  'Email Enabled' => EMAIL_ENABLED ? 'Yes' : 'No'
];

// Check PHP extensions
$diagnostic['Extensions'] = [
  'OpenSSL' => extension_loaded('openssl') ? '✓ Available' : '✗ Missing (REQUIRED)',
  'Sockets' => extension_loaded('sockets') ? '✓ Available' : '✗ Missing (Recommended)',
];

// Check file paths
$phpmailer_path = __DIR__ . '/includes/phpmailer/PHPMailer-master/src/PHPMailer.php';
$diagnostic['PHPMailer Files'] = [
  'PHPMailer.php' => file_exists($phpmailer_path) ? '✓ Found' : '✗ Missing',
  'SMTP.php' => file_exists(__DIR__ . '/includes/phpmailer/PHPMailer-master/src/SMTP.php') ? '✓ Found' : '✗ Missing',
  'Exception.php' => file_exists(__DIR__ . '/includes/phpmailer/PHPMailer-master/src/Exception.php') ? '✓ Found' : '✗ Missing'
];

// Attempt to load PHPMailer
$phpmailer_ok = false;
if (file_exists($phpmailer_path)) {
  try {
    require_once 'includes/email.php';
    $emailHelper = new EmailHelper();
    $phpmailer_ok = true;
    $diagnostic['PHPMailer'] = [
      'Loaded' => '✓ Successfully loaded',
      'Configured' => $emailHelper->isConfigured() ? '✓ Yes' : '✗ No (check settings)'
    ];
  } catch (Exception $e) {
    $diagnostic['PHPMailer'] = [
      'Loaded' => '✗ Load error: ' . $e->getMessage(),
      'Configured' => 'N/A'
    ];
  }
}

// Handle test email submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
  $test_email = trim($_POST['test_email']);

  if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
    $test_result = ['status' => 'error', 'message' => 'Invalid email address format'];
  } else if (!$phpmailer_ok) {
    $test_result = ['status' => 'error', 'message' => 'PHPMailer not properly initialized'];
  } else {
    try {
      if ($emailHelper->testConfiguration($test_email)) {
        $test_result = [
          'status' => 'success',
          'message' => 'Test email sent successfully to ' . htmlspecialchars($test_email) . '. Check your inbox!'
        ];
      } else {
        $test_result = [
          'status' => 'error',
          'message' => 'Test email failed: ' . $emailHelper->getLastError()
        ];
      }
    } catch (Exception $e) {
      $test_result = [
        'status' => 'error',
        'message' => 'Exception: ' . $e->getMessage()
      ];
    }
  }
}

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
  <div class="row mb-4">
    <div class="col-md-12">
      <h2><i class="fas fa-envelope me-2"></i>Email Configuration Diagnostics</h2>
      <p class="text-muted">Test your SMTP settings and send a test email</p>
    </div>
  </div>

  <!-- Status Alerts -->
  <?php if ($test_result): ?>
    <div class="row mb-4">
      <div class="col-md-10">
        <div class="alert alert-<?php echo $test_result['status'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
          <strong><?php echo $test_result['status'] === 'success' ? '✓ Success' : '✗ Error'; ?>:</strong>
          <?php echo $test_result['message']; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row">
    <!-- Configuration Details -->
    <div class="col-md-6 mb-4">
      <div class="card">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="fas fa-cog me-2"></i>SMTP Configuration</h5>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-borderless">
              <tbody>
                <?php foreach ($diagnostic['Configuration'] as $key => $value): ?>
                  <tr>
                    <td><strong><?php echo $key; ?>:</strong></td>
                    <td><code><?php echo htmlspecialchars($value); ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- System Check -->
    <div class="col-md-6 mb-4">
      <div class="card">
        <div class="card-header bg-info text-white">
          <h5 class="mb-0"><i class="fas fa-cube me-2"></i>System Requirements</h5>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-borderless">
              <tbody>
                <?php foreach ($diagnostic['Extensions'] as $key => $value): ?>
                  <tr>
                    <td><strong><?php echo $key; ?>:</strong></td>
                    <td>
                      <span class="<?php echo strpos($value, '✓') !== false ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $value; ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row mb-4">
    <!-- PHPMailer Files Check -->
    <div class="col-md-6 mb-4">
      <div class="card">
        <div class="card-header bg-secondary text-white">
          <h5 class="mb-0"><i class="fas fa-file-code me-2"></i>PHPMailer Files</h5>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-borderless">
              <tbody>
                <?php foreach ($diagnostic['PHPMailer Files'] as $key => $value): ?>
                  <tr>
                    <td><strong><?php echo $key; ?>:</strong></td>
                    <td>
                      <span class="<?php echo strpos($value, '✓') !== false ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $value; ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- PHPMailer Status -->
    <div class="col-md-6 mb-4">
      <div class="card">
        <div class="card-header <?php echo isset($diagnostic['PHPMailer']) && strpos(implode('', $diagnostic['PHPMailer']), '✓') !== false ? 'bg-success' : 'bg-warning'; ?> text-white">
          <h5 class="mb-0"><i class="fas fa-lightbulb me-2"></i>PHPMailer Status</h5>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-borderless">
              <tbody>
                <?php if (isset($diagnostic['PHPMailer'])): ?>
                  <?php foreach ($diagnostic['PHPMailer'] as $key => $value): ?>
                    <tr>
                      <td><strong><?php echo $key; ?>:</strong></td>
                      <td>
                        <span class="<?php echo strpos($value, '✓') !== false ? 'text-success' : 'text-danger'; ?>">
                          <?php echo $value; ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="2" class="text-danger">PHPMailer not loaded - check file paths above</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Test Email Form -->
  <div class="row mb-4">
    <div class="col-md-8">
      <div class="card">
        <div class="card-header bg-success text-white">
          <h5 class="mb-0"><i class="fas fa-paper-plane me-2"></i>Send Test Email</h5>
        </div>
        <div class="card-body">
          <form method="POST" action="">
            <div class="mb-3">
              <label for="test_email" class="form-label">Test Email Address</label>
              <input type="email" class="form-control" id="test_email" name="test_email"
                required placeholder="your-email@example.com">
              <small class="form-text text-muted">We'll send a test email to verify your configuration is working</small>
            </div>
            <button type="submit" class="btn btn-success" <?php echo $phpmailer_ok ? '' : 'disabled'; ?>>
              <i class="fas fa-paper-plane me-2"></i>Send Test Email
            </button>
            <?php if (!$phpmailer_ok): ?>
              <p class="text-danger mt-2"><small>Test email disabled - PHPMailer not properly loaded</small></p>
            <?php endif; ?>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Troubleshooting -->
  <div class="row">
    <div class="col-md-12">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i>Troubleshooting</h5>
        </div>
        <div class="card-body">
          <h6>Common Issues:</h6>
          <ul>
            <li><strong>OpenSSL Missing:</strong> Enable OpenSSL in php.ini: uncomment <code>extension=openssl</code></li>
            <li><strong>Connection Timeout:</strong> ISP blocking ports 587/465. Try port 25 or contact host provider</li>
            <li><strong>Authentication Failed:</strong> Check username/password. For Gmail, use App Passwords instead</li>
            <li><strong>Port/Encryption Mismatch:</strong> Use port 587 for TLS, 465 for SSL, 25 for plain</li>
            <li><strong>PHPMailer Files Missing:</strong> Ensure PHPMailer-master folder exists in includes/phpmailer/</li>
          </ul>

          <h6 class="mt-3">Gmail Setup:</h6>
          <ol>
            <li>Enable 2FA on your Google Account</li>
            <li>Visit <a href="https://myaccount.google.com/apppasswords" target="_blank">myaccount.google.com/apppasswords</a></li>
            <li>Generate an App Password and use it (without spaces)</li>
            <li>Use <code>smtp.gmail.com</code>:587 with TLS encryption</li>
          </ol>

          <h6 class="mt-3">Check Error Logs:</h6>
          <p>Review <code>logs/error.log</code> for detailed error messages about email failures</p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>