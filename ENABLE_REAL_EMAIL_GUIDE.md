# Step-by-Step Guide: Enable Real Email Sending

## Overview
This guide will help you configure the PCIMS password recovery system to send real emails instead of using development mode logging.

---

## 📋 Prerequisites
- XAMPP/WAMP server with PHP
- Internet connection for SMTP
- Email account (Gmail, Outlook, or custom SMTP)
- Composer installed (or manual download option)

---

## 🚀 Step 1: Install PHPMailer

### Option A: Using Composer (Recommended)
1. **Open Command Prompt/Terminal**
   ```bash
   cd C:\xampp\htdocs\pcims
   ```

2. **Install PHPMailer**
   ```bash
   composer require phpmailer/phpmailer
   ```

3. **Verify Installation**
   - Check that `vendor/` directory is created
   - Confirm `vendor/phpmailer/phpmailer/` exists

### Option B: Manual Download (If Composer Not Available)
1. **Download PHPMailer**
   - Go to: https://github.com/PHPMailer/PHPMailer/releases
   - Download the latest `PHPMailer-*.zip`

2. **Extract Files**
   - Extract to: `C:\xampp\htdocs\pcims\vendor\`
   - Rename folder to `phpmailer\`

3. **Verify Structure**
   ```
   vendor/
   └── phpmailer/
       └── src/
           ├── PHPMailer.php
           ├── SMTP.php
           └── Exception.php
   ```

---

## ⚙️ Step 2: Configure SMTP Settings

### Open Email Configuration File
```
C:\xampp\htdocs\pcims\config\email_config.php
```

### Update SMTP Settings (Lines 12-26)
```php
// Email Settings - Only define if not already set
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');        // Change to your SMTP server
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);                     // Port (587 for TLS, 465 for SSL)
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', 'your-email@gmail.com'); // Your SMTP username
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', 'your-app-password');      // Your SMTP password
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');             // 'tls' or 'ssl'
}
```

### Email Provider Settings

#### Gmail Configuration
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');
```
**Important**: Use App Password, not regular password
- Go to: https://myaccount.google.com/apppasswords
- Generate 16-digit app password
- Use that as SMTP_PASSWORD

#### Outlook/Hotmail Configuration
```php
define('SMTP_HOST', 'smtp-mail.outlook.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');
```

#### Yahoo Mail Configuration
```php
define('SMTP_HOST', 'smtp.mail.yahoo.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');
```

#### Custom SMTP Configuration
```php
define('SMTP_HOST', 'mail.yourdomain.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl');
```

### Update From Email (Lines 29-34)
```php
if (!defined('EMAIL_FROM_ADDRESS')) {
    define('EMAIL_FROM_ADDRESS', 'noreply@yourdomain.com'); // Your domain email
}
```

---

## 🔧 Step 3: Disable Development Mode

### Open Main Configuration File
```
C:\xampp\htdocs\pcims\config\config.php
```

### Change Development Mode (Line 19)
```php
// Development Mode (set to true in development, false in production)
define('DEVELOPMENT_MODE', false);  // Change from true to false
```

---

## 🧪 Step 4: Test Email Configuration

### Run Email Test Script
1. **Open in Browser**
   ```
   http://localhost/pcims/test_email_simple.php
   ```

2. **Check Test Results**
   - ✅ PHPMailer found
   - ✅ All email constants defined
   - ✅ Email functions working

### Run PHPMailer Setup Test
1. **Open in Browser**
   ```
   http://localhost/pcims/setup_phpmailer.php
   ```

2. **Verify Integration**
   - ✅ PHPMailer class loaded
   - ✅ SMTP configuration set
   - ✅ Test email sent

---

## 📧 Step 5: Test Real Email Sending

### Test Forgot Password Form
1. **Go to Login Page**
   ```
   http://localhost/pcims/login.php
   ```

2. **Click "Forgot password?"**

3. **Enter Real Email Address**
   - Use an email you can access
   - Submit the form

4. **Check Email Results**
   - **Success**: Email should arrive in 1-2 minutes
   - **Check Spam Folder**: If not in inbox
   - **Error Logs**: Check `C:\xampp\apache\logs\error.log`

### Verify Email Content
Real email should contain:
- Professional HTML design
- PCIMS branding
- Password reset button
- Expiration information
- Security notice

---

## 🔍 Step 6: Troubleshooting

### Common Issues & Solutions

#### Issue 1: "SMTP connect() failed"
**Solution**: 
- Check SMTP credentials
- Verify firewall allows SMTP (port 587/465)
- Try different encryption (tls/ssl)

#### Issue 2: "Username and Password not accepted"
**Solution**:
- Use app password for Gmail
- Check email/username format
- Verify account allows SMTP

#### Issue 3: "Connection timed out"
**Solution**:
- Check internet connection
- Try different SMTP port
- Verify SMTP server address

#### Issue 4: Email goes to spam
**Solution**:
- Check SPF/DKIM records
- Use proper from address
- Avoid spam trigger words

### Debug Mode
If emails still don't work, temporarily enable debug logging:

```php
// In config/email_config.php, add to send_email function:
error_log("SMTP Debug - Host: " . SMTP_HOST);
error_log("SMTP Debug - Port: " . SMTP_PORT);
error_log("SMTP Debug - User: " . SMTP_USERNAME);
```

---

## 📊 Step 7: Verify Complete Workflow

### Test Complete Password Reset
1. **Request Reset**: Use forgot password form
2. **Receive Email**: Check email arrives
3. **Click Link**: Verify reset page loads
4. **Set Password**: Enter new password
5. **Login**: Test new password works

### Check Error Logs
```bash
# View Apache error logs
tail -f C:\xampp\apache\logs\error.log
```

Look for:
- Email sending confirmations
- SMTP connection details
- Any error messages

---

## 🎯 Step 8: Production Deployment

### Final Checks
- [ ] DEVELOPMENT_MODE set to false
- [ ] Real SMTP credentials configured
- [ ] Test email received successfully
- [ ] Complete workflow tested
- [ ] Error logs clean

### Security Considerations
- [ ] Use strong SMTP password
- [ ] Enable 2FA on email account
- [ ] Monitor email delivery rates
- [ ] Keep PHPMailer updated

---

## 📞 Support

### If Issues Persist
1. **Check Error Logs**: `C:\xampp\apache\logs\error.log`
2. **Verify SMTP Settings**: Double-check all credentials
3. **Test with Different Email**: Try another SMTP provider
4. **Run Diagnostic**: `http://localhost/pcims/email_diagnostic.php`

### Common SMTP Providers
| Provider | Host | Port | Encryption |
|----------|------|------|------------|
| Gmail | smtp.gmail.com | 587 | TLS |
| Outlook | smtp-mail.outlook.com | 587 | TLS |
| Yahoo | smtp.mail.yahoo.com | 587 | TLS |
| GoDaddy | smtpout.secureserver.net | 80 | None |

---

## ✅ Success Checklist

When completed, you should have:
- ✅ PHPMailer properly installed
- ✅ SMTP credentials configured
- ✅ Development mode disabled
- ✅ Real emails being sent
- ✅ Complete password reset workflow working
- ✅ Professional email templates delivered

Your PCIMS password recovery system is now production-ready!
