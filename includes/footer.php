</div>
</main>
</div>

<div class="loading-overlay" id="globalLoadingOverlay" aria-hidden="true">
    <div class="loading-overlay__panel">
        <span class="button-spinner" aria-hidden="true"></span>
        <span>Working on your request...</span>
    </div>
</div>

<script>
(function() {
    function initTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function(element) {
            new bootstrap.Tooltip(element);
        });
    }

    function setSidebarOpen(isOpen) {
        const toggle = document.getElementById('sidebarToggle');
        const backdrop = document.getElementById('appSidebarBackdrop');

        document.body.classList.toggle('sidebar-open', isOpen);

        if (toggle) {
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            const icon = toggle.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-bars', !isOpen);
                icon.classList.toggle('fa-xmark', isOpen);
            }
        }

        if (backdrop) {
            backdrop.classList.toggle('show', isOpen);
        }
    }

    function initSidebar() {
        const toggle = document.getElementById('sidebarToggle');
        const backdrop = document.getElementById('appSidebarBackdrop');
        const mobileQuery = window.matchMedia('(max-width: 991.98px)');

        if (toggle) {
            toggle.addEventListener('click', function(event) {
                event.preventDefault();
                setSidebarOpen(!document.body.classList.contains('sidebar-open'));
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function() {
                setSidebarOpen(false);
            });
        }

        document.querySelectorAll('[data-sidebar-group-toggle]').forEach(function(button) {
            const item = button.closest('.sidebar-item--group');
            if (!item) {
                return;
            }

            button.addEventListener('click', function() {
                const willOpen = !item.classList.contains('is-open');

                if (mobileQuery.matches && willOpen) {
                    document.querySelectorAll('.sidebar-item--group.is-open').forEach(function(openItem) {
                        if (openItem !== item) {
                            openItem.classList.remove('is-open');
                            const openButton = openItem.querySelector('[data-sidebar-group-toggle]');
                            if (openButton) {
                                openButton.setAttribute('aria-expanded', 'false');
                            }
                        }
                    });
                }

                item.classList.toggle('is-open', willOpen);
                button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                setSidebarOpen(false);
            }
        });

        window.addEventListener('resize', function() {
            if (!mobileQuery.matches) {
                setSidebarOpen(false);
            }
        });
    }

    function initAlerts() {
        setTimeout(function() {
            document.querySelectorAll('.alert.alert-dismissible').forEach(function(alert) {
                if (!alert.classList.contains('show')) {
                    return;
                }

                try {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                } catch (error) {
                    alert.remove();
                }
            });
        }, 5000);
    }

    function initMotion() {
        const reduceMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
        const uniqueElements = new Set();
        const revealQueue = [];

        function queueReveal(element) {
            if (!element || uniqueElements.has(element)) {
                return;
            }

            uniqueElements.add(element);
            revealQueue.push(element);
        }

        document.body.classList.add('motion-enabled');
        queueReveal(document.querySelector('.topbar'));
        document.querySelectorAll('.flash-stack .alert').forEach(queueReveal);

        const contentWrapper = document.querySelector('.content-wrapper');
        if (contentWrapper) {
            Array.from(contentWrapper.children).forEach(function(child) {
                if (child.tagName !== 'SCRIPT') {
                    queueReveal(child);
                }
            });
        }

        revealQueue.forEach(function(element, index) {
            element.classList.add('motion-enter');
            element.style.setProperty('--motion-delay', Math.min(index * 55, 330) + 'ms');
        });

        if (reduceMotionQuery.matches) {
            document.body.classList.add('page-is-ready');
            return;
        }

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                document.body.classList.add('page-is-ready');
            });
        });

        document.querySelectorAll('.modal').forEach(function(modal) {
            modal.addEventListener('show.bs.modal', function() {
                modal.classList.add('modal-is-animating');
            });

            modal.addEventListener('hidden.bs.modal', function() {
                modal.classList.remove('modal-is-animating');
            });
        });
    }

    function getLoadingOverlay() {
        return document.getElementById('globalLoadingOverlay');
    }

    function showLoadingOverlay() {
        const overlay = getLoadingOverlay();
        if (overlay) {
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');
        }
    }

    function hideLoadingOverlay() {
        const overlay = getLoadingOverlay();
        if (overlay) {
            overlay.classList.remove('is-visible');
            overlay.setAttribute('aria-hidden', 'true');
        }
    }

    function setSubmitButtonState(button, isLoading) {
        if (!button) {
            return;
        }

        if (button.tagName === 'INPUT') {
            if (isLoading) {
                if (!button.dataset.originalValue) {
                    button.dataset.originalValue = button.value;
                }
                button.value = button.dataset.loadingText || 'Processing...';
                button.disabled = true;
            } else if (button.dataset.originalValue) {
                button.value = button.dataset.originalValue;
                button.disabled = false;
            }
            return;
        }

        if (isLoading) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            const loadingText = button.dataset.loadingText || button.textContent.trim() || 'Processing...';
            button.innerHTML = '<span class="button-spinner me-2" aria-hidden="true"></span>' + loadingText;
            button.disabled = true;
        } else if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
            button.disabled = false;
        }
    }

    function ensureValidationFeedback(field) {
        if (!field || !field.required) {
            return;
        }

        let feedback = field.parentElement ? field.parentElement.querySelector('.invalid-feedback[data-generated="true"]') : null;
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.dataset.generated = 'true';
            field.insertAdjacentElement('afterend', feedback);
        }

        feedback.textContent = field.validationMessage || 'This field is required.';
    }

    function updateFieldValidity(field) {
        if (!field) {
            return;
        }

        ensureValidationFeedback(field);

        if (field.checkValidity()) {
            field.classList.remove('is-invalid');
            if ((field.value || '').trim() !== '') {
                field.classList.add('is-valid');
            } else {
                field.classList.remove('is-valid');
            }
        } else {
            field.classList.remove('is-valid');
            field.classList.add('is-invalid');
            const feedback = field.parentElement ? field.parentElement.querySelector('.invalid-feedback[data-generated="true"]') : null;
            if (feedback) {
                feedback.textContent = field.validationMessage || 'This field is required.';
            }
        }
    }

    function inferPlaceholders() {
        document.querySelectorAll('input.form-control, textarea.form-control, input.form-control-sm, input.form-control-lg').forEach(function(field) {
            const type = (field.getAttribute('type') || 'text').toLowerCase();
            if (!['text', 'email', 'tel', 'search', 'number'].includes(type) || field.placeholder || !field.id) {
                return;
            }

            const label = document.querySelector('label[for="' + field.id + '"]');
            if (!label) {
                return;
            }

            const labelText = label.textContent.replace(/\*/g, '').trim();
            if (labelText) {
                field.placeholder = 'Enter ' + labelText.toLowerCase();
            }
        });
    }

    function initForms() {
        inferPlaceholders();

        document.querySelectorAll('form').forEach(function(form) {
            const fields = form.querySelectorAll('input, select, textarea');
            fields.forEach(function(field) {
                if (field.required) {
                    const label = field.id ? document.querySelector('label[for="' + field.id + '"]') : null;
                    if (label) {
                        label.classList.add('required-label');
                    }
                }

                field.addEventListener('input', function() {
                    updateFieldValidity(field);
                });

                field.addEventListener('change', function() {
                    updateFieldValidity(field);
                });
            });

            form.addEventListener('submit', function(event) {
                const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

                if (form.dataset.submitting === 'true') {
                    event.preventDefault();
                    return;
                }

                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    form.classList.add('was-validated');
                    fields.forEach(updateFieldValidity);

                    const firstInvalid = form.querySelector(':invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                    }
                    hideLoadingOverlay();
                    return;
                }

                form.dataset.submitting = 'true';
                form.classList.add('was-validated');
                submitButtons.forEach(function(button) {
                    setSubmitButtonState(button, true);
                });

                if (form.dataset.disableLoading !== 'true') {
                    showLoadingOverlay();
                }
            });
        });

        window.addEventListener('pageshow', function() {
            hideLoadingOverlay();
            document.querySelectorAll('form[data-submitting="true"]').forEach(function(form) {
                form.dataset.submitting = 'false';
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(button) {
                    setSubmitButtonState(button, false);
                });
            });
        });
    }

    function parseTableValue(value) {
        const cleaned = value.replace(/\s+/g, ' ').trim();
        if (cleaned === '') {
            return '';
        }

        const numeric = cleaned.replace(/[^0-9.\-]/g, '');
        if (numeric !== '' && !Number.isNaN(Number(numeric))) {
            return Number(numeric);
        }

        const time = Date.parse(cleaned);
        if (!Number.isNaN(time)) {
            return time;
        }

        return cleaned.toLowerCase();
    }

    function compareTableValues(a, b) {
        if (typeof a === 'number' && typeof b === 'number') {
            return a - b;
        }

        return String(a).localeCompare(String(b), undefined, {
            numeric: true,
            sensitivity: 'base'
        });
    }

    function initTableEnhancements() {
        document.querySelectorAll('.table').forEach(function(table) {
            if (table.dataset.enhanced === 'true') {
                return;
            }

            table.dataset.enhanced = 'true';
            const tbody = table.tBodies[0];
            if (!tbody || tbody.rows.length < 2) {
                return;
            }

            table.querySelectorAll('thead th').forEach(function(header, index) {
                const label = (header.textContent || '').trim().toLowerCase();
                if (!label || label === 'actions' || label === 'image') {
                    return;
                }

                header.classList.add('is-sortable');
                header.setAttribute('role', 'button');
                header.setAttribute('tabindex', '0');

                function sortHeader() {
                    const currentDirection = header.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
                    const rows = Array.from(tbody.rows);

                    table.querySelectorAll('thead th').forEach(function(th) {
                        th.classList.remove('is-sorted-asc', 'is-sorted-desc');
                        th.dataset.sortDirection = '';
                    });

                    rows.sort(function(rowA, rowB) {
                        const valueA = parseTableValue(rowA.cells[index] ? rowA.cells[index].innerText : '');
                        const valueB = parseTableValue(rowB.cells[index] ? rowB.cells[index].innerText : '');
                        return compareTableValues(valueA, valueB) * (currentDirection === 'asc' ? 1 : -1);
                    });

                    rows.forEach(function(row) {
                        tbody.appendChild(row);
                    });

                    header.dataset.sortDirection = currentDirection;
                    header.classList.add(currentDirection === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
                }

                header.addEventListener('click', sortHeader);
                header.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        sortHeader();
                    }
                });
            });
        });
    }

    function initCustomerHint() {
        const customerName = document.getElementById('customer_name');
        const customerEmail = document.getElementById('customer_email');
        const customerPhone = document.getElementById('customer_phone');

        if (!customerName || !customerEmail || !customerPhone) {
            return;
        }

        let helper = document.getElementById('walkInCustomerHint');
        if (!helper) {
            helper = document.createElement('div');
            helper.id = 'walkInCustomerHint';
            helper.className = 'form-text';
            customerName.insertAdjacentElement('afterend', helper);
        }

        function syncHelper() {
            const hasCustomerInfo = [customerName, customerEmail, customerPhone].some(function(field) {
                return field.value.trim() !== '';
            });

            customerName.placeholder = 'Optional for walk-in customers';
            customerEmail.placeholder = customerEmail.placeholder || 'Enter email address';
            customerPhone.placeholder = customerPhone.placeholder || 'Enter contact number';
            helper.textContent = hasCustomerInfo
                ? 'Customer details will be saved as a registered sale record.'
                : 'Leave customer details blank to complete this sale as walk-in.';
        }

        [customerName, customerEmail, customerPhone].forEach(function(field) {
            field.addEventListener('input', syncHelper);
        });

        syncHelper();
    }

    function initConfirmations() {
        document.querySelectorAll('[data-confirm]').forEach(function(element) {
            const eventName = element.tagName === 'FORM' ? 'submit' : 'click';
            element.addEventListener(eventName, function(event) {
                const message = element.getAttribute('data-confirm') || 'Are you sure you want to continue?';
                if (!window.confirm(message)) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
        });
    }

    function searchTable(tableId, searchInput) {
        const table = document.getElementById(tableId);
        const input = typeof searchInput === 'string' ? document.getElementById(searchInput) : searchInput;
        if (!table || !input) {
            return;
        }

        const filter = input.value.toUpperCase();
        const rows = table.getElementsByTagName('tr');
        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            for (let j = 0; j < cells.length; j++) {
                const textValue = cells[j].textContent || cells[j].innerText;
                if (textValue.toUpperCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
            rows[i].style.display = found ? '' : 'none';
        }
    }

    function exportTableToCSV(tableId, filename) {
        const table = document.getElementById(tableId);
        if (!table) {
            return;
        }

        const rows = table.querySelectorAll('tr');
        const csv = [];
        rows.forEach(function(row) {
            const cols = row.querySelectorAll('td, th');
            const csvRow = [];
            cols.forEach(function(col) {
                const text = (col.textContent || col.innerText || '').replace(/"/g, '""').trim();
                csvRow.push('"' + text + '"');
            });
            csv.push(csvRow.join(','));
        });

        const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename || 'export.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function printTable(tableId) {
        const table = document.getElementById(tableId);
        if (!table) {
            return;
        }

        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            return;
        }

        printWindow.document.write(`
            <html>
                <head>
                    <title>Print Table</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 24px; font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f4f4f4; }
                    </style>
                </head>
                <body>
                    <h3>Print Report</h3>
                    ${table.outerHTML}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    function submitFormAjax(formId, successCallback) {
        const form = document.getElementById(formId);
        if (!form) {
            return;
        }

        const formData = new FormData(form);
        fetch(form.action, {
            method: form.method,
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (typeof successCallback === 'function') {
                    successCallback(data);
                }
            } else {
                alert(data.message || 'An error occurred');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while submitting the form');
        });
    }

    window.confirmDelete = function(message) {
        return window.confirm(message || 'Are you sure you want to delete this item?');
    };

    window.showLoadingOverlay = showLoadingOverlay;
    window.hideLoadingOverlay = hideLoadingOverlay;
    window.searchTable = searchTable;
    window.exportTableToCSV = exportTableToCSV;
    window.printTable = printTable;
    window.submitFormAjax = submitFormAjax;

    document.addEventListener('DOMContentLoaded', function() {
        initMotion();
        initTooltips();
        initSidebar();
        initAlerts();
        initForms();
        initTableEnhancements();
        initCustomerHint();
        initConfirmations();
    });
})();
</script>
</body>
</html>
