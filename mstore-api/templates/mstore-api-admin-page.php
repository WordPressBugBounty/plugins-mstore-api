<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include_once(plugin_dir_path(dirname(__FILE__)) . 'functions/index.php');
?>

<head>
    <style>
        .mstore-admin-shell { margin: 0 auto; max-width: 72rem; padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 24px; box-shadow: 0 1px 2px rgba(15,23,42,.06); }
        .mstore-admin-header { margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #e2e8f0; }
        .mstore-admin-title { margin: 0; font-size: 30px; line-height: 1.2; font-weight: 600; color: #0f172a; }
        .mstore-admin-subtitle { margin-top: 8px; max-width: 48rem; font-size: 14px; line-height: 1.7; color: #475569; }
        .mstore-section { margin-bottom: 24px; padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 20px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        .mstore-section-header { margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9; }
        .mstore-section-title { margin: 0; font-size: 18px; font-weight: 600; color: #0f172a; }
        .mstore-section-desc { margin-top: 4px; font-size: 14px; line-height: 1.7; color: #475569; }
        .mstore-helper-text { margin: 0; font-size: 14px; line-height: 1.7; color: #475569; }
        .mstore-helper-text-sm { margin: 8px 0 0; font-size: 12px; line-height: 1.6; color: #64748b; }
        .mstore-field-label { margin: 0; font-size: 14px; font-weight: 500; color: #334155; }
        .mstore-divider-group { padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .mstore-info-title { margin: 20px 0 8px; font-size: 20px; line-height: 1.4; font-weight: 600; color: #111827; }
        .mstore-info-list { margin: 0 0 16px 20px; padding: 0; list-style: disc; color: #6b7280; }
        .mstore-info-list li { margin-top: 8px; font-size: 14px; line-height: 1.6; }
        .mstore-success-text { color: #16a34a; }
        .mstore-help-link { color: #059669; font-weight: 500; }
        .mstore-help-link:hover { text-decoration: underline; }
        .mstore-admin-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #e2e8f0; }
        .mstore-admin-tab { display: inline-flex; align-items: center; min-height: 36px; padding: 0 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #334155; font-size: 14px; font-weight: 500; text-decoration: none; }
        .mstore-admin-tab:hover { border-color: #94a3b8; color: #0f172a; }
        .mstore-admin-tab.is-active { border-color: #059669; background: #059669; color: #fff; }
        .mstore-stack { display: grid; gap: 16px; }
        .mstore-card, .mstore-file-box { padding: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; }
        .mstore-card + .mstore-card, .mstore-config-upload-form { margin-top: 16px; }
        .mstore-card-title { margin: 0 0 12px; font-size: 14px; font-weight: 600; color: #0f172a; }
        .mstore-card-desc { margin: 0 0 16px; font-size: 14px; line-height: 1.7; color: #475569; }
        .mstore-notice { margin-bottom: 24px; padding: 12px 16px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 18px; color: #065f46; font-size: 14px; font-weight: 500; }
        .mstore-action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 16px; }
        .mstore-action-row .mstore-button-class { margin-top: 0; }
        .mstore-token-result { margin-bottom: 12px; }
        .mstore-token-output { width: 100%; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; font: 14px/1.5 monospace; color: #1e293b; }
        .mstore-table-wrap { overflow: hidden; border: 1px solid #e2e8f0; border-radius: 18px; }
        .mstore-table-wrap table { width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; color: #475569; }
        .mstore-table-wrap th, .mstore-table-wrap td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        .mstore-table-wrap tr:last-child th, .mstore-table-wrap tr:last-child td { border-bottom: 0; }
        .mstore-table-wrap thead th { background: #fff; color: #334155; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .mstore-table-wrap tbody th { color: #0f172a; font-weight: 600; }
        .mstore-input-class { width: 100%; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 16px; background: #fff; color: #0f172a; font-size: 14px; box-sizing: border-box; }
        .mstore-button-class { display: inline-flex; align-items: center; justify-content: center; margin-top: 20px; padding: 10px 20px; border: 0; border-radius: 14px; background: #059669; color: #fff; font-size: 14px; font-weight: 600; text-align: center; cursor: pointer; }
        .mstore-button-class:hover { background: #047857; }
        .mstore-file-input-class { display: block; width: 100%; font-size: 14px; color: #64748b; }
        .mstore-file-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .mstore-file-link { margin-right: 8px; font-size: 14px; font-weight: 500; color: #334155; overflow-wrap: anywhere; }
        .mstore-link-download { color: #047857; }
        .mstore-link-delete { color: #991b1b; cursor: pointer; }
        .mstore-delete-file-button { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 36px; height: 36px; padding: 0; border: 1px solid #fecaca; border-radius: 999px; background: #fef2f2; color: #dc2626; cursor: pointer; }
        .mstore-delete-file-button:hover { background: #fee2e2; color: #b91c1c; }
        .mstore-delete-file-button svg { display: block; width: 18px; height: 18px; }
    </style>
</head>
<body>
<?php
	wp_enqueue_script('my_script', plugins_url('assets/js/mstore-inspireui.js', MSTORE_PLUGIN_FILE), array('jquery'), '1.0.0', true);
            wp_localize_script('my_script', 'MyAjax', array('ajaxurl' => admin_url('admin-ajax.php')));
	?>
<div class="mstore-admin-shell">
    <div class="mstore-admin-header">
        <h4 class="mstore-admin-title">MStore API Settings</h4>
        <p class="mstore-admin-subtitle">Manage product caching, push notification content, authentication files, and FluxBuilder config uploads from one place.</p>
    </div>
    <?php include dirname(__FILE__) . '/admin/mstore-api-admin-dashboard.php'; ?>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    var settingsForm = document.getElementById("mstore-settings-form");

    if (!settingsForm) {
        return;
    }

    var getSnapshot = function () {
        var formData = new FormData(settingsForm);
        formData.delete("mstore_settings_nonce");
        return JSON.stringify(Array.from(formData.entries()));
    };

    var initialSnapshot = getSnapshot();

    settingsForm.addEventListener("submit", function () {
        initialSnapshot = getSnapshot();
    });

    window.addEventListener("beforeunload", function (event) {
        if (getSnapshot() === initialSnapshot) {
            return;
        }

        event.preventDefault();
        event.returnValue = "";
    });
});
</script>

<?php
do_action('admin_print_footer_scripts');
wp_print_footer_scripts();
?>

</body>
</html>
