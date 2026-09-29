<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'mstore_sanitize_textarea_compat' ) ) {
    function mstore_sanitize_textarea_compat( $value ) {
        return function_exists( 'sanitize_textarea_field' )
            ? sanitize_textarea_field( $value )
            : sanitize_text_field( $value );
    }
}

include_once(plugin_dir_path(dirname(dirname(__FILE__))) . 'functions/index.php');
include_once(plugin_dir_path(dirname(dirname(__FILE__))) . 'controllers/helpers/firebase-message-helper.php');
include_once(plugin_dir_path(dirname(dirname(__FILE__))) . 'controllers/helpers/social-login-helper.php');
?>
<!--
    <div class="wrap">
        <div class="thanks">
            <p>Thank you for installing Mstore API plugins.</p>
            <?php
            $verified = isPurchaseCodeVerified();
            if (isset($verified) && $verified == "1") {
                ?>
                <p class="mstore-success-text">Your website have been license and all the API features are
                    unlocked. </p>
                <?php
            }
            ?>
        </div>
    </div> -->
<?php

// ==========================================================================
// Purchase code verification
// ==========================================================================
$verified = isPurchaseCodeVerified();
if (!isset($verified) || $verified === "" || $verified === false) {
    ?>
    <form action="" enctype="multipart/form-data" method="post" style="margin-bottom:50px">
        <!-- <?php
        if (isset($_POST['but_verify'])) {
            $verified = verifyPurchaseCode(sanitize_text_field($_POST['code']));

            if ($verified !== true) {
                ?>
                <p style="text-red-600"><?php echo esc_attr($verified); ?></p>
                <?php
            } else {
                ?>
                <p style="text-green-600">Your website have been license and all the API features are
                    unlocked. </p>
                <?php
            }
        }
        ?> -->

        <input type="text" class="mstore-input-class" placeholder="Enter Purchase Code" name="code">
        <div>
            <div class="mstore-info-title">What is purchase code?</div>
            <ul class="mstore-info-list">
                <li>A purchase code is a license identifier which is issued with the item once a purchase has been made
                    and included with your download.
                </li>
                <li>One purchase code is used for one website only.</li>
                <li>It's required to active to unlock the API use to connect with the app.</li>
            </ul>
            <div class="mstore-info-title">How can I get my purchase code? </div>
            <ul class="mstore-info-list">
                <li>Log into your Envato Market account.</li>
                <li>Hover the mouse over your username at the top of the screen.</li>
                <li>Click ‘Downloads’ from the drop-down menu.`</li>
                <li>Click ‘License certificate & purchase code’ (available as PDF or text file).</li>
            </ul>

<a href="https://help.market.envato.com/hc/en-us/articles/202822600-Where-Is-My-Purchase-Code-" class="mstore-help-link" target="_blank">https://help.market.envato.com/hc/en-us/articles/202822600-Where-Is-My-Purchase-Code-</a>
        </div>

        <button type="submit"  name='but_verify' class="mstore-button-class">Verify</button>
        <div class="mstore-section">
            <div class="mstore-action-row">
                <button type="submit" class="mstore-button-class" name="but_save_settings">Save</button>
            </div>
        </div>
    </form>
    <?php
}

if (isset($verified) && $verified == "1") {
    $theme = wp_get_theme(get_template());
    $template = strtolower($theme->get('Name'));
    $is_listeo = strpos($template, 'listeo') !== false;
    $settings_saved = false;
    $active_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'mstore-plugin';
    $active_page = in_array($active_page, array('mstore-plugin', 'mstore-notifications', 'mstore-social-login', 'mstore-tap-payments', 'mstore-firebase', 'mstore-apple-sign-in', 'mstore-fluxbuilder'), true)
        ? $active_page
        : 'mstore-plugin';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mstore_settings_nonce']) && wp_verify_nonce($_POST['mstore_settings_nonce'], 'save_mstore_settings')) {
        if (checkIsAdmin(get_current_user_id())) {
            if (isset($_POST['mstore_limit_product'])) {
                $limit = absint(wp_unslash($_POST['mstore_limit_product']));
                update_option("mstore_limit_product", $limit > 0 ? $limit : 10);
            }

            if (isset($_POST['mstore_new_order_title'])) {
                update_option("mstore_new_order_title", sanitize_text_field(wp_unslash($_POST['mstore_new_order_title'])));
                update_option("mstore_new_order_message", mstore_sanitize_textarea_compat(wp_unslash($_POST['mstore_new_order_message'] ?? '')));
                update_option("mstore_status_order_title", sanitize_text_field(wp_unslash($_POST['mstore_status_order_title'] ?? '')));
                update_option("mstore_status_order_message", mstore_sanitize_textarea_compat(wp_unslash($_POST['mstore_status_order_message'] ?? '')));
            }

            if (isset($_POST['mstore_delivery_order_title'])) {
                update_option("mstore_delivery_order_title", sanitize_text_field(wp_unslash($_POST['mstore_delivery_order_title'])));
                update_option("mstore_delivery_order_message", mstore_sanitize_textarea_compat(wp_unslash($_POST['mstore_delivery_order_message'] ?? '')));
                update_option("mstore_delivery_order_unassign_message", mstore_sanitize_textarea_compat(wp_unslash($_POST['mstore_delivery_order_unassign_message'] ?? '')));
            }

            if (isset($_POST['mstore_google_client_ids']) || isset($_POST['mstore_facebook_app_id'])) {
                // These values must live on the server because the mobile app and requests
                // are not trusted sources for deciding which OAuth app a token belongs to.
                $google_client_ids = MStoreSocialLoginHelper::parse_id_list(mstore_sanitize_textarea_compat(wp_unslash($_POST['mstore_google_client_ids'] ?? '')));
                update_option(MStoreSocialLoginHelper::OPTION_GOOGLE_CLIENT_IDS, implode("\n", $google_client_ids));
                update_option(MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_ID, sanitize_text_field(wp_unslash($_POST['mstore_facebook_app_id'] ?? '')));

                // Secrets: an empty field keeps the saved value, the checkbox removes it.
                $facebook_app_secret = sanitize_text_field(wp_unslash($_POST['mstore_facebook_app_secret'] ?? ''));
                if (!empty($_POST['mstore_facebook_app_secret_clear'])) {
                    delete_option(MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_SECRET);
                } elseif ($facebook_app_secret !== '') {
                    update_option(MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_SECRET, $facebook_app_secret);
                }
            }

            if (isset($_POST['mstore_tap_secret_key']) || isset($_POST['mstore_tap_secret_key_clear'])) {
                // Tap orders originate from a client-supplied transaction id, so the server
                // must verify the charge with Tap before marking an order as paid.
                $tap_secret_key = sanitize_text_field(wp_unslash($_POST['mstore_tap_secret_key'] ?? ''));
                if (!empty($_POST['mstore_tap_secret_key_clear'])) {
                    delete_option("mstore_tap_secret_key");
                } elseif ($tap_secret_key !== '') {
                    update_option("mstore_tap_secret_key", $tap_secret_key);
                }
            }

            if ($is_listeo && isset($_POST['mstore_new_booking_title'])) {
                update_option("mstore_new_booking_title", sanitize_text_field(wp_unslash($_POST['mstore_new_booking_title'])));
                update_option("mstore_new_booking_message", mstore_sanitize_textarea_compat(wp_unslash($_POST['mstore_new_booking_message'] ?? '')));
                update_option("mstore_status_booking_title", sanitize_text_field(wp_unslash($_POST['mstore_status_booking_title'] ?? '')));
                update_option("mstore_status_booking_message", mstore_sanitize_textarea_compat(wp_unslash($_POST['mstore_status_booking_message'] ?? '')));
            }

            $settings_saved = true;
        }
    }

    $limit = get_option("mstore_limit_product");
    if (!isset($limit) || $limit == false) {
        $limit = 10;
    }

    $newOrderTitle = get_option("mstore_new_order_title");
    if (!isset($newOrderTitle) || $newOrderTitle == false) {
        $newOrderTitle = "New Order";
    }
    $newOrderMsg = get_option("mstore_new_order_message");
    if (!isset($newOrderMsg) || $newOrderMsg == false) {
        $newOrderMsg = "Hi {{name}}, Congratulations, you have received a new order! ";
    }

    $statusOrderTitle = get_option("mstore_status_order_title");
    if (!isset($statusOrderTitle) || $statusOrderTitle == false) {
        $statusOrderTitle = "Order Status Changed";
    }
    $statusOrderMsg = get_option("mstore_status_order_message");
    if (!isset($statusOrderMsg) || $statusOrderMsg == false) {
        $statusOrderMsg = "Hi {{name}}, Your order: #{{orderId}} changed from {{prevStatus}} to {{nextStatus}}";
    }

    $deliveryOrderTitle = get_option("mstore_delivery_order_title");
    if (!isset($deliveryOrderTitle) || $deliveryOrderTitle == false) {
        $deliveryOrderTitle = "Order notification";
    }
    $deliveryOrderMsg = get_option("mstore_delivery_order_message");
    if (!isset($deliveryOrderMsg) || $deliveryOrderMsg == false) {
        $deliveryOrderMsg = "The order #{{orderId}} has been {{status}}";
    }
    $deliveryOrderUnassignMsg = get_option("mstore_delivery_order_unassign_message");
    if (!isset($deliveryOrderUnassignMsg) || $deliveryOrderUnassignMsg == false) {
        $deliveryOrderUnassignMsg = "The order #{{orderId}} has been unassigned from you";
    }

    $googleClientIds = (string) get_option(MStoreSocialLoginHelper::OPTION_GOOGLE_CLIENT_IDS, '');
    $facebookAppId = (string) get_option(MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_ID, '');
    $hasFacebookAppSecret = (string) get_option(MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_SECRET, '') !== '';
    $hasTapSecretKey = (string) get_option("mstore_tap_secret_key", '') !== '';

    $newBookingTitle = get_option("mstore_new_booking_title");
    if (!isset($newBookingTitle) || $newBookingTitle == false) {
        $newBookingTitle = "New Booking";
    }
    $newBookingMsg = get_option("mstore_new_booking_message");
    if (!isset($newBookingMsg) || $newBookingMsg == false) {
        $newBookingMsg = "Hi {{name}}, You have received a new booking for {{listing}}!";
    }

    $statusBookingTitle = get_option("mstore_status_booking_title");
    if (!isset($statusBookingTitle) || $statusBookingTitle == false) {
        $statusBookingTitle = "Booking Status Changed";
    }
    $statusBookingMsg = get_option("mstore_status_booking_message");
    if (!isset($statusBookingMsg) || $statusBookingMsg == false) {
        $statusBookingMsg = "Hi {{name}}, Your booking #{{bookingId}} for {{listing}} has been {{status}}";
    }

    ?>
    <?php if ($settings_saved) { ?>
        <div class="mstore-notice">Settings saved.</div>
    <?php } ?>

    <?php
    $mstore_admin_tabs = array(
        'mstore-plugin' => 'General',
        'mstore-notifications' => 'Notifications',
        'mstore-social-login' => 'Social Login',
        'mstore-tap-payments' => 'Tap Payments',
        'mstore-firebase' => 'Firebase',
        'mstore-apple-sign-in' => 'Apple Sign-In',
        'mstore-fluxbuilder' => 'FluxBuilder',
    );
    ?>
    <nav class="mstore-admin-tabs" aria-label="MStore API settings sections">
        <?php foreach ($mstore_admin_tabs as $tab_slug => $tab_label) { ?>
            <a class="mstore-admin-tab<?php echo $active_page === $tab_slug ? ' is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $tab_slug)); ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php } ?>
    </nav>

    <?php if (in_array($active_page, array('mstore-plugin', 'mstore-notifications', 'mstore-social-login', 'mstore-tap-payments'), true)) { ?>
    <form action="" method="post" class="mstore-settings-form" id="mstore-settings-form">
        <?php wp_nonce_field('save_mstore_settings', 'mstore_settings_nonce'); ?>
        <section class="mstore-section">
            <div class="mstore-stack">
                <?php if ($active_page === 'mstore-plugin') { ?>
                <div>
                    <p class="mstore-card-title">General</p>
                    <div class="mstore-stack">
                        <p class="mstore-helper-text">Control how many products each category caches for the home screen.</p>
                        <input type="number" name="mstore_limit_product" value="<?php echo esc_attr($limit); ?>" class="mstore-input-class">
                    </div>
                </div>
                <?php } ?>

                <?php if ($active_page === 'mstore-notifications') { ?>
                <div class="mstore-divider-group">
                    <p class="mstore-card-title">Order Notifications</p>
                    <div class="mstore-stack">
                        <div class="mstore-file-box">
                            <p class="mstore-card-title">New Order Message</p>
                            <div class="mstore-stack">
                                <input type="text" name="mstore_new_order_title" class="mstore-input-class" placeholder="Title" value="<?php echo esc_attr($newOrderTitle); ?>">
                                <textarea name="mstore_new_order_message" placeholder="Message" class="mstore-input-class"
                                          style="height: 120px"><?php echo esc_textarea($newOrderMsg); ?></textarea>
                            </div>
                        </div>

                        <div class="mstore-file-box">
                            <p class="mstore-card-title">Order Status Changed Message</p>
                            <div class="mstore-stack">
                                <input type="text" name="mstore_status_order_title" placeholder="Title" value="<?php echo esc_attr($statusOrderTitle); ?>"
                                       class="mstore-input-class">
                                <textarea name="mstore_status_order_message" placeholder="Message" class="mstore-input-class"
                                          style="height: 120px"><?php echo esc_textarea($statusOrderMsg); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mstore-divider-group">
                    <p class="mstore-card-title">Delivery Notifications</p>
                    <div class="mstore-stack">
                        <div class="mstore-file-box">
                            <p class="mstore-card-title">Delivery Boy Order Status Message</p>
                            <div class="mstore-stack">
                                <input type="text" name="mstore_delivery_order_title" placeholder="Title" value="<?php echo esc_attr($deliveryOrderTitle); ?>"
                                       class="mstore-input-class">
                                <p class="mstore-field-label">Order Status Message</p>
                                <textarea name="mstore_delivery_order_message" placeholder="Message" class="mstore-input-class"
                                          style="height: 120px"><?php echo esc_textarea($deliveryOrderMsg); ?></textarea>
                                <p class="mstore-field-label">Unassigned Message</p>
                                <textarea name="mstore_delivery_order_unassign_message" placeholder="Message when unassigned" class="mstore-input-class"
                                          style="height: 120px"><?php echo esc_textarea($deliveryOrderUnassignMsg); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($is_listeo) { ?>
                    <div class="mstore-divider-group">
                        <p class="mstore-card-title">Booking Notifications</p>
                        <p class="mstore-card-desc">These messages are used only when the active theme is Listeo.</p>
                        <div class="mstore-stack">
                            <div class="mstore-file-box">
                                <p class="mstore-card-title">New Booking Message</p>
                                <div class="mstore-stack">
                                    <input type="text" name="mstore_new_booking_title" class="mstore-input-class" placeholder="Title" value="<?php echo esc_attr($newBookingTitle); ?>">
                                    <textarea name="mstore_new_booking_message" placeholder="Message" class="mstore-input-class"
                                              style="height: 120px"><?php echo esc_textarea($newBookingMsg); ?></textarea>
                                </div>
                            </div>

                            <div class="mstore-file-box">
                                <p class="mstore-card-title">Booking Status Changed Message</p>
                                <div class="mstore-stack">
                                    <input type="text" name="mstore_status_booking_title" placeholder="Title" value="<?php echo esc_attr($statusBookingTitle); ?>"
                                           class="mstore-input-class">
                                    <textarea name="mstore_status_booking_message" placeholder="Message" class="mstore-input-class"
                                              style="height: 120px"><?php echo esc_textarea($statusBookingMsg); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>
                <?php } ?>

                <?php if ($active_page === 'mstore-social-login') { ?>
                <div class="border-t border-slate-200 pt-5">
                    <p class="mstore-card-title">Social Login Verification</p>
                    <p class="mb-3 text-sm leading-6 text-slate-600">Google and Facebook sign-in only accept tokens that were issued to the app IDs below. Leave a provider empty to disable that login method.</p>
                    <div class="mstore-stack">
                        <div class="mstore-file-box bg-white">
                            <p class="mstore-card-title">Google Client IDs</p>
                            <p class="mb-2 text-sm text-slate-600">One per line: the Web, Android and iOS OAuth client IDs used by your app (they end with .apps.googleusercontent.com).</p>
                            <textarea name="mstore_google_client_ids" class="mstore-input-class" style="height: 100px"
                                      placeholder="1234567890-abcdefg.apps.googleusercontent.com"><?php echo esc_textarea($googleClientIds); ?></textarea>
                        </div>

                        <div class="mstore-file-box bg-white">
                            <p class="mstore-card-title">Facebook App</p>
                            <input type="text" name="mstore_facebook_app_id" class="mstore-input-class" placeholder="Facebook App ID" value="<?php echo esc_attr($facebookAppId); ?>">
                            <p class="mt-3 mb-2 text-sm text-slate-600">App Secret (optional, enables Facebook's debug_token check).<?php echo $hasFacebookAppSecret ? ' A secret is saved; leave the field blank to keep it.' : ''; ?></p>
                            <input type="password" name="mstore_facebook_app_secret" class="mstore-input-class" placeholder="Facebook App Secret" value="" autocomplete="new-password">
                            <?php if ($hasFacebookAppSecret) { ?>
                                <label class="mt-2 block text-sm text-slate-600"><input type="checkbox" name="mstore_facebook_app_secret_clear" value="1"> Remove the saved App Secret</label>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <?php } ?>

                <?php if ($active_page === 'mstore-tap-payments') { ?>
                <div class="border-t border-slate-200 pt-5">
                    <p class="mstore-card-title">Tap Payments Verification</p>
                    <p class="mb-3 text-sm leading-6 text-slate-600">Orders paid through Tap are only marked as paid after the charge is confirmed with Tap using this secret key. Without a key, Tap orders stay unpaid until reviewed manually.</p>
                    <div class="mstore-file-box bg-white">
                        <p class="mb-2 text-sm text-slate-600"><?php echo $hasTapSecretKey ? 'A secret key is saved; leave the field blank to keep it.' : 'No secret key saved.'; ?></p>
                        <input type="password" name="mstore_tap_secret_key" class="mstore-input-class" placeholder="sk_live_..." value="" autocomplete="new-password">
                        <?php if ($hasTapSecretKey) { ?>
                            <label class="mt-2 block text-sm text-slate-600"><input type="checkbox" name="mstore_tap_secret_key_clear" value="1"> Remove the saved secret key</label>
                        <?php } ?>
                    </div>
                </div>
                <?php } ?>

                <div class="mstore-action-row">
                    <button type="submit" class="mstore-button-class" name="but_save_settings" value="1">Save Settings</button>
                    <p class="mstore-helper-text">Saves the settings on this tab.</p>
                </div>
            </div>
        </section>
    </form>
    <?php } ?>

    <?php if ($active_page === 'mstore-firebase') { ?>
        <section class="mstore-section">
            <div class="mstore-section-header">
                <div>
                    <h2 class="mstore-section-title">Firebase</h2>
                    <p class="mstore-section-desc">Upload the Firebase Admin SDK private key used to send push notifications when order statuses change.</p>
                    <p class="mstore-helper-text-sm">Firebase project -> Project Settings -> Service accounts -> Firebase Admin SDK -> Generate new private key</p>
                </div>
            </div>
            <form id="firebaseFileToUploadForm" action="" enctype="multipart/form-data" method="post">
            <?php wp_nonce_field( 'upload_firebase_file', 'upload_firebase_file_nonce' ); ?>
            <?php
            if(FirebaseMessageHelper::is_file_existed()){
                ?>
                <div class="mstore-file-box mstore-file-row">
                    <a  href="<?php echo esc_url(FirebaseMessageHelper::get_config_file_url()); ?>" target="_blank" class="mstore-file-link"><?php echo esc_html(FirebaseMessageHelper::get_file_name()); ?></a>
                    <button type="button" data-nonce="<?php echo esc_attr(wp_create_nonce('delete_config_firebase_file')); ?>" class="mstore-delete-file-button mstore-delete-firebase-file" aria-label="Delete Firebase file">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 11.793a1 1 0 1 1-1.414 1.414L10 11.414l-2.293 2.293a1 1 0 0 1-1.414-1.414L8.586 10 6.293 7.707a1 1 0 0 1 1.414-1.414L10 8.586l2.293-2.293a1 1 0 0 1 1.414 1.414L11.414 10l2.293 2.293Z"/>
                        </svg>
                    </button>
                </div>
                <?php
            }else{
                ?>
                <div class="mstore-file-box">
                    <p class="mstore-card-title">Upload Firebase key</p>
                    <input type="file" id="firebaseFileToUpload" accept=".json" name="firebaseFileToUpload" class="mstore-file-input-class"/>
                </div>

                <button type="submit" hidden="hidden" class="mstore_button" name='but_firebase_submit'>Upload</button>
                <?php
                    if (isset($_POST['but_firebase_submit']) && wp_verify_nonce($_POST['upload_firebase_file_nonce'], 'upload_firebase_file')) {
                        $errMsg = FirebaseMessageHelper::upload_file_by_admin($_FILES['firebaseFileToUpload']);
                        if($errMsg != null){
                            echo "<script type='text/javascript'>
                            alert('You need to upload Firebase private key file');
                            </script>";
                        }else{
                            echo "<script type='text/javascript'>
                            location.reload();
                            </script>";
                        }
                    }
                ?>
                <?php
            }
            ?>
            </form>
        </section>
    <?php } ?>

    <?php if ($active_page === 'mstore-apple-sign-in') { ?>
        <section class="mstore-section">
            <div class="mstore-section-header">
                <div>
                    <h2 class="mstore-section-title">Apple Sign-In</h2>
                    <p class="mstore-section-desc">Upload the Apple private key used by the app for Sign in with Apple.</p>
                </div>
            </div>
            <form id="appleFileToUploadForm" action="" enctype="multipart/form-data" method="post">
            <?php wp_nonce_field( 'upload_apple_file', 'upload_apple_file_nonce' ); ?>
            <?php
            if(FlutterAppleSignInUtils::is_file_existed()){
                ?>
                <div class="mstore-file-box mstore-file-row">
                    <a  href="<?php echo esc_url(FlutterAppleSignInUtils::get_config_file_url()); ?>" target="_blank" class="mstore-file-link"><?php echo esc_html(FlutterAppleSignInUtils::get_file_name()); ?></a>
                    <button type="button" data-nonce="<?php echo esc_attr(wp_create_nonce('delete_config_apple_file')); ?>" class="mstore-delete-file-button mstore-delete-apple-file" aria-label="Delete Apple Sign-In file">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 11.793a1 1 0 1 1-1.414 1.414L10 11.414l-2.293 2.293a1 1 0 0 1-1.414-1.414L8.586 10 6.293 7.707a1 1 0 0 1 1.414-1.414L10 8.586l2.293-2.293a1 1 0 0 1 1.414 1.414L11.414 10l2.293 2.293Z"/>
                        </svg>
                    </button>
                </div>
                <?php
            }else{
                ?>
                <div class="mstore-file-box">
                    <p class="mstore-card-title">Upload Apple key</p>
                    <input type="file" id="appleFileToUpload" accept=".p8" name="appleFileToUpload" class="mstore-file-input-class"/>
                </div>

                <button type="submit" hidden="hidden" class="mstore_button" name='but_apple_sign_in_submit'>Upload</button>
                <?php
                    if (isset($_POST['but_apple_sign_in_submit']) && wp_verify_nonce($_POST['upload_apple_file_nonce'], 'upload_apple_file')) {
                        $errMsg = FlutterAppleSignInUtils::upload_file_by_admin($_FILES['appleFileToUpload']);
                        if($errMsg != null){
                            echo "<script type='text/javascript'>
                            alert('You need to upload AuthKey_XXXX.p8 file');
                            </script>";
                        }else{
                            echo "<script type='text/javascript'>
                            location.reload();
                            </script>";
                        }
                    }
                ?>
                <?php
            }
            ?>
            </form>
        </section>
    <?php } ?>

    <?php if ($active_page === 'mstore-fluxbuilder') { ?>
        <section class="mstore-section">
            <div class="mstore-section-header">
                <div>
                    <h2 class="mstore-section-title">FluxBuilder</h2>
                    <p class="mstore-section-desc">Generate an upload token and manage the configuration JSON files used by FluxBuilder.</p>
                </div>
            </div>
            <div class="mstore-card">
                <p class="mstore-card-title">Upload token</p>
                <form action="" method="post">
                <?php wp_nonce_field( 'generate_token', 'generate_token_nonce' ); ?>
            <?php
                if (isset($_POST['but_generate']) && wp_verify_nonce($_POST['generate_token_nonce'], 'generate_token')) {
                    $user = wp_get_current_user();
                    $cookie = generateCookieByUserId($user->ID);
                    ?>
                    <div class="mstore-token-result">
                        <textarea class="mstore-token-output" style="height: 150px"><?php echo esc_attr($cookie) ?></textarea>
                    </div>
                    <?php
                }
                ?>
                    <button type="submit" class="mstore-button-class" name='but_generate'>Generate Token</button>
                </form>
            </div>

            <div class="mstore-card">
                <p class="mstore-card-title">Config JSON files</p>
                <p class="mstore-card-desc">Upload `config_xx.json` files to improve app performance and keep localized config data in sync.</p>
        <?php

        // ==========================================================================
        // Config JSON file management
        // ==========================================================================
        FlutterUtils::create_json_folder();
        // Existing config files
        $configs = FlutterUtils::get_all_json_files();
        if (!empty($configs)) {
            ?>
            <form action="" method="POST">
            <div class="mstore-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">
                                File
                            </th>
                            <th scope="col">
                                Download / Delete
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($configs as $file) {
                        ?>
                        <tr>
                            <th scope="row">
                            <?php echo esc_attr($file); ?>
                            </th>
                            <td>
                            <a href="<?php echo esc_url(FlutterUtils::get_json_file_url($file)); ?>" target="_blank" class="mstore-link-download">Download</a>
                                / <a data-id="<?php echo esc_attr(getLangCodeFromConfigFile($file)); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('delete_config_json_file')); ?>" class="mstore-link-delete mstore-delete-json-file">Delete</a>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
            </div>
            </form>
            <?php
        }
        ?>
                <form action="" enctype="multipart/form-data" method="post" class="mstore-config-upload-form">
                <?php wp_nonce_field( 'upload_file', 'upload_file_nonce' ); ?>
                <div class="mstore-file-box">
                    <p class="mstore-card-title">Upload new config file</p>
                    <input type="file" id="fileToUpload" accept=".json" name="fileToUpload" class="mstore-file-input-class" data-nonce="<?php echo esc_attr(wp_create_nonce('upload_file')); ?>"/>
                </div>
                <p style="font-size: 14px; color: #1B9D0D; margin-top:10px">
                <?php
                if (isset($_POST['but_submit'])) {
                    if(wp_verify_nonce($_POST['upload_file_nonce'], 'upload_file')){
                        if(isset($_FILES['fileToUpload']) && $_FILES['fileToUpload']['size'] > 0){
                            $errMsg = FlutterUtils::upload_file_by_admin($_FILES['fileToUpload']);
                            if($errMsg != null){
                                echo "<script type='text/javascript'>
                                alert('You need to upload config_xx.json file');
                                </script>";
                            }else{
                                echo "<script type='text/javascript'>
                                location.reload();
                                  </script>";
                            }
                        }
                    }else{
                        wp_send_json_error('No Permission',401);
                    }
                }
                ?>
                </p>

                <?php
                if (isset($_POST['but_deactive'])) {
                    $success = deactiveMStoreApi();
                    if (is_string($success)) {
                        echo "<script type='text/javascript'>
                            console.log(".json_encode(esc_attr($success)).");
                            alert(".json_encode(esc_attr($success)).")
                        </script>";
                    } else {
                        echo "<script type='text/javascript'>
              location.reload();
                </script>";
                    }
                }
                ?>

                <div class="mstore-action-row">
                    <button type="submit" class="mstore-button-class" name='but_submit'>Upload Config File</button>
                </div>
                <!-- <button type="submit" class="mstore-button-class bg-red-700" name='but_deactive'
                        onclick="return confirm('Are you sure to deactivate the license on this domain?');">Deactivate License
                </button> -->
                </form>
            </div>
        </section>
    <?php } ?>
    <?php
}
?>
