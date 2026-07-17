<?php
$page_title = 'Admin Settings';
include('../header.php');

if (!$okr_is_admin) {
    header('Location: /odb/okr/index.php');
    exit;
}

$cfg_result = mysqli_query($conn, "SELECT setting_value FROM okr_config WHERE setting_key = 'backdate_enabled'");
$backdate_enabled = false;
if ($cfg_result && ($cfg_row = mysqli_fetch_assoc($cfg_result))) {
    $backdate_enabled = ($cfg_row['setting_value'] === '1');
}
?>

<div class="row g-4">
    <div class="col-md-6">

        <div class="okr-card">
            <p class="mb-1" style="font-size:13px;font-weight:600;">Allow Backdated OKRs</p>
            <p class="mb-3 text-muted" style="font-size:12px;">When enabled, Start Date, End Date and Extended Date
                inputs across the OKR module allow past dates to be selected.</p>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="okr-backdate-toggle"
                        <?php echo $backdate_enabled ? 'checked' : ''; ?>
                        style="width:2.5em;height:1.25em;cursor:pointer;">
                </div>
                <span id="okr-backdate-status" style="font-size:12px;"></span>
            </div>
        </div>

    </div>
</div>

<script>
var OKR_BACKDATE_ENABLED = <?php echo $backdate_enabled ? 'true' : 'false'; ?>;
var OKR_ADMIN_BACKEND_URL = 'okr/admin/backend.php';
</script>
<?php
$page_js = 'okr/js/admin_settings.js';
include('../footer.php');
?>
