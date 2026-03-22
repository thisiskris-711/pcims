# Password Reset System Setup Guide

## Overview
The PCIMS password reset system is now fully implemented with the following components:

- **Forgot Password Page** - Users can request password resets
- **Reset Password Page** - Users can set new passwords using secure tokens
- **Email Integration** - Automated password reset emails
- **Database Schema** - Required columns for token management

## Files Created

### 1. Core Files
- `forgot_password.php` - Password reset request page
- `reset_password.php` - Password reset confirmation page
- `config/email_config.php` - Email configuration and templates

### 2. Database Migration
- `migrate_password_reset.php` - Database schema update script

## Setup Instructions

### Step 1: Database Migration
Run the migration script to add required columns:

```bash
php migrate_password_reset.php
```

This will add:
- `reset_token` (VARCHAR(64)) - Stores secure reset tokens
- `reset_expiry` (DATETIME) - Token expiration timestamp

### Step 2: Email Configuration
Configure email settings in `config/email_config.php`:

```php
// Update these settings
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password'); // Use app password for Gmail
define('SMTP_ENCRYPTION', 'tls');

define('EMAIL_FROM_ADDRESS', 'noreply@yourdomain.com');
```

### Step 3: Install PHPMailer (if not already installed)

```bash
composer require phpmailer/phpmailer
```

Or download manually from: https://github.com/PHPMailer/PHPMailer

Place in: `vendor/PHPMailer/`

### Step 4: Test the System

1. Navigate to `login.php`
2. Click "Forgot password?"
3. Enter your email address
4. Check your email (or see demo link on screen)
5. Click the reset link
6. Set a new password following the requirements

## Security Features

### Token Security
- **Cryptographically Secure Tokens** - Uses `random_bytes(32)` for token generation
- **One-Hour Expiration** - Tokens automatically expire after 1 hour
- **Single-Use Tokens** - Tokens are cleared after successful reset
- **CSRF Protection** - All forms include CSRF token validation

### Password Requirements
- Minimum 8 characters
- At least one uppercase letter (A-Z)
- At least one lowercase letter (a-z)
- At least one number (0-9)
- At least one special character (@$!%*?&)

### Other Security Measures
- **Rate Limiting** - Built through existing security functions
- **Activity Logging** - All password reset requests are logged
- **Email Obfuscation** - Doesn't reveal if email exists in system
- **Secure Password Hashing** - Uses `PASSWORD_DEFAULT` algorithm

## Email Templates

The system includes professional HTML email templates:

### Password Reset Email
- Branded with PCIMS colors
- Clear call-to-action button
- Fallback text link
- Security information and expiration notice
- Responsive design

### Welcome Email (Bonus)
- New user onboarding template
- Login instructions
- Getting started guide

## Development vs Production

### Development Mode
- Shows reset link on screen if email fails
- Logs emails to error log
- Safe for testing without real email sending

### Production Mode
- Sends actual emails via SMTP
- No sensitive information displayed
- Professional user experience

## Troubleshooting

### Email Not Sending
1. Check SMTP credentials in `config/email_config.php`
2. Verify PHPMailer is properly installed
3. Check firewall/SMTP port access
4. Review error logs for detailed messages

### Database Issues
1. Ensure migration script ran successfully
2. Verify database permissions
3. Check that columns exist: `reset_token`, `reset_expiry`

### Token Not Working
1. Check token hasn't expired (1-hour limit)
2. Verify URL is complete and not truncated
3. Ensure database connection is working

## Customization

### Email Templates
Edit templates in `config/email_config.php`:
- `send_password_reset_email()` function
- Modify HTML/CSS for branding
- Update footer information

### Password Rules
Update validation in `reset_password.php`:
- Modify regex pattern
- Change minimum length
- Add custom requirements

### Email Design
- Update colors in email CSS
- Add company logo
- Customize layout and messaging

## Maintenance

### Regular Tasks
- Monitor email deliverability
- Check SMTP quota limits
- Review failed reset attempts in logs
- Update email templates as needed

### Security Considerations
- Regularly rotate SMTP passwords
- Monitor for unusual reset activity
- Keep PHPMailer updated
- Review access logs

## Support

For issues with:
- **Database**: Check migration script output
- **Email**: Review SMTP configuration and logs
- **Functionality**: Test each step of the reset flow
- **Security**: Verify all security measures are active

---

**Note**: Delete `migrate_password_reset.php` after successful migration for security.
