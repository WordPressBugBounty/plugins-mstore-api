<?php include_once(plugin_dir_path(dirname(__FILE__)) . 'functions/index.php'); ?>

<head>
    <script src="https://cdn.tailwindcss.com"></script>
    <style type="text/tailwindcss">
        .mstore-admin-shell {
            @apply mx-auto max-w-5xl p-6 md:p-8 bg-slate-50 rounded-3xl border border-slate-200 shadow-sm
        }
        .mstore-admin-header {
            @apply mb-8 pb-6 border-b border-slate-200
        }
        .mstore-admin-title {
            @apply text-3xl font-semibold tracking-tight text-slate-900
        }
        .mstore-admin-subtitle {
            @apply mt-2 max-w-3xl text-sm leading-6 text-slate-600
        }
        .mstore-section {
            @apply mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6
        }
        .mstore-section-header {
            @apply mb-5 flex items-start justify-between gap-4 border-b border-slate-100 pb-4
        }
        .mstore-section-title {
            @apply text-lg font-semibold text-slate-900
        }
        .mstore-section-desc {
            @apply mt-1 text-sm leading-6 text-slate-600
        }
        .mstore-stack {
            @apply space-y-4
        }
        .mstore-card {
            @apply rounded-2xl border border-slate-200 bg-slate-50 p-4
        }
        .mstore-card-title {
            @apply mb-3 text-sm font-semibold text-slate-900
        }
        .mstore-notice {
            @apply mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800
        }
        .mstore-file-box {
            @apply rounded-2xl border border-slate-200 bg-slate-50 p-4
        }
        .mstore-action-row {
            @apply mt-4 flex flex-wrap items-center gap-3
        }
        .mstore-token-output {
            @apply w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 font-mono text-sm text-slate-800
        }
        .mstore-table-wrap {
            @apply overflow-hidden rounded-2xl border border-slate-200
        }
        .mstore-input-class { 
            @apply border border-slate-300 bg-white text-slate-900 text-sm rounded-2xl focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-full sm:max-w-none px-3 py-3
        }
        input.mstore-input-class,
        textarea.mstore-input-class {
            appearance: none;
            -webkit-appearance: none;
            border-radius: 1rem !important;
            border-color: #cbd5e1 !important;
            background-color: #ffffff !important;
            box-shadow: none !important;
        }
        .mstore-button-class {
            @apply mt-5 inline-flex items-center justify-center px-5 py-2.5 text-sm font-semibold text-center text-white bg-emerald-600 rounded-xl hover:bg-emerald-700
        }
        .mstore-file-input-class {
            @apply block w-full text-sm text-slate-500
      file:mr-4 file:py-2.5 file:px-4
      file:rounded-xl file:border-0
      file:text-sm file:font-semibold
      file:bg-emerald-50 file:text-emerald-700
      hover:file:bg-emerald-100
        }
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
    <?php echo load_template(dirname(__FILE__) . '/admin/mstore-api-admin-dashboard.php'); ?>
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
