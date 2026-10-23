<?php
// templates/footer.php - Application Footer & Scripts
?>
    </main>

<?php if (empty($hideFooter)): ?>
    <footer class="py-3 px-4 bg-white border-top text-muted d-flex justify-content-between align-items-center flex-wrap" style="font-size: 0.8rem;">
        <div>
            &copy; <?= date('Y') ?> <strong><?= APP_NAME ?></strong>. All rights reserved.
        </div>
        <div class="d-flex gap-3">
            <span>Server Time (IST): <strong><?= date('H:i:s') ?></strong></span>
            <span class="text-secondary">|</span>
            <span>Version: <?= APP_VERSION ?></span>
        </div>
    </footer>
<?php endif; ?>
</div>
</div><!-- /app-wrapper -->

<!-- GLOBAL CUSTOM CONFIRM MODAL (Replaces native browser confirm dialogs) -->
<div class="modal fade" id="customConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-body p-4 text-center">
                <div class="mb-3 d-inline-flex align-items-center justify-content-center rounded-circle" id="confirmModalIconBg" style="width: 56px; height: 56px; background: #fee2e2; color: #dc2626; font-size: 1.75rem;">
                    <i class="bi bi-exclamation-triangle-fill" id="confirmModalIcon"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2" id="confirmModalTitle" style="font-size: 1.05rem;">Are you sure?</h5>
                <div class="text-muted small mb-0 px-2" id="confirmModalMessage" style="font-size: 0.86rem; line-height: 1.5;">Are you sure you want to proceed with this action?</div>
            </div>
            <div class="modal-footer bg-light border-0 py-2.5 px-3 justify-content-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary px-3.5 py-1.5 fw-semibold" data-bs-dismiss="modal" style="min-width: 90px; font-size: 0.82rem;">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger px-3.5 py-1.5 fw-bold" id="confirmModalBtnAction" style="min-width: 110px; font-size: 0.82rem;">
                    <i class="bi bi-check2 me-1"></i> Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- GLOBAL CUSTOM PROMPT MODAL (Replaces native browser prompt dialogs) -->
<div class="modal fade" id="customPromptModal" tabindex="-1" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-header bg-danger text-white py-2.5 px-3" id="promptModalHeader">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-plus-circle-fill fs-6" id="promptModalIcon"></i>
                    <h6 class="modal-title fw-bold mb-0" id="promptModalTitle" style="font-size: 0.90rem;">Enter Information</h6>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="customPromptForm" onsubmit="handleGlobalPromptSubmit(event)">
                <div class="modal-body p-3">
                    <label class="form-label text-muted small fw-bold text-uppercase mb-1" id="promptModalLabel" style="font-size: 0.70rem;">Input Value *</label>
                    <input type="text" id="promptModalInput" class="form-control form-control-sm py-1.5 px-2.5" placeholder="" required style="font-size: 0.84rem; border-radius: 6px;">
                    <div class="form-text small text-muted mt-1" id="promptModalHelp" style="font-size: 0.70rem;"></div>
                </div>
                <div class="modal-footer bg-light border-0 py-2 px-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-3 d-flex align-items-center gap-1" id="promptModalSubmitBtn" style="font-size: 0.80rem;">
                        <i class="bi bi-check2 me-1"></i> Submit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- GLOBAL TOAST NOTIFICATION CONTAINER (Replaces native browser alert popups) -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;" id="globalToastContainer">
    <div id="globalAppToast" class="toast align-items-center text-white border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-semibold py-2.5 px-3 d-flex align-items-center gap-2" id="globalToastBody" style="font-size: 0.84rem;">
                <i class="bi bi-info-circle-fill fs-5" id="globalToastIcon"></i>
                <span id="globalToastMsg">Action completed.</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="<?= BASE_URL ?>/assets/js/chart.umd.min.js"></script>
<!-- html2pdf Library for Instant Client-Side PDF Export -->
<script src="<?= BASE_URL ?>/assets/js/html2pdf.bundle.min.js"></script>
<!-- Flatpickr JS (Global DD/MM/YYYY Date Picker) -->
<script src="<?= BASE_URL ?>/assets/js/flatpickr.min.js"></script>
<!-- Kansan App JS -->
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= file_exists(ROOT_PATH . '/assets/js/app.js') ? filemtime(ROOT_PATH . '/assets/js/app.js') : time() ?>"></script>
</body>
</html>
