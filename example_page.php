<?php
require_once 'config/config.php';
redirect_if_not_logged_in();

$page_title = 'Example Page';

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-file-alt me-2"></i>Example Page
        </h1>
    </div>
    
    <div class="card">
        <div class="card-body">
            <h5>Modular Sidebar Example</h5>
            <p>This page demonstrates how the sidebar has been modularized.</p>
            
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle me-2"></i>Benefits of Modularization:</h6>
                <ul>
                    <li><strong>Reusable:</strong> The sidebar can be included in any page with a single line</li>
                    <li><strong>Maintainable:</strong> Changes to the sidebar only need to be made in one file</li>
                    <li><strong>Consistent:</strong> All pages will have the same navigation structure</li>
                    <li><strong>Clean:</strong> Page files are more focused on their specific content</li>
                </ul>
            </div>
            
            <h6>How to Use:</h6>
            <div class="bg-light p-3 rounded">
                <pre><code>&lt;?php
$page_title = 'Your Page Title';
include 'includes/header.php';
?&gt;

&lt;!-- Your page content goes here --&gt;

&lt;?php include 'includes/footer.php'; ?&gt;</code></pre>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
