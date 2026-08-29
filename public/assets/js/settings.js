document.addEventListener('DOMContentLoaded', () => {
    loadSettings();
    
    document.getElementById('settingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const form = e.target;
        const submitBtn = form.closest('.content-wrapper').querySelector('button[form="settingsForm"]');
        const originalText = submitBtn.innerHTML;
        
        try {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width:18px;height:18px;"></i> Saving...';
            lucide.createIcons();
            
            const formData = new FormData(form);
            const data = {};
            
            // Collect standard inputs
            for (const [key, value] of formData.entries()) {
                data[key] = value;
            }
            
            // Handle unchecked checkboxes (FormData omits them)
            const checkboxes = form.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => {
                data[cb.name] = cb.checked ? 1 : 0;
            });
            
            const res = await fetch(`${window.APP_URL}/api/settings`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.CSRF_TOKEN || data.csrf_token || ''
                },
                body: JSON.stringify(data)
            });
            
            const json = await res.json();
            
            if (!res.ok) {
                throw new Error(json.error || 'Failed to save settings');
            }
            
            if (typeof showToast === 'function') {
                showToast('Settings saved successfully', 'success');
            } else {
                alert('Settings saved successfully');
            }
            
        } catch (error) {
            console.error('Save error:', error);
            if (typeof showToast === 'function') {
                showToast(error.message, 'error');
            } else {
                alert(error.message);
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            lucide.createIcons();
        }
    });
});

async function loadSettings() {
    try {
        const res = await fetch(`${window.APP_URL}/api/settings`);
        if (!res.ok) throw new Error('Failed to load settings');
        
        const settings = await res.json();
        
        const form = document.getElementById('settingsForm');
        
        // Populate form
        Object.keys(settings).forEach(key => {
            const el = form.elements[key];
            if (el) {
                if (el.type === 'checkbox') {
                    el.checked = (settings[key] == 1 || settings[key] === true);
                } else {
                    el.value = settings[key];
                }
            }
        });
        
    } catch (error) {
        console.error('Load error:', error);
        if (typeof showToast === 'function') {
            showToast(error.message, 'error');
        }
    }
}
