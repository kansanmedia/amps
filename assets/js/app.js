// assets/js/app.js - Kansan Manpower Client Interaction Scripts

document.addEventListener('DOMContentLoaded', () => {
    // 0. Initialize Global DD/MM/YYYY Datepickers (Flatpickr)
    if (typeof window.initGlobalDatepickers === 'function') {
        window.initGlobalDatepickers();
    }

    // 1. Sidebar Toggle (Mobile Drawer & Desktop Collapse)
    const sidebar = document.querySelector('.app-sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const overlay = document.querySelector('.sidebar-overlay');

    // Restore saved desktop sidebar state
    if (window.innerWidth >= 992 && localStorage.getItem('sidebar_collapsed') === 'true') {
        document.body.classList.add('sidebar-collapsed');
    }

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', () => {
            if (window.innerWidth >= 992) {
                // Desktop: collapse / hide sidebar
                document.body.classList.toggle('sidebar-collapsed');
                const isCollapsed = document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');
            } else {
                // Mobile: toggle drawer overlay
                sidebar.classList.toggle('show');
                if (overlay) overlay.classList.toggle('show');
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // 2. Generic Table Filter / Search
    document.querySelectorAll('[data-table-search]').forEach(input => {
        const targetTable = document.querySelector(input.dataset.tableSearch);
        if (!targetTable) return;

        input.addEventListener('keyup', (e) => {
            const term = e.target.value.toLowerCase().trim();
            const rows = targetTable.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    });

    // 3. Global Custom Confirmation Dialog Interceptors (replaces native confirm)
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        if (form.dataset.confirm && !form.dataset.confirmed) {
            e.preventDefault();
            e.stopPropagation();
            const msg = form.dataset.confirm;
            const title = form.dataset.confirmTitle || 'Confirm Action';
            const btnClass = form.dataset.confirmBtn || 'btn-danger';
            const confirmText = form.dataset.confirmText || 'Confirm';
            const ok = await window.customConfirm({
                title,
                message: msg,
                btnClass,
                confirmText
            });
            if (ok) {
                form.dataset.confirmed = 'true';
                form.submit();
            }
        }
    }, true);

    document.addEventListener('click', async (e) => {
        const trigger = e.target.closest('[data-confirm]');
        if (!trigger || trigger.tagName === 'FORM') return;

        const form = trigger.closest('form');
        // Button inside a form
        if (trigger.type === 'submit' || (form && form.contains(trigger) && !trigger.hasAttribute('href'))) {
            e.preventDefault();
            e.stopPropagation();
            const msg = trigger.dataset.confirm;
            const title = trigger.dataset.confirmTitle || 'Confirm Action';
            const btnClass = trigger.dataset.confirmBtn || (trigger.classList.contains('btn-danger') ? 'btn-danger' : 'btn-primary');
            const confirmText = trigger.dataset.confirmText || 'Confirm';
            const ok = await window.customConfirm({
                title,
                message: msg,
                btnClass,
                confirmText
            });
            if (ok) {
                if (trigger.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = trigger.name;
                    hidden.value = trigger.value;
                    form.appendChild(hidden);
                }
                form.submit();
            }
            return;
        }

        // Link with data-confirm
        if (trigger.tagName === 'A' && trigger.href && !trigger.href.startsWith('javascript:')) {
            e.preventDefault();
            e.stopPropagation();
            const msg = trigger.dataset.confirm;
            const title = trigger.dataset.confirmTitle || 'Confirm Action';
            const btnClass = trigger.dataset.confirmBtn || 'btn-danger';
            const ok = await window.customConfirm({
                title,
                message: msg,
                btnClass
            });
            if (ok) {
                window.location.href = trigger.href;
            }
        }
    }, true);

    initGlobalModals();

    // 4. Auto-hide flash alerts after 5 seconds
    const flashAlerts = document.querySelectorAll('.alert-dismissible');
    flashAlerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // 5. Shift Duration Calculator
    const startTimeInput = document.getElementById('shift_start_time');
    const endTimeInput = document.getElementById('shift_end_time');
    const isOvernightInput = document.getElementById('shift_is_overnight');
    const durationInput = document.getElementById('shift_duration_hours');

    function calculateShiftDuration() {
        if (!startTimeInput || !endTimeInput || !durationInput) return;
        const start = startTimeInput.value;
        const end = endTimeInput.value;
        if (!start || !end) return;

        const [sH, sM] = start.split(':').map(Number);
        const [eH, eM] = end.split(':').map(Number);

        let startMinutes = sH * 60 + sM;
        let endMinutes = eH * 60 + eM;

        let overnight = false;
        if (endMinutes <= startMinutes) {
            endMinutes += 24 * 60;
            overnight = true;
        }

        if (isOvernightInput) {
            isOvernightInput.checked = overnight;
        }

        const diffMinutes = endMinutes - startMinutes;
        const hours = (diffMinutes / 60).toFixed(2);
        durationInput.value = hours;
    }

    if (startTimeInput && endTimeInput) {
        startTimeInput.addEventListener('change', calculateShiftDuration);
        endTimeInput.addEventListener('change', calculateShiftDuration);
    }
});

// GPS Geolocation Helper for Attendance
function captureCurrentLocation(latInputId, lngInputId, statusMsgId) {
    const statusEl = document.getElementById(statusMsgId);
    if (!navigator.geolocation) {
        if (statusEl) statusEl.innerHTML = '<span class="text-danger">Geolocation is not supported by this browser.</span>';
        return;
    }

    if (statusEl) statusEl.innerHTML = '<span class="text-primary"><i class="bi bi-geo-alt"></i> Acquiring GPS coordinates...</span>';

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const accuracy = position.coords.accuracy;

            const latInput = document.getElementById(latInputId);
            const lngInput = document.getElementById(lngInputId);

            if (latInput) latInput.value = lat.toFixed(8);
            if (lngInput) lngInput.value = lng.toFixed(8);

            if (statusEl) {
                statusEl.innerHTML = `<span class="text-success"><i class="bi bi-check-circle"></i> GPS Location Acquired (Accuracy: ${Math.round(accuracy)}m)</span>`;
            }
        },
        (error) => {
            if (statusEl) {
                statusEl.innerHTML = `<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Location capture failed: ${error.message}</span>`;
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

// 7. Universal High-Resolution PDF Exporter
function downloadElementAsPDF(elementId, filename, options = {}) {
    const element = document.getElementById(elementId);
    if (!element) {
        console.warn('PDF Target element not found:', elementId);
        window.print();
        return;
    }

    if (typeof html2pdf === 'undefined') {
        console.warn('html2pdf library not loaded. Falling back to native print.');
        window.print();
        return;
    }

    const defaultOpt = {
        margin: [8, 8, 8, 8],
        filename: (filename ? filename.replace(/[^a-zA-Z0-9_-]/g, '_') : 'document') + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, letterRendering: true, logging: false },
        jsPDF: { unit: 'mm', format: 'a4', orientation: options.orientation || 'portrait' }
    };

    const opt = Object.assign(defaultOpt, options);

    // Button loading animation
    const activeBtn = document.activeElement;
    const origHtml = activeBtn && (activeBtn.tagName === 'BUTTON' || activeBtn.tagName === 'A') ? activeBtn.innerHTML : null;
    if (activeBtn && origHtml) {
        activeBtn.disabled = true;
        activeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Preparing PDF...';
    }

    html2pdf().set(opt).from(element).save().then(() => {
        if (activeBtn && origHtml) {
            activeBtn.disabled = false;
            activeBtn.innerHTML = origHtml;
        }
    }).catch(err => {
        console.error('PDF export failed:', err);
        if (activeBtn && origHtml) {
            activeBtn.disabled = false;
            activeBtn.innerHTML = origHtml;
        }
        window.print();
    });
}

// =========================================================================
// 8. GLOBAL CUSTOM ENTERPRISE MODAL DIALOGS & TOAST SYSTEM
// (Replaces native window.confirm, window.prompt, and window.alert)
// =========================================================================
let activeGlobalConfirmModal = null;
let activeGlobalPromptModal = null;
let activeGlobalToast = null;
let globalConfirmCallback = null;
let globalPromptCallback = null;

function initGlobalModals() {
    const confirmEl = document.getElementById('customConfirmModal');
    if (confirmEl && !activeGlobalConfirmModal) {
        activeGlobalConfirmModal = new bootstrap.Modal(confirmEl);
        confirmEl.addEventListener('hidden.bs.modal', () => {
            if (globalConfirmCallback) {
                const cb = globalConfirmCallback;
                globalConfirmCallback = null;
                cb(false);
            }
        });
    }

    const promptEl = document.getElementById('customPromptModal');
    if (promptEl && !activeGlobalPromptModal) {
        activeGlobalPromptModal = new bootstrap.Modal(promptEl);
        promptEl.addEventListener('hidden.bs.modal', () => {
            if (globalPromptCallback) {
                const cb = globalPromptCallback;
                globalPromptCallback = null;
                cb(null);
            }
        });
    }

    const toastEl = document.getElementById('globalAppToast');
    if (toastEl && !activeGlobalToast) {
        activeGlobalToast = new bootstrap.Toast(toastEl, { delay: 4000 });
    }
}

// Custom Confirm Modal Dialog (Replaces native window.confirm)
window.customConfirm = function({
    title = 'Confirm Action',
    message = 'Are you sure you want to proceed?',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    btnClass = 'btn-danger',
    icon = 'bi-exclamation-triangle-fill',
    iconBg = '#fee2e2',
    iconColor = '#dc2626'
} = {}) {
    initGlobalModals();
    return new Promise((resolve) => {
        const titleEl = document.getElementById('confirmModalTitle');
        const msgEl = document.getElementById('confirmModalMessage');
        const btnAction = document.getElementById('confirmModalBtnAction');
        const iconEl = document.getElementById('confirmModalIcon');
        const iconBgEl = document.getElementById('confirmModalIconBg');

        if (titleEl) titleEl.innerText = title;
        if (msgEl) msgEl.innerHTML = message;
        if (iconEl) iconEl.className = `bi ${icon}`;
        if (iconBgEl) {
            iconBgEl.style.backgroundColor = iconBg;
            iconBgEl.style.color = iconColor;
        }

        if (btnAction) {
            btnAction.className = `btn btn-sm ${btnClass} px-3.5 py-1.5 fw-bold`;
            btnAction.innerHTML = `<i class="bi bi-check2 me-1"></i> ${confirmText}`;
            btnAction.onclick = () => {
                if (globalConfirmCallback) {
                    const cb = globalConfirmCallback;
                    globalConfirmCallback = null;
                    cb(true);
                }
                if (activeGlobalConfirmModal) activeGlobalConfirmModal.hide();
            };
        }

        globalConfirmCallback = (res) => resolve(res);
        if (activeGlobalConfirmModal) {
            activeGlobalConfirmModal.show();
        } else {
            resolve(confirm(message));
        }
    });
};

// Custom Prompt Modal Dialog (Replaces native window.prompt)
window.customPrompt = function({
    title = 'Enter Details',
    label = 'Name',
    placeholder = '',
    help = '',
    confirmText = 'Save',
    btnClass = 'btn-danger',
    defaultValue = ''
} = {}) {
    initGlobalModals();
    return new Promise((resolve) => {
        const titleEl = document.getElementById('promptModalTitle');
        const labelEl = document.getElementById('promptModalLabel');
        const inputEl = document.getElementById('promptModalInput');
        const helpEl = document.getElementById('promptModalHelp');
        const btnSubmit = document.getElementById('promptModalSubmitBtn');

        if (titleEl) titleEl.innerText = title;
        if (labelEl) labelEl.innerText = label;
        if (inputEl) {
            inputEl.value = defaultValue;
            inputEl.placeholder = placeholder;
        }
        if (helpEl) {
            if (help) {
                helpEl.innerText = help;
                helpEl.classList.remove('d-none');
            } else {
                helpEl.classList.add('d-none');
            }
        }
        if (btnSubmit) {
            btnSubmit.className = `btn btn-sm ${btnClass} fw-bold px-3 d-flex align-items-center gap-1`;
            btnSubmit.innerHTML = `<i class="bi bi-check2 me-1"></i> ${confirmText}`;
        }

        globalPromptCallback = (val) => resolve(val);
        if (activeGlobalPromptModal) {
            activeGlobalPromptModal.show();
            setTimeout(() => { if (inputEl) inputEl.focus(); }, 350);
        } else {
            resolve(prompt(label, defaultValue));
        }
    });
};

window.handleGlobalPromptSubmit = function(e) {
    e.preventDefault();
    const inputEl = document.getElementById('promptModalInput');
    const val = inputEl ? inputEl.value.trim() : '';
    if (globalPromptCallback) {
        const cb = globalPromptCallback;
        globalPromptCallback = null;
        cb(val);
    }
    if (activeGlobalPromptModal) activeGlobalPromptModal.hide();
};

// Universal Toast Notification
window.showToast = function(msg, type = 'info') {
    // If matrixToast is present on deploy sheet, update and show it
    const matrixToastEl = document.getElementById('matrixToast');
    const matrixBodyEl = document.getElementById('matrixToastBody');
    if (matrixToastEl && matrixBodyEl) {
        matrixToastEl.className = `toast align-items-center text-white border-0 shadow-lg bg-${type}`;
        matrixBodyEl.innerHTML = msg;
        const mt = new bootstrap.Toast(matrixToastEl, { delay: 4000 });
        mt.show();
        return;
    }

    const toastEl = document.getElementById('globalAppToast');
    const toastMsg = document.getElementById('globalToastMsg');
    const toastIcon = document.getElementById('globalToastIcon');
    if (!toastEl || !toastMsg) return;

    const bgMap = {
        success: 'bg-success',
        danger: 'bg-danger',
        warning: 'bg-warning text-dark',
        info: 'bg-primary'
    };
    const iconMap = {
        success: 'bi-check-circle-fill',
        danger: 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill'
    };

    toastEl.className = `toast align-items-center border-0 shadow-lg ${bgMap[type] || 'bg-primary'} text-white`;
    if (type === 'warning') toastEl.classList.remove('text-white');
    if (toastIcon) toastIcon.className = `bi ${iconMap[type] || 'bi-info-circle-fill'} fs-5`;
    toastMsg.innerHTML = msg;

    if (!activeGlobalToast) {
        activeGlobalToast = new bootstrap.Toast(toastEl, { delay: 4000 });
    }
    activeGlobalToast.show();
};

// Replaces native browser alert popup with styled toast
window.alert = function(msg) {
    window.showToast(msg, 'warning');
};

// 4. Global DD/MM/YYYY Date Picker (Flatpickr) Integration
window.initGlobalDatepickers = function(container = document) {
    if (typeof flatpickr === 'undefined') return;

    container.querySelectorAll('input[type="date"], input.datepicker').forEach(input => {
        // Skip hidden or transparent matrix overlay inputs
        if (input.classList.contains('opacity-0') || input.style.opacity === '0' || input.dataset.noFlatpickr) {
            return;
        }
        if (input._flatpickr) return;

        const isSmall = input.classList.contains('form-control-sm');

        flatpickr(input, {
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "d/m/Y",
            allowInput: true,
            altInputClass: input.className + ' flatpickr-alt-input' + (isSmall ? ' form-control-sm' : ''),
            onChange: function(selectedDates, dateStr) {
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });
};

document.addEventListener('shown.bs.modal', (e) => {
    if (window.initGlobalDatepickers) {
        window.initGlobalDatepickers(e.target);
    }
});


