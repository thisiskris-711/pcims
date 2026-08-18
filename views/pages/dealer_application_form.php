<?php
/**
 * Public Dealer Application Form
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PC Dealer Registration</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        body {
            background-color: #f5f5f5;
        }
        .header-banner {
            background-color: #c62026;
            padding: 20px;
            color: white;
            text-align: center;
        }
        .header-banner img {
            max-width: 200px;
        }
        .application-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .app-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .app-card-header {
            background-color: white;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
            font-size: 20px;
            font-weight: 600;
            color: #666;
        }
        .app-card-body {
            padding: 30px;
        }
        .section-title {
            background-color: #c62026;
            color: white;
            padding: 10px 15px;
            margin: -30px -30px 20px -30px;
            font-weight: 600;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        @media (max-width: 768px) {
            .grid-3, .grid-2 {
                grid-template-columns: 1fr;
            }
        }
        .btn-submit {
            background-color: #ff0000;
            color: white;
            font-weight: bold;
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }
        .btn-submit:hover {
            background-color: #d10000;
        }
        .success-message {
            display: none;
            background-color: #dcfce7;
            color: #166534;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

    <div class="header-banner">
        <h2>Personal Collection</h2>
        <p>Since 2003 | Nearly 700 branches nationwide</p>
    </div>

    <div class="application-container">
        
        <div class="success-message" id="successMessage">
            <i data-lucide="check-circle" style="width: 48px; height: 48px; margin: 0 auto 10px auto; display: block;"></i>
            <h3>Registration Submitted!</h3>
            <p>Thank you for applying to be a PC Dealer. We will contact you shortly.</p>
        </div>

        <form id="appForm" class="app-card">
            <div class="app-card-header">
                Be a PC Dealer today!
            </div>
            
            <div class="app-card-body">
                <div class="section-title">Personal Information</div>
                
                <div class="grid-3">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="first_name" id="first_name" required placeholder="First Name">
                        <label for="first_name">First Name *</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="middle_name" id="middle_name" placeholder="Middle Name">
                        <label for="middle_name">Middle Name</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="last_name" id="last_name" required placeholder="Last Name">
                        <label for="last_name">Last Name *</label>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group form-floating">
                        <input type="tel" class="form-control" name="phone" id="phone" required placeholder="Mobile Number" maxlength="11">
                        <label for="phone">Mobile Number *</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="email" class="form-control" name="email" id="email" placeholder="Email Address">
                        <label for="email">Email Address</label>
                    </div>
                </div>

                <div class="form-group form-floating" style="margin-top: 15px;">
                    <input type="text" class="form-control" name="address1" id="address1" placeholder="House No. / Street">
                    <label for="address1">House No. / Street</label>
                </div>

                <div class="grid-2" style="margin-top: 15px;">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="region" id="region" placeholder="Region">
                        <label for="region">Region</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="province" id="province" placeholder="Province">
                        <label for="province">Province</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="city" id="city" placeholder="City / Municipality">
                        <label for="city">City / Municipality</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="barangay" id="barangay" placeholder="Barangay">
                        <label for="barangay">Barangay</label>
                    </div>
                </div>
            </div>

            <div class="app-card-body" style="border-top: 1px solid #eee;">
                <div class="section-title">Other Details</div>

                <div class="grid-2">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="preferred_branch" id="preferred_branch" placeholder="Preferred Branch">
                        <label for="preferred_branch">Preferred Branch</label>
                    </div>
                    <div class="form-group form-floating">
                        <select class="form-select form-control" name="source" id="source">
                            <option value="">Select Source...</option>
                            <option value="Facebook">Facebook</option>
                            <option value="Friend/Family">Friend/Family</option>
                            <option value="Branch Walk-in">Branch Walk-in</option>
                            <option value="Other">Other</option>
                        </select>
                        <label for="source">Where did you hear about us?</label>
                    </div>
                </div>
            </div>

            <div class="app-card-body" style="border-top: 1px solid #eee;">
                <div class="section-title">Recruiter Details</div>

                <div class="grid-2">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="recruiter_id" id="recruiter_id" placeholder="Recruiter's ID">
                        <label for="recruiter_id">Recruiter's ID</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="recruiter_name" id="recruiter_name" placeholder="Recruiter's Name">
                        <label for="recruiter_name">Recruiter's Name</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="tel" class="form-control" name="recruiter_phone" id="recruiter_phone" placeholder="Mobile No.">
                        <label for="recruiter_phone">Recruiter's Mobile No.</label>
                    </div>
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" name="recruiter_fb" id="recruiter_fb" placeholder="Facebook Profile">
                        <label for="recruiter_fb">Recruiter's FB Profile</label>
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 10px;">
                        <input type="checkbox" required style="width: 20px; height: 20px;">
                        <span>I am 18 years old or above.</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" required style="width: 20px; height: 20px;">
                        <span>I agree to the Terms and Conditions and Data Privacy Policy.</span>
                    </label>
                </div>

                <div style="margin-top: 30px; text-align: center;">
                    <button type="submit" class="btn-submit" id="submitBtn">SUBMIT REGISTRATION FORM</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        lucide.createIcons();

        document.getElementById('appForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = 'Submitting...';

            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());

            try {
                const res = await fetch('<?= APP_URL ?>/api/dealer_applications?action=submit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await res.json();
                
                if (res.ok) {
                    this.style.display = 'none';
                    document.getElementById('successMessage').style.display = 'block';
                    window.scrollTo(0, 0);
                } else {
                    alert(result.error || 'An error occurred');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            } catch (err) {
                alert('Connection error');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    </script>
</body>
</html>
