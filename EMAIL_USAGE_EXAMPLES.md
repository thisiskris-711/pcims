# PHPMailer Integration - Usage Examples

## Quick Start

```php
// Load the email helper
require_once 'includes/email.php';

// Create email helper instance
$email = new EmailHelper();

// Check if email is configured
if ($email->isConfigured()) {
    // Send a simple email
    $result = $email->sendEmail(
        'recipient@example.com',
        'Test Subject',
        '<h1>HTML Content</h1><p>This is a test email.</p>',
        'Plain text alternative content'
    );
    
    if ($result) {
        echo "Email sent successfully!";
    } else {
        echo "Failed to send email.";
    }
}
```

## Available Email Methods

### 1. Low Stock Alert
```php
$email->sendLowStockAlert('Product Name', 5);
```

### 2. Out of Stock Alert
```php
$email->sendOutOfStockAlert('Product Name');
```

### 3. Welcome Email
```php
$email->sendWelcomeEmail('user@example.com', 'John Doe', 'temp123');
```

### 4. Password Reset
```php
$email->sendPasswordResetEmail('user@example.com', 'John Doe', 'https://example.com/reset?token=abc123');
```

### 5. Order Confirmation
```php
$orderData = [
    'order_number' => 'ORD-001',
    'customer_name' => 'John Doe',
    'type' => 'sales',
    'total_amount' => 299.99,
    'items' => [
        ['product_name' => 'Product A', 'quantity' => 2, 'unit_price' => 99.99],
        ['product_name' => 'Product B', 'quantity' => 1, 'unit_price' => 100.01]
    ]
];
$email->sendOrderConfirmation($orderData, 'customer@example.com');
```

### 6. Test Email Configuration
```php
$email->testConfiguration('test@example.com');
```

## Configuration

### Via Settings Page
1. Go to `settings.php?tab=email`
2. Configure SMTP settings
3. Enable email notifications
4. Test configuration

### Via Code
```php
$email->updateConfig([
    'email_enabled' => '1',
    'email_host' => 'smtp.gmail.com',
    'email_port' => '587',
    'email_username' => 'your-email@gmail.com',
    'email_password' => 'your-app-password',
    'email_encryption' => 'tls',
    'email_from' => 'noreply@yourcompany.com',
    'email_from_name' => 'Your Company'
]);
```

## SMTP Configuration Examples

### Gmail/Google Workspace
- Host: `smtp.gmail.com`
- Port: `587`
- Encryption: `TLS`
- Use App Password (not regular password)

### Outlook/Office 365
- Host: `smtp.office365.com`
- Port: `587`
- Encryption: `TLS`

### Custom SMTP
- Use your provider's specific settings
- Common ports: 25 (no encryption), 465 (SSL), 587 (TLS)

## Integration with Existing System

The email functionality is automatically integrated with:

- **Low Stock Notifications**: Automatically sends emails when products are low on stock
- **Out of Stock Alerts**: Immediate notifications for zero stock items
- **User Management**: Welcome emails and password reset functionality
- **Order Processing**: Order confirmation emails for sales and purchases

## Error Handling

```php
try {
    $email = new EmailHelper();
    $result = $email->sendEmail($to, $subject, $body);
    
    if (!$result) {
        // Check logs for error details
        error_log("Email sending failed - check SMTP configuration");
    }
} catch (Exception $e) {
    error_log("Email error: " . $e->getMessage());
}
```

## Troubleshooting

1. **Email not sending**: Check SMTP settings and enable debug mode
2. **Authentication failed**: Verify username/password or use app password
3. **Connection timeout**: Check firewall and SMTP port accessibility
4. **SSL/TLS errors**: Verify encryption settings match provider requirements
