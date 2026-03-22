<?php
/**
 * JavaScript Error Diagnostic Tool
 * Identify and fix JavaScript errors in forgot password page
 */

require_once 'config/config.php';
require_once 'includes/security.php';

// Set security headers
set_security_headers();

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JavaScript Diagnostic - PCIMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fc; font-family: Arial, sans-serif; }
        .diagnostic-container { max-width: 1000px; margin: 50px auto; }
        .error-log { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; }
        .fix-card { background: #d4edda; border-left: 4px solid #28a745; }
        .warning-card { background: #fff3cd; border-left: 4px solid #ffc107; }
        .error-card { background: #f8d7da; border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
    <div class="diagnostic-container">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h2><i class="fas fa-bug me-2"></i>JavaScript Error Diagnostic</h2>
                <p class="mb-0">Identify and fix browser extension conflicts and JavaScript errors</p>
            </div>
            <div class="card-body">
                
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle me-2"></i>About the Error</h5>
                    <p>The error "A listener indicated an asynchronous response by returning true, but the message channel closed before a response was received" typically indicates:</p>
                    <ul>
                        <li>Browser extension conflicts</li>
                        <li>Page navigation before async operations complete</li>
                        <li>Service worker or background script issues</li>
                        <li>Browser developer tools interference</li>
                    </ul>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">🔍 Error Detection</h6>
                            </div>
                            <div class="card-body">
                                <button type="button" class="btn btn-primary" onclick="startErrorDetection()">
                                    <i class="fas fa-search me-2"></i>Start Error Detection
                                </button>
                                <button type="button" class="btn btn-secondary ms-2" onclick="clearErrors()">
                                    <i class="fas fa-trash me-2"></i>Clear Errors
                                </button>
                                
                                <div class="mt-3">
                                    <h6>Detected Errors:</h6>
                                    <div id="errorLog" class="error-log">
                                        <div class="text-muted">No errors detected yet...</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">🛠️ Quick Fixes</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-warning" onclick="disableExtensions()">
                                        <i class="fas fa-puzzle-piece me-2"></i>Disable Browser Extensions
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="testForgotPassword()">
                                        <i class="fas fa-key me-2"></i>Test Forgot Password
                                    </button>
                                    <button type="button" class="btn btn-success" onclick="runDiagnostic()">
                                        <i class="fas fa-stethoscope me-2"></i>Run Full Diagnostic
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fix-card card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-tools me-2"></i>Solution Steps</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li><strong>Disable Browser Extensions:</strong>
                                <ul>
                                    <li>Open browser in incognito/private mode</li>
                                    <li>Or disable all extensions temporarily</li>
                                    <li>Common culprits: Ad blockers, password managers, developer tools</li>
                                </ul>
                            </li>
                            <li><strong>Clear Browser Cache:</strong>
                                <ul>
                                    <li>Ctrl+Shift+Delete (Windows) or Cmd+Shift+Delete (Mac)</li>
                                    <li>Clear cache and cookies</li>
                                    <li>Restart browser</li>
                                </ul>
                            </li>
                            <li><strong>Update Browser:</strong>
                                <ul>
                                    <li>Ensure Chrome/Firefox/Edge is up to date</li>
                                    <li>Older browsers may have async handling issues</li>
                                </ul>
                            </li>
                            <li><strong>Try Different Browser:</strong>
                                <ul>
                                    <li>Test in Chrome, Firefox, Edge, Safari</li>
                                    <li>If works in one browser, it's extension-related</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                </div>

                <div class="warning-card card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-code me-2"></i>Code Improvements</h5>
                    </div>
                    <div class="card-body">
                        <p>If the issue persists, here are code improvements to prevent the error:</p>
                        
                        <h6>1. Add Error Handling to JavaScript</h6>
                        <pre><code>// Add to forgot_password.php before &lt;/script&gt; tag
window.addEventListener('error', function(e) {
    console.error('JavaScript Error:', e.error);
    // Prevent extension-related errors from showing
    if (e.message.includes('message channel closed')) {
        e.preventDefault();
        return false;
    }
});

window.addEventListener('unhandledrejection', function(e) {
    console.error('Unhandled Promise Rejection:', e.reason);
    // Prevent extension-related errors
    if (e.reason && e.reason.toString().includes('message channel closed')) {
        e.preventDefault();
        return false;
    }
});</code></pre>

                        <h6>2. Add Page Unload Handler</h6>
                        <pre><code>// Add before &lt;/script&gt; tag
window.addEventListener('beforeunload', function() {
    // Cancel any pending async operations
    return null;
});</code></pre>

                        <h6>3. Improve Form Submission</h6>
                        <pre><code>// Replace existing form submit handler
document.querySelector('form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value.trim();
    
    if (!email) {
        alert('Please enter your email address');
        return;
    }
    
    // Submit form synchronously to avoid async issues
    this.submit();
});</code></pre>
                    </div>
                </div>

                <div class="text-center">
                    <a href="forgot_password.php" class="btn btn-primary me-2">
                        <i class="fas fa-arrow-left me-2"></i>Back to Forgot Password
                    </a>
                    <a href="login.php" class="btn btn-secondary">
                        <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Error detection system
        let errorCount = 0;
        let errors = [];

        // Enhanced error handling
        window.addEventListener('error', function(e) {
            const errorMsg = `Error: ${e.message} at ${e.filename}:${e.lineno}`;
            errors.push({
                type: 'error',
                message: errorMsg,
                timestamp: new Date().toISOString()
            });
            updateErrorLog();
            
            // Prevent extension-related errors
            if (e.message.includes('message channel closed')) {
                e.preventDefault();
                return false;
            }
        });

        window.addEventListener('unhandledrejection', function(e) {
            const errorMsg = `Promise Rejection: ${e.reason}`;
            errors.push({
                type: 'unhandledrejection',
                message: errorMsg,
                timestamp: new Date().toISOString()
            });
            updateErrorLog();
            
            // Prevent extension-related errors
            if (e.reason && e.reason.toString().includes('message channel closed')) {
                e.preventDefault();
                return false;
            }
        });

        function updateErrorLog() {
            const errorLog = document.getElementById('errorLog');
            if (errors.length === 0) {
                errorLog.innerHTML = '<div class="text-muted">No errors detected yet...</div>';
            } else {
                errorLog.innerHTML = errors.map(err => 
                    `<div class="${err.type === 'error' ? 'text-danger' : 'text-warning'}">
                        <small>[${err.timestamp}]</small><br>
                        ${err.message}
                    </div><hr>`
                ).join('');
            }
        }

        function startErrorDetection() {
            errors = [];
            updateErrorLog();
            
            // Trigger some test operations to detect errors
            console.log('Starting error detection...');
            
            // Test async operation
            setTimeout(() => {
                console.log('Async operation completed');
            }, 100);
            
            // Test promise
            Promise.resolve().then(() => {
                console.log('Promise resolved');
            }).catch(err => {
                console.log('Promise rejected:', err);
            });
            
            setTimeout(() => {
                if (errors.length === 0) {
                    alert('No JavaScript errors detected! The issue may be browser extension related.');
                } else {
                    alert(`Detected ${errors.length} error(s). See the error log for details.`);
                }
            }, 2000);
        }

        function clearErrors() {
            errors = [];
            updateErrorLog();
            console.clear();
        }

        function disableExtensions() {
            alert('To disable browser extensions:\n\n1. Open browser in Incognito/Private mode\nOR\n2. Go to browser extensions settings\n3. Disable all extensions temporarily\n4. Refresh the page\n5. Test forgot password functionality');
        }

        function testForgotPassword() {
            window.open('forgot_password.php', '_blank');
        }

        function runDiagnostic() {
            console.log('=== PCIMS JavaScript Diagnostic ===');
            console.log('Browser:', navigator.userAgent);
            console.log('Platform:', navigator.platform);
            console.log('Language:', navigator.language);
            console.log('Cookies Enabled:', navigator.cookieEnabled);
            console.log('Online:', navigator.onLine);
            
            // Check for common extension indicators
            if (window.chrome && window.chrome.runtime) {
                console.log('Chrome extensions may be active');
            }
            
            console.log('=== End Diagnostic ===');
            
            alert('Diagnostic complete! Check browser console for details.');
        }

        // Page load detection
        window.addEventListener('load', function() {
            console.log('Page fully loaded');
        });

        // Prevent async issues on page unload
        window.addEventListener('beforeunload', function() {
            return null;
        });
    </script>
</body>
</html>
