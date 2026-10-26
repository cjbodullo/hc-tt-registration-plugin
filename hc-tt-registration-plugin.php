<?php
/**
 * Plugin Name: Tommee Tippee Natural Start Healthcare Centres Registration
 * Description: Tommee Tippee Natural Start healthcare centres registration form. Shortcode [hc_tt_registration].
 * Version: 1.0.0
 * Author: Samplits
 * Text Domain: hc-tt-registration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HCTT_VERSION', '1.0.0');
define('HCTT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HCTT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('HCTT_ADMIN_PAGE_SLUG', 'hctt-submissions');
/** Posted `action` / nonce scheme for public form (`admin-post.php`). */
define('HCTT_POST_ACTION', 'hctt_register');
/** Default timezone used when saving `created_at`. */
define('HCTT_CREATED_AT_TIMEZONE', 'America/New_York');

require_once HCTT_PLUGIN_PATH . 'includes/class-hctt-submissions-list-table.php';

/**
 * @return string
 */
function hctt_get_submissions_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'hc_tt_registration_submissions';
}

/**
 * Build a WHERE fragment and prepare args for submission list / export search.
 *
 * @return array{sql:string,args:array<int,string>}
 */
function hctt_submissions_search_where_clause($wpdb, $search)
{
    $search = trim((string) $search);
    if ($search === '') {
        return ['sql' => '', 'args' => []];
    }

    $like = '%' . $wpdb->esc_like($search) . '%';
    $fields = [
        'healthcare_id',
        'organization_name',
        'contact_first_name',
        'contact_last_name',
        'email',
        'city',
        'province',
        'phone',
        'postal_code',
        'category',
        'category_other',
        'job_title',
        'department',
        'address_1',
        'comments',
    ];
    $parts = [];
    $args = [];
    foreach ($fields as $field) {
        $parts[] = '`' . $field . '` LIKE %s';
        $args[] = $like;
    }

    return [
        'sql' => '(' . implode(' OR ', $parts) . ')',
        'args' => $args,
    ];
}

/**
 * CSV column order for exports (matches submissions table).
 *
 * @return string[]
 */
function hctt_submissions_csv_columns()
{
    return [
        'no',
        'healthcare_id',
        'organization_name',
        'contact_first_name',
        'contact_last_name',
        'email',
        'job_title',
        'department',
        'phone',
        'extension',
        'address_1',
        'suite',
        'city',
        'province',
        'postal_code',
        'category',
        'category_other',
        'patients_type',
        'weekly_expecting_parents',
        'receive_patient_samples',
        'confirmed_distribution',
        'comments',
        'created_at',
    ];
}

/**
 * Attempt to fix common UTF-8/Windows-1252 mojibake (e.g. "Ã‰" -> "É", "â€™" -> "’").
 *
 * If conversion doesn't produce valid UTF-8, the original string is returned.
 *
 * @param string $value
 * @return string
 */
function hctt_csv_normalize_value($value)
{
    $value = (string) $value;
    if ($value === '') {
        return $value;
    }

    // Normalize common punctuation to ASCII to avoid viewer-specific encoding issues.
    $value = str_replace(
        ["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}"],
        ["'", "'", '"', '"', '-', '-', '...'],
        $value
    );

    // Convert accented characters to closest ASCII equivalent for maximum compatibility.
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            return $ascii;
        }
    }

    return $value;
}

/**
 * Timezone used for `created_at` persistence and formatting.
 *
 * Filter: `hctt_created_at_timezone` (string $tzId).
 *
 * @return DateTimeZone
 */
function hctt_created_at_timezone()
{
    $tzId = (string) apply_filters('hctt_created_at_timezone', HCTT_CREATED_AT_TIMEZONE);
    if ($tzId === '') {
        $tzId = HCTT_CREATED_AT_TIMEZONE;
    }

    try {
        return new DateTimeZone($tzId);
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

/**
 * @return string MySQL datetime in ET (or filtered timezone).
 */
function hctt_created_at_mysql()
{
    $tz = hctt_created_at_timezone();
    try {
        $dt = new DateTimeImmutable('now', $tz);

        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return gmdate('Y-m-d H:i:s');
    }
}

/**
 * Convert a stored MySQL datetime (assumed ET / filtered tz) to timestamp.
 *
 * @param string $mysqlDatetime "Y-m-d H:i:s"
 * @return int|null
 */
function hctt_created_at_to_timestamp($mysqlDatetime)
{
    $mysqlDatetime = trim((string) $mysqlDatetime);
    if ($mysqlDatetime === '') {
        return null;
    }

    $tz = hctt_created_at_timezone();
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime, $tz);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->getTimestamp();
    }

    $ts = strtotime($mysqlDatetime);

    return $ts ? (int) $ts : null;
}

function hctt_maybe_install_table()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = hctt_get_submissions_table_name();
    $charsetCollate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        healthcare_id VARCHAR(64) NULL DEFAULT NULL,
        organization_name VARCHAR(255) NULL DEFAULT NULL,
        contact_first_name VARCHAR(250) NULL DEFAULT NULL,
        contact_last_name VARCHAR(120) NULL DEFAULT NULL,
        email VARCHAR(250) NULL DEFAULT NULL,
        job_title VARCHAR(250) NULL DEFAULT NULL,
        department VARCHAR(250) NULL DEFAULT NULL,
        phone VARCHAR(40) NULL DEFAULT NULL,
        extension VARCHAR(250) NULL DEFAULT NULL,
        address_1 VARCHAR(255) NULL DEFAULT NULL,
        suite VARCHAR(120) NULL DEFAULT NULL,
        city VARCHAR(120) NULL DEFAULT NULL,
        province VARCHAR(10) NULL DEFAULT NULL,
        postal_code VARCHAR(25) NULL DEFAULT NULL,
        category VARCHAR(120) NULL DEFAULT NULL,
        category_other VARCHAR(255) NULL DEFAULT NULL,
        patients_type VARCHAR(40) NULL DEFAULT NULL,
        weekly_expecting_parents VARCHAR(120) NULL DEFAULT NULL,
        receive_patient_samples VARCHAR(3) NULL DEFAULT NULL,
        confirmed_distribution TINYINT(1) NOT NULL DEFAULT 0,
        comments TEXT NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_email (email),
        KEY idx_created_at (created_at)
    ) {$charsetCollate};";

    dbDelta($sql);
}

add_action('init', static function () {
    hctt_maybe_install_table();
});

function hctt_get_canadian_provinces()
{
    return [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];
}

/**
 * @return string[]
 */
function hctt_get_registration_categories()
{
    return [
        'Community Centre',
        "Doctor's Office",
        'Doula Services',
        'Family Health Team',
        'Hospital',
        'Medical Centre',
        'Midwifery Practice',
        'OBGYN Practice',
        'Pediatric Practice',
        'Pregnancy Centre',
        'Public Health Unit',
        'Trade Show',
        'Other',
    ];
}

/**
 * @return string[]
 */
function hctt_get_patients_types()
{
    return ['Prenatal', 'Postnatal', 'Both'];
}

/**
 * Validate submission fields (DB column keys). Used by public form and admin save.
 *
 * @param array<string,mixed> $input Raw values (e.g. from POST after wp_unslash).
 * @param bool $require_confirm Whether confirmed_distribution must be accepted.
 * @return array<string,mixed>|WP_Error Row for insert/update (nulls for empty optional strings).
 */
function hctt_validate_submission_row(array $input, $require_confirm = true)
{
    $healthcareId = sanitize_text_field((string) ($input['healthcare_id'] ?? ''));
    $healthcareId = strtoupper(trim($healthcareId));
    if ($healthcareId === 'HCP') {
        // Treat bare prefix as "not set" unless they add digits/characters.
        $healthcareId = '';
    }

    $organization = sanitize_text_field($input['organization_name'] ?? '');
    $first = sanitize_text_field($input['contact_first_name'] ?? '');
    $last = sanitize_text_field($input['contact_last_name'] ?? '');
    $email = sanitize_email($input['email'] ?? '');
    $job = sanitize_text_field($input['job_title'] ?? '');
    $department = sanitize_text_field($input['department'] ?? '');
    $phone = sanitize_text_field($input['phone'] ?? '');
    $extension = sanitize_text_field($input['extension'] ?? '');
    $address1 = sanitize_text_field($input['address_1'] ?? '');
    $suite = sanitize_text_field($input['suite'] ?? '');
    $city = sanitize_text_field($input['city'] ?? '');
    $province = sanitize_text_field($input['province'] ?? '');
    $postalRaw = strtoupper(preg_replace('/\s+/', ' ', trim((string) ($input['postal_code'] ?? ''))));
    $postal = $postalRaw;
    $category = sanitize_text_field($input['category'] ?? '');
    $categoryOther = sanitize_text_field((string) ($input['category_other'] ?? ''));
    $patientsType = sanitize_text_field($input['patients_type'] ?? '');
    $weeklyExpecting = preg_replace('/\D/', '', (string) ($input['weekly_expecting_parents'] ?? ''));
    $receivePatientSamples = sanitize_text_field($input['receive_patient_samples'] ?? '');
    $confirmRaw = $input['confirmed_distribution'] ?? '';
    $confirmDistribution = $confirmRaw === 1 || $confirmRaw === '1' || $confirmRaw === true;
    $comments = sanitize_textarea_field((string) ($input['comments'] ?? ''));

    $allowedProvinces = array_keys(hctt_get_canadian_provinces());
    if ($province !== '' && !in_array($province, $allowedProvinces, true)) {
        $province = '';
    }

    if ($category !== '' && !in_array($category, hctt_get_registration_categories(), true)) {
        $category = '';
    }

    if ($category !== 'Other') {
        $categoryOther = '';
    }

    if ($patientsType !== '' && !in_array($patientsType, hctt_get_patients_types(), true)) {
        $patientsType = '';
    }

    if (
        $organization === '' || $first === '' || $last === '' || !is_email($email) ||
        $job === '' || $department === '' || $phone === '' || $address1 === '' ||
        $city === '' || $province === '' || $postal === '' || $category === '' || $patientsType === '' ||
        $weeklyExpecting === '' || !in_array($receivePatientSamples, ['Yes', 'No'], true)
    ) {
        return new WP_Error('hctt_required', __('Please complete all required fields.', 'hc-tt-registration'));
    }

    if ($extension !== '' && !ctype_digit($extension)) {
        return new WP_Error('hctt_extension', __('Extension must contain digits only.', 'hc-tt-registration'));
    }

    if (!preg_match('/^[A-Z]\d[A-Z] \d[A-Z]\d$/', $postal)) {
        return new WP_Error('hctt_postal', __('Please enter a valid postal code (format A1A 1A1).', 'hc-tt-registration'));
    }

    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) !== 10) {
        return new WP_Error('hctt_phone', __('Please enter a valid 10-digit phone number.', 'hc-tt-registration'));
    }

    if ($require_confirm && !$confirmDistribution) {
        return new WP_Error('hctt_required', __('Please confirm the distribution agreement.', 'hc-tt-registration'));
    }

    if ($category === 'Other' && trim($categoryOther) === '') {
        return new WP_Error('hctt_required', __('Please enter the services available.', 'hc-tt-registration'));
    }

    return [
        'healthcare_id' => $healthcareId === '' ? null : $healthcareId,
        'organization_name' => $organization,
        'contact_first_name' => $first,
        'contact_last_name' => $last,
        'email' => $email,
        'job_title' => $job,
        'department' => $department,
        'phone' => $phone,
        'extension' => $extension === '' ? null : $extension,
        'address_1' => $address1,
        'suite' => $suite === '' ? null : $suite,
        'city' => $city,
        'province' => $province,
        'postal_code' => $postal,
        'category' => $category,
        'category_other' => $categoryOther === '' ? null : $categoryOther,
        'patients_type' => $patientsType,
        'weekly_expecting_parents' => $weeklyExpecting,
        'receive_patient_samples' => $receivePatientSamples,
        'confirmed_distribution' => $confirmDistribution ? 1 : 0,
        'comments' => $comments === '' ? null : $comments,
    ];
}

function hctt_get_redirect_url()
{
    $redirectUrl = '';

    if (is_singular()) {
        $postId = get_queried_object_id();
        if ($postId) {
            $redirectUrl = get_permalink($postId);
        }
    }

    if ($redirectUrl === '') {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $redirectUrl = home_url($requestUri);
    }

    return remove_query_arg(['hctt_status', 'hctt_message'], $redirectUrl);
}

function hctt_get_feedback()
{
    return [
        'status' => isset($_GET['hctt_status']) ? sanitize_key(wp_unslash($_GET['hctt_status'])) : '',
        'message' => isset($_GET['hctt_message']) ? sanitize_text_field(wp_unslash((string) $_GET['hctt_message'])) : '',
    ];
}

function hctt_build_feedback_url($status, $message = '', $redirectUrl = '')
{
    if ($redirectUrl === '') {
        $redirectUrl = hctt_get_redirect_url();
    }

    $args = ['hctt_status' => $status];
    if ($message !== '') {
        $args['hctt_message'] = $message;
    }

    return add_query_arg($args, $redirectUrl);
}

/**
 * Shortcode: [hc_tt_registration]
 *
 * Attributes:
 * - thank_you_url — optional custom URL after success
 * - contact_email — shown on thank-you screen
 * - notification_email — optional; if set, submission summary is emailed there. Empty = no email.
 */
function hctt_render_form_shortcode($atts, $content = '', $tag = '')
{
    $shortcodeTag = $tag !== '' ? $tag : 'hc_tt_registration';
    $atts = shortcode_atts(
        [
            'thank_you_url' => '',
            'contact_email' => 'info@babybrandsgiftclub.com',
            'notification_email' => '',
        ],
        $atts,
        $shortcodeTag
    );

    $thankYouUrl = esc_url(trim((string) $atts['thank_you_url']));
    $contactEmail = sanitize_email((string) $atts['contact_email']);
    if ($contactEmail === '' || !is_email($contactEmail)) {
        $contactEmail = 'info@babybrandsgiftclub.com';
    }
    $contactEmail = (string) apply_filters('hctt_contact_email', $contactEmail, $shortcodeTag);

    $notifyEmail = sanitize_email((string) $atts['notification_email']);

    $feedback = hctt_get_feedback();
    $redirectUrl = hctt_get_redirect_url();
    $successRedirectUrl = $thankYouUrl !== '' ? $thankYouUrl : $redirectUrl;
    $successRedirectUrl = wp_validate_redirect($successRedirectUrl, $redirectUrl);
    $successRedirectUrl = remove_query_arg(['hctt_status', 'hctt_message'], $successRedirectUrl);
    $formPageUrl = remove_query_arg(['hctt_status', 'hctt_message'], $redirectUrl);

    wp_enqueue_style(
        'hc-tt-registration',
        HCTT_PLUGIN_URL . 'assets/css/style.css',
        [],
        HCTT_VERSION
    );

    $isSuccess = $feedback['status'] === 'success';
    $errorMessage = $feedback['status'] === 'error' ? $feedback['message'] : '';

    $provinces = hctt_get_canadian_provinces();

    $assets = [
        'step1' => HCTT_PLUGIN_URL .'assets/images/step1.webp',
        'samplits_logo' => HCTT_PLUGIN_URL . 'assets/images/babybrands-logo.png',
        'thankyou_banner' => HCTT_PLUGIN_URL . 'assets/images/banner-thankyou.webp',
    ];

    ob_start();
    ?>
    <main class="hctt-shell">
        <div class="hctt-container">
            <?php if ($isSuccess) : ?>
                <div class="hctt-thankyou hctt-thankyou-page">
                    <div class="hctt-thankyou-card">
                        <div class="hctt-thankyou-badge" style="text-transform: uppercase;"><?php esc_html_e('Tommee Tippee Natural Start Healthcare Centres Registration Received', 'hc-tt-registration'); ?></div>
                        <h1 class="hctt-thankyou-card-title"><?php esc_html_e('Thank You!', 'hc-tt-registration'); ?></h1>
                        <p class="hctt-thankyou-card-lead"><?php esc_html_e('We have received your form.', 'hc-tt-registration'); ?></p>
                        <div class="hctt-thankyou-banner-wrap text-center">
                            <img class="hctt-thankyou-banner" src="<?php echo esc_url($assets['thankyou_banner']); ?>" alt="">
                        </div>
                        <p class="hctt-thankyou-card-text">
                            <?php esc_html_e('We will review your submission. If you have any questions, please contact', 'hc-tt-registration'); ?>
                            <a href="mailto:<?php echo esc_attr($contactEmail); ?>"><?php echo esc_html($contactEmail); ?></a>
                        </p>
                        <a class="btn btn-primary hctt-thankyou-cta" href="<?php echo esc_url($formPageUrl); ?>"><?php esc_html_e('Submit another form', 'hc-tt-registration'); ?></a>
                        <div class="hctt-thankyou-card-footer">
                            <div class="hctt-powered-by"><?php esc_html_e('Powered by Samplits', 'hc-tt-registration'); ?></div>
                            <img src="<?php echo esc_url($assets['samplits_logo']); ?>" alt="<?php esc_attr_e('Samplits Logo', 'hc-tt-registration'); ?>">
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    try {
                        var u = new URL(window.location.href);
                        if (!u.searchParams.has('hctt_status')) return;
                        u.searchParams.delete('hctt_status');
                        u.searchParams.delete('hctt_message');
                        var qs = u.searchParams.toString();
                        window.history.replaceState(null, '', u.pathname + (qs ? '?' + qs : '') + u.hash);
                    } catch (e) {}
                })();
                </script>
            <?php elseif ($errorMessage !== '') : ?>
                <div class="alert alert-danger text-center mb-4">
                    <?php echo esc_html($errorMessage); ?>
                </div>
                <script>
                (function () {
                    try {
                        var u = new URL(window.location.href);
                        if (!u.searchParams.has('hctt_status') && !u.searchParams.has('hctt_message')) return;
                        u.searchParams.delete('hctt_status');
                        u.searchParams.delete('hctt_message');
                        var qs = u.searchParams.toString();
                        window.history.replaceState(null, '', u.pathname + (qs ? '?' + qs : '') + u.hash);
                    } catch (e) {}
                })();
                </script>
            <?php endif; ?>

            <?php if (!$isSuccess) : ?>
                <div class="hctt-form-container">
                    <div class="hctt-header p-3 pb-0">
                        <img class="hctt-header-image" src="<?php echo esc_url($assets['step1']); ?>" alt="">
                    </div>

                    <div class="hctt-topbar">
                        <div class="hctt-title">
                            <span><?php esc_html_e('Tommee Tippee Natural Start', 'hc-tt-registration'); ?></span>
                            <span><?php esc_html_e('Healthcare Centres Registration', 'hc-tt-registration'); ?></span>
                        </div>
                    </div>

                    <form class="hctt-form px-3 pb-3 mt-3" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" novalidate>
                        <input type="hidden" name="action" value="<?php echo esc_attr(HCTT_POST_ACTION); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirectUrl); ?>">
                        <input type="hidden" name="hctt_success_redirect" value="<?php echo esc_url($successRedirectUrl); ?>">
                        <input type="hidden" name="hctt_notification_email" value="<?php echo esc_attr($notifyEmail); ?>">
                        <?php wp_nonce_field(HCTT_POST_ACTION, 'hctt_nonce'); ?>

                        <h3 class="h6 font-weight-bold mb-3 hctt-section-title"><?php esc_html_e('General Information', 'hc-tt-registration'); ?></h3>

                        <div class="form-group">
                            <label class="font-weight-bold d-block" for="hctt-name">*<?php esc_html_e('Name of the organization', 'hc-tt-registration'); ?></label>
                            <input type="text" class="form-control" id="hctt-name" name="name" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-firstName">*<?php esc_html_e('Contact First Name', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-firstName" name="firstName" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-lastName">*<?php esc_html_e('Contact Last Name', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-lastName" name="lastName" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-email">*<?php esc_html_e('Email Address', 'hc-tt-registration'); ?></label>
                                    <input type="email" class="form-control" id="hctt-email" name="email" required>
                                    <div class="invalid-feedback" id="hctt-emailError" style="display:none;"><?php esc_html_e('Invalid email address', 'hc-tt-registration'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-job">*<?php esc_html_e('Job Title', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-job" name="job" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold d-block" for="hctt-department">*<?php esc_html_e('Department/Unit', 'hc-tt-registration'); ?></label>
                            <input type="text" class="form-control" id="hctt-department" name="department" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-phone">*<?php esc_html_e('Telephone Number', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-phone" name="phone" required>
                                    <div class="invalid-feedback" id="hctt-phoneError" style="display:none;"><?php esc_html_e('Please enter a valid phone number', 'hc-tt-registration'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-extension"><?php esc_html_e('Extension', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-extension" name="extension">
                                    <div class="invalid-feedback" id="hctt-extensionError" style="display:none;"><?php esc_html_e('Please enter numbers only', 'hc-tt-registration'); ?></div>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <h3 class="h6 font-weight-bold mb-3 hctt-section-title"><?php esc_html_e('Shipping Information', 'hc-tt-registration'); ?></h3>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-address1">*<?php esc_html_e('Address 1', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-address1" name="address1" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-suite"><?php esc_html_e('Suite #', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-suite" name="suite">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-city">*<?php esc_html_e('City', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-city" name="city" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-province">*<?php esc_html_e('Province', 'hc-tt-registration'); ?></label>
                                    <select class="form-control" id="hctt-province" name="province" required>
                                        <option value=""><?php esc_html_e('Select Province', 'hc-tt-registration'); ?></option>
                                        <?php foreach ($provinces as $abbr => $label) : ?>
                                            <option value="<?php echo esc_attr($abbr); ?>"><?php echo esc_html($abbr . ' - ' . $label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold d-block" for="hctt-postalCode">*<?php esc_html_e('Postal Code', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control" id="hctt-postalCode" name="postalCode" required>
                                    <div class="invalid-feedback" id="hctt-postalCodeError" style="display:none;"><?php esc_html_e('Format: XNX NXN', 'hc-tt-registration'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mt-3 hctt-org-types">
                            <label class="font-weight-bold d-block">*<?php esc_html_e('Organization Type', 'hc-tt-registration'); ?></label>
                            <?php foreach (hctt_get_registration_categories() as $cat) :
                                $id = 'hctt-category-' . sanitize_title($cat);
                                ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="category" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($cat); ?>" required>
                                    <label class="form-check-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($cat); ?></label>
                                </div>
                            <?php endforeach; ?>
                            <div class="form-group mt-2 hctt-category-other" style="display:none;">
                                <label class="font-weight-bold d-block" for="hctt-categoryOther">*<?php esc_html_e('Services Available', 'hc-tt-registration'); ?></label>
                                <input type="text" class="form-control hctt-small-text-input" id="hctt-categoryOther" name="categoryOther" maxlength="255">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold d-block">*<?php esc_html_e('Identify the majority of patients you cater to', 'hc-tt-registration'); ?></label>
                            <?php foreach (hctt_get_patients_types() as $pt) : ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="patientsType" id="hctt-patientsType-<?php echo esc_attr(strtolower($pt)); ?>" value="<?php echo esc_attr($pt); ?>" required>
                                    <label class="form-check-label" for="hctt-patientsType-<?php echo esc_attr(strtolower($pt)); ?>"><?php echo esc_html($pt); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="hctt-late-fields">
                            <div class="form-group">
                                <div class="hctt-inline-label-input">
                                    <label class="font-weight-bold hctt-late-fields-top-label mb-0" for="hctt-weeklyExpecting">*<?php esc_html_e('Approximately how many expecting parents do you see on a weekly basis?', 'hc-tt-registration'); ?></label>
                                    <input type="text" class="form-control hctt-inline-small-input" id="hctt-weeklyExpecting" name="weeklyExpecting" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                                </div>
                                <div class="invalid-feedback" id="hctt-weeklyExpectingError" style="display:none;"><?php esc_html_e('Please enter a whole number.', 'hc-tt-registration'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold d-block">*<?php esc_html_e('Would you like to receive sample of Diapers, Sudocrem, wipes for your patients', 'hc-tt-registration'); ?></label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="receivePatientSamples" id="hctt-receive-yes" value="Yes" required>
                                    <label class="form-check-label" for="hctt-receive-yes"><?php esc_html_e('Yes', 'hc-tt-registration'); ?></label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="receivePatientSamples" id="hctt-receive-no" value="No" required>
                                    <label class="form-check-label" for="hctt-receive-no"><?php esc_html_e('No', 'hc-tt-registration'); ?></label>
                                </div>
                            </div>
                            <div class="form-group hctt-confirm-check">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="hctt-confirmDistribution" name="confirmDistribution" value="1" required>
                                    <label class="form-check-label" for="hctt-confirmDistribution"><?php esc_html_e('I confirm that our healthcare centre will distribute Tommee Tippee Feeding Bottle to expecting parents only, and we will kindly encourage parents to register using the QR code provided on the package to access additional support and resources.', 'hc-tt-registration'); ?></label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold d-block" for="hctt-comments"><?php esc_html_e('Comments', 'hc-tt-registration'); ?></label>
                                <textarea class="form-control" id="hctt-comments" name="comments" rows="3" maxlength="1000"></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <span class="hctt-btn-spinner" aria-hidden="true"></span>
                                <span class="hctt-btn-label"><?php esc_html_e('Register', 'hc-tt-registration'); ?></span>
                            </button>
                        </div>
                    </form>

                    <div class="hctt-footer p-4 hctt-thankyou-card-footer">
                        <div class="hctt-powered-by"><?php esc_html_e('Powered by Samplits', 'hc-tt-registration'); ?></div>
                        <img src="<?php echo esc_url($assets['samplits_logo']); ?>" alt="<?php esc_attr_e('Samplits Logo', 'hc-tt-registration'); ?>">
                    </div>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var form = document.querySelector('.hctt-form');
                    if (!form || form.dataset.hcttReady === '1') return;
                    form.dataset.hcttReady = '1';

                    var catOtherWrap = form.querySelector('.hctt-category-other');
                    var catOtherInput = document.getElementById('hctt-categoryOther');
                    function updateCategoryOther() {
                        var selected = form.querySelector('input[name="category"]:checked');
                        var isOther = selected && selected.value === 'Other';
                        if (catOtherWrap) catOtherWrap.style.display = isOther ? 'block' : 'none';
                        if (catOtherInput) {
                            if (isOther) {
                                catOtherInput.setAttribute('required', 'required');
                            } else {
                                catOtherInput.removeAttribute('required');
                                catOtherInput.value = '';
                            }
                        }
                    }
                    form.addEventListener('change', function (e) {
                        if (e && e.target && e.target.name === 'category') updateCategoryOther();
                    });
                    updateCategoryOther();

                    function formatPhone(value) {
                        if (!value) return value;
                        var digits = value.replace(/\D/g, '').slice(0, 10);
                        if (digits.length <= 3) return digits;
                        if (digits.length <= 6) return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
                        return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
                    }

                    var phone = document.getElementById('hctt-phone');
                    if (phone) {
                        phone.addEventListener('input', function () {
                            phone.value = formatPhone(phone.value);
                            syncFieldVisual(phone, false);
                        });
                    }

                    function hideErr(id) {
                        var n = document.getElementById(id);
                        if (n) n.style.display = 'none';
                    }
                    function showErr(id) {
                        var n = document.getElementById(id);
                        if (n) n.style.display = 'block';
                    }

                    function syncFieldVisual(el, isBlur) {
                        if (!el || !form.contains(el)) return;
                        if (el.type === 'submit' || el.type === 'button') return;

                        if (el.type === 'checkbox') {
                            if (el.required) {
                                el.classList.toggle('is-valid', el.checked);
                                el.classList.toggle('is-invalid', !el.checked);
                            }
                            return;
                        }

                        if (el.type === 'radio' && el.name) {
                            var esc = el.name.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
                            var radios = form.querySelectorAll('input[type="radio"][name="' + esc + '"]');
                            if (!radios.length) return;
                            var tracked = ['category', 'patientsType', 'receivePatientSamples'];
                            var req = false;
                            radios.forEach(function (r) { if (r.required) req = true; });
                            if (!req && tracked.indexOf(el.name) === -1) return;
                            var checked = form.querySelector('input[type="radio"][name="' + esc + '"]:checked');
                            var ok = !!checked;
                            radios.forEach(function (r) {
                                r.classList.remove('is-valid', 'is-invalid');
                                if (ok && r.checked) r.classList.add('is-valid');
                                if (!ok) r.classList.add('is-invalid');
                            });
                            return;
                        }

                        if (el.tagName !== 'INPUT' && el.tagName !== 'SELECT' && el.tagName !== 'TEXTAREA') return;

                        var v = (el.value || '').trim();
                        var id = el.id;

                        if (!el.required && v === '') {
                            el.classList.remove('is-valid', 'is-invalid');
                            return;
                        }

                        if (el.required && v === '') {
                            el.classList.remove('is-valid');
                            if (isBlur) el.classList.add('is-invalid');
                            else el.classList.remove('is-invalid');
                            if (id === 'hctt-email') hideErr('hctt-emailError');
                            if (id === 'hctt-phone') hideErr('hctt-phoneError');
                            if (id === 'hctt-extension') hideErr('hctt-extensionError');
                            if (id === 'hctt-postalCode') hideErr('hctt-postalCodeError');
                            if (id === 'hctt-weeklyExpecting') hideErr('hctt-weeklyExpectingError');
                            return;
                        }

                        var ok = true;
                        if (id === 'hctt-email') {
                            ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
                            if (!ok) showErr('hctt-emailError'); else hideErr('hctt-emailError');
                        } else if (id === 'hctt-phone') {
                            ok = /^\(\d{3}\) \d{3}-\d{4}$/.test(v);
                            if (!ok) showErr('hctt-phoneError'); else hideErr('hctt-phoneError');
                        } else if (id === 'hctt-extension') {
                            ok = v === '' || /^[0-9]+$/.test(v);
                            if (!ok) showErr('hctt-extensionError'); else hideErr('hctt-extensionError');
                        } else if (id === 'hctt-postalCode') {
                            ok = /^[A-Za-z]\d[A-Za-z] \d[A-Za-z]\d$/.test(v);
                            if (!ok) showErr('hctt-postalCodeError'); else hideErr('hctt-postalCodeError');
                        } else if (id === 'hctt-weeklyExpecting') {
                            ok = /^[0-9]+$/.test(v);
                            if (!ok) showErr('hctt-weeklyExpectingError'); else hideErr('hctt-weeklyExpectingError');
                        }

                        if (!ok) {
                            el.classList.remove('is-valid');
                            el.classList.add('is-invalid');
                            return;
                        }
                        el.classList.remove('is-invalid');
                        el.classList.add('is-valid');
                    }

                    form.addEventListener('input', function (e) {
                        if (form.contains(e.target)) syncFieldVisual(e.target, false);
                    });
                    form.addEventListener('change', function (e) {
                        if (form.contains(e.target)) syncFieldVisual(e.target, true);
                    });
                    form.addEventListener('blur', function (e) {
                        if (form.contains(e.target)) syncFieldVisual(e.target, true);
                    }, true);

                    function validateForm() {
                        var isValid = true;
                        var required = form.querySelectorAll('[required]');
                        required.forEach(function (el) {
                            if (el.type === 'checkbox') {
                                el.classList.toggle('is-invalid', !el.checked);
                                el.classList.toggle('is-valid', el.checked);
                                if (!el.checked) isValid = false;
                                return;
                            }
                            if (el.type === 'radio') {
                                var group = form.querySelectorAll('input[type="radio"][name="' + el.name.replace(/"/g, '\\"') + '"]');
                                var anyChecked = false;
                                group.forEach(function (g) { if (g.checked) anyChecked = true; });
                                group.forEach(function (g) {
                                    g.classList.toggle('is-invalid', !anyChecked);
                                    g.classList.toggle('is-valid', anyChecked && g.checked);
                                });
                                if (!anyChecked) isValid = false;
                                return;
                            }
                            if (el.value === '') {
                                el.classList.add('is-invalid');
                                el.classList.remove('is-valid');
                                isValid = false;
                            } else {
                                el.classList.remove('is-invalid');
                                el.classList.add('is-valid');
                            }
                        });

                        var email = document.getElementById('hctt-email');
                        var emailErr = document.getElementById('hctt-emailError');
                        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value || '')) {
                            isValid = false;
                            email.classList.add('is-invalid');
                            email.classList.remove('is-valid');
                            if (emailErr) emailErr.style.display = 'block';
                        } else if (email) {
                            email.classList.remove('is-invalid');
                            email.classList.add('is-valid');
                            if (emailErr) emailErr.style.display = 'none';
                        }

                        var phoneEl = document.getElementById('hctt-phone');
                        var phoneErr = document.getElementById('hctt-phoneError');
                        if (phoneEl && !/^\(\d{3}\) \d{3}-\d{4}$/.test(phoneEl.value || '')) {
                            isValid = false;
                            phoneEl.classList.add('is-invalid');
                            phoneEl.classList.remove('is-valid');
                            if (phoneErr) phoneErr.style.display = 'block';
                        } else if (phoneEl) {
                            phoneEl.classList.remove('is-invalid');
                            phoneEl.classList.add('is-valid');
                            if (phoneErr) phoneErr.style.display = 'none';
                        }

                        var extEl = document.getElementById('hctt-extension');
                        var extErr = document.getElementById('hctt-extensionError');
                        if (extEl && extEl.value !== '' && !/^[0-9]+$/.test(extEl.value)) {
                            isValid = false;
                            extEl.classList.add('is-invalid');
                            extEl.classList.remove('is-valid');
                            if (extErr) extErr.style.display = 'block';
                        } else if (extEl) {
                            extEl.classList.remove('is-invalid');
                            extEl.classList.add('is-valid');
                            if (extErr) extErr.style.display = 'none';
                        }

                        var postal = document.getElementById('hctt-postalCode');
                        var postalErr = document.getElementById('hctt-postalCodeError');
                        if (postal && !/^[A-Za-z]\d[A-Za-z] \d[A-Za-z]\d$/.test((postal.value || '').trim())) {
                            isValid = false;
                            postal.classList.add('is-invalid');
                            postal.classList.remove('is-valid');
                            if (postalErr) postalErr.style.display = 'block';
                        } else if (postal) {
                            postal.classList.remove('is-invalid');
                            postal.classList.add('is-valid');
                            if (postalErr) postalErr.style.display = 'none';
                        }

                        var weekly = document.getElementById('hctt-weeklyExpecting');
                        var weeklyErr = document.getElementById('hctt-weeklyExpectingError');
                        if (weekly && !/^[0-9]+$/.test((weekly.value || '').trim())) {
                            isValid = false;
                            weekly.classList.add('is-invalid');
                            weekly.classList.remove('is-valid');
                            if (weeklyErr) weeklyErr.style.display = 'block';
                        } else if (weekly) {
                            weekly.classList.remove('is-invalid');
                            weekly.classList.add('is-valid');
                            if (weeklyErr) weeklyErr.style.display = 'none';
                        }

                        return isValid;
                    }

                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        if (!validateForm()) {
                            var c = document.querySelector('.hctt-container');
                            if (c) window.scrollTo({ top: c.getBoundingClientRect().top + window.scrollY - 40, behavior: 'smooth' });
                            return;
                        }
                        var btn = form.querySelector('button[type="submit"]');
                        if (btn && !btn.disabled) {
                            btn.disabled = true;
                            btn.classList.add('is-waiting');
                            btn.setAttribute('aria-busy', 'true');
                        }
                        if (typeof form.submit === 'function') form.submit();
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </main>
    <?php

    return ob_get_clean();
}

add_shortcode('hc_tt_registration', 'hctt_render_form_shortcode');

function hctt_handle_submission()
{
    $redirectUrl = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : home_url('/');
    if ($redirectUrl === '') {
        $redirectUrl = home_url('/');
    }

    $successRedirect = isset($_POST['hctt_success_redirect'])
        ? esc_url_raw(wp_unslash($_POST['hctt_success_redirect']))
        : $redirectUrl;
    if ($successRedirect === '') {
        $successRedirect = $redirectUrl;
    }
    $successRedirect = wp_validate_redirect($successRedirect, $redirectUrl);
    $successRedirect = remove_query_arg(['hctt_status', 'hctt_message'], $successRedirect);

    $posted_action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '';
    $nonce_by_action = [
        HCTT_POST_ACTION => HCTT_POST_ACTION,
        'hctt_submit_registration' => 'hctt_submit_registration',
    ];
    $nonce_action = isset($nonce_by_action[$posted_action]) ? $nonce_by_action[$posted_action] : HCTT_POST_ACTION;
    if (
        !isset($_POST['hctt_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hctt_nonce'])), $nonce_action)
    ) {
        wp_safe_redirect(hctt_build_feedback_url('error', __('Security check failed. Please try again.', 'hc-tt-registration'), $redirectUrl));
        exit;
    }
    global $wpdb;

    $input = [
        'healthcare_id' => '',
        'organization_name' => wp_unslash($_POST['name'] ?? ''),
        'contact_first_name' => wp_unslash($_POST['firstName'] ?? ''),
        'contact_last_name' => wp_unslash($_POST['lastName'] ?? ''),
        'email' => wp_unslash($_POST['email'] ?? ''),
        'job_title' => wp_unslash($_POST['job'] ?? ''),
        'department' => wp_unslash($_POST['department'] ?? ''),
        'phone' => wp_unslash($_POST['phone'] ?? ''),
        'extension' => wp_unslash($_POST['extension'] ?? ''),
        'address_1' => wp_unslash($_POST['address1'] ?? ''),
        'suite' => wp_unslash($_POST['suite'] ?? ''),
        'city' => wp_unslash($_POST['city'] ?? ''),
        'province' => wp_unslash($_POST['province'] ?? ''),
        'postal_code' => wp_unslash($_POST['postalCode'] ?? ''),
        'category' => wp_unslash($_POST['category'] ?? ''),
        'category_other' => wp_unslash($_POST['categoryOther'] ?? ''),
        'patients_type' => wp_unslash($_POST['patientsType'] ?? ''),
        'weekly_expecting_parents' => wp_unslash($_POST['weeklyExpecting'] ?? ''),
        'receive_patient_samples' => wp_unslash($_POST['receivePatientSamples'] ?? ''),
        'confirmed_distribution' => !empty($_POST['confirmDistribution']) ? wp_unslash($_POST['confirmDistribution']) : '',
        'comments' => wp_unslash($_POST['comments'] ?? ''),
    ];

    $validated = hctt_validate_submission_row($input, true);
    if (is_wp_error($validated)) {
        wp_safe_redirect(hctt_build_feedback_url('error', $validated->get_error_message(), $redirectUrl));
        exit;
    }

    $table = hctt_get_submissions_table_name();
    $validated['created_at'] = hctt_created_at_mysql();
    $inserted = $wpdb->insert(
        $table,
        $validated,
        [
            '%s', // healthcare_id
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%d', '%s', '%s',
        ]
    );

    if (!$inserted) {
        wp_safe_redirect(hctt_build_feedback_url('error', __('Could not save your submission. Please try again later.', 'hc-tt-registration'), $redirectUrl));
        exit;
    }

    $notify = sanitize_email(wp_unslash($_POST['hctt_notification_email'] ?? ''));
    if ($notify !== '' && is_email($notify) && apply_filters('hctt_send_notification_email', true, $wpdb->insert_id)) {
        $subject = sprintf('[%s] New Tommee Tippee Healthcare Registration', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $body = sprintf(
            "Organization: %s\nContact: %s %s\nEmail: %s\nPhone: %s\nAddress: %s, %s, %s %s\nOrganization Type: %s\nServices Available: %s\nPatients: %s\nExpecting parents (weekly): %s\nReceive patient samples: %s\nConfirmed distribution: %s\nComments: %s\n",
            $validated['organization_name'],
            $validated['contact_first_name'],
            $validated['contact_last_name'],
            $validated['email'],
            $validated['phone'],
            $validated['address_1'],
            $validated['city'],
            $validated['province'],
            $validated['postal_code'],
            $validated['category'],
            isset($validated['category_other']) && $validated['category_other'] !== null ? $validated['category_other'] : '',
            $validated['patients_type'],
            $validated['weekly_expecting_parents'],
            $validated['receive_patient_samples'],
            !empty($validated['confirmed_distribution']) ? 'yes' : 'no',
            isset($validated['comments']) && $validated['comments'] !== null ? $validated['comments'] : ''
        );
        wp_mail($notify, $subject, $body);
    }

    wp_safe_redirect(hctt_build_feedback_url('success', '', $successRedirect));
    exit;
}

add_action('admin_post_nopriv_' . HCTT_POST_ACTION, 'hctt_handle_submission');
add_action('admin_post_' . HCTT_POST_ACTION, 'hctt_handle_submission');
/** @deprecated Prior action name; kept for cached HTML. */
add_action('admin_post_nopriv_hctt_submit_registration', 'hctt_handle_submission');
add_action('admin_post_hctt_submit_registration', 'hctt_handle_submission');

function hctt_register_admin_menu()
{
    add_menu_page(
        __('HC Tommee Tippee Registrations', 'hc-tt-registration'),
        __('HC Tommee Tippee Registrations', 'hc-tt-registration'),
        'manage_options',
        HCTT_ADMIN_PAGE_SLUG,
        'hctt_render_admin_submissions_page',
        'dashicons-clipboard',
        28
    );
}

add_action('admin_menu', 'hctt_register_admin_menu');

add_action('admin_head', static function () {
    if (empty($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== HCTT_ADMIN_PAGE_SLUG) {
        return;
    }
    ?>
    <style>
        /* Keep the "No." column compact on submissions list table. */
        .wp-list-table .column-hctt_no { width: 30px; }
        .wp-list-table .column-hctt_no strong { display: inline-block; min-width: 0; }
        .hctt-modal[hidden] { display: none; }
        .hctt-modal { position: fixed; inset: 0; z-index: 100000; }
        .hctt-modal__backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.6); }
        .hctt-modal__dialog {
            position: relative;
            z-index: 1;
            width: min(420px, calc(100% - 32px));
            margin: 12vh auto 0;
            background: #fff;
            border-radius: 4px;
            padding: 20px 24px 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
        }
        .hctt-modal__dialog h2 { margin: 0 0 8px; font-size: 18px; line-height: 1.3; }
        .hctt-modal__dialog p { margin: 0; }
        .hctt-modal__actions { display: flex; justify-content: flex-end; gap: 8px; margin: 16px 0 0; }
        .hctt-modal__confirm.button-primary { background: #b32d2e; border-color: #b32d2e; }
        .hctt-modal__confirm.button-primary:hover,
        .hctt-modal__confirm.button-primary:focus { background: #8a2424; border-color: #8a2424; color: #fff; }
    </style>
    <?php
});

function hctt_render_delete_confirm_modal()
{
    if (empty($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== HCTT_ADMIN_PAGE_SLUG) {
        return;
    }
    ?>
    <div id="hctt-delete-modal" class="hctt-modal" hidden>
        <div class="hctt-modal__backdrop" data-hctt-modal-close></div>
        <div class="hctt-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="hctt-delete-modal-title">
            <h2 id="hctt-delete-modal-title"><?php esc_html_e('Delete this submission?', 'hc-tt-registration'); ?></h2>
            <p><?php esc_html_e('This cannot be undone.', 'hc-tt-registration'); ?></p>
            <p class="hctt-modal__actions">
                <button type="button" class="button" data-hctt-modal-close><?php esc_html_e('Cancel', 'hc-tt-registration'); ?></button>
                <a class="button button-primary hctt-modal__confirm" href="#"><?php esc_html_e('Delete', 'hc-tt-registration'); ?></a>
            </p>
        </div>
    </div>
    <script>
    (function () {
        var modal = document.getElementById('hctt-delete-modal');
        if (!modal) return;
        var confirmLink = modal.querySelector('.hctt-modal__confirm');
        var lastFocus = null;

        function openModal(href) {
            lastFocus = document.activeElement;
            confirmLink.setAttribute('href', href);
            modal.hidden = false;
            confirmLink.focus();
        }

        function closeModal() {
            modal.hidden = true;
            confirmLink.setAttribute('href', '#');
            if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        }

        document.addEventListener('click', function (event) {
            var link = event.target.closest('.hctt-delete-link');
            if (link) {
                event.preventDefault();
                openModal(link.href);
                return;
            }
            if (event.target.closest('[data-hctt-modal-close]')) {
                event.preventDefault();
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!modal.hidden && event.key === 'Escape') closeModal();
        });
    })();
    </script>
    <?php
}

add_action('admin_footer', 'hctt_render_delete_confirm_modal');

function hctt_maybe_export_submissions_csv()
{
    if (empty($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== HCTT_ADMIN_PAGE_SLUG) {
        return;
    }
    if (empty($_GET['hctt_export']) || sanitize_key(wp_unslash((string) $_GET['hctt_export'])) !== 'csv') {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-tt-registration'));
    }

    check_admin_referer('hctt_export_csv');

    global $wpdb;

    $table = hctt_get_submissions_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        wp_die(esc_html__('Submissions table is not available.', 'hc-tt-registration'));
    }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
    $cond = hctt_submissions_search_where_clause($wpdb, $search);

    $sql = "SELECT * FROM `{$table}`";
    if ($cond['sql'] !== '') {
        $sql .= ' WHERE ' . $cond['sql'];
    }
    $sql .= ' ORDER BY `created_at` DESC, `id` DESC';

    $rows = $cond['args'] === []
        ? $wpdb->get_results($sql, ARRAY_A)
        : $wpdb->get_results($wpdb->prepare($sql, ...$cond['args']), ARRAY_A);

    if (!is_array($rows)) {
        $rows = [];
    }

    $columns = hctt_submissions_csv_columns();
    $filename = 'hc-tt-registration-submissions-' . gmdate('Y-m-d') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    // Excel on Windows detects UTF-8 reliably when a BOM is present.
    // (Some plain-text viewers may show it as "ï»¿" if opened with the wrong encoding.)

    fputcsv($out, $columns);

    $no = 0;
    foreach ($rows as $row) {
        $no++;
        $line = [];
        foreach ($columns as $col) {
            if ($col === 'no') {
                $line[] = (string) $no;
                continue;
            }

            $val = isset($row[$col]) && $row[$col] !== null ? (string) $row[$col] : '';
            if ($col === 'confirmed_distribution') {
                $val = ((int) $val === 1) ? 'Yes' : 'No';
            }
            $line[] = hctt_csv_normalize_value($val);
        }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}

add_action('admin_init', 'hctt_maybe_export_submissions_csv', 1);

function hctt_render_admin_submissions_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-tt-registration'));
    }

    if (!empty($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
        $editId = absint($_GET['id']);
        if ($editId > 0) {
            hctt_render_submission_edit($editId);

            return;
        }
    }

    if (!empty($_GET['view'])) {
        $viewId = absint($_GET['view']);
        if ($viewId > 0) {
            hctt_render_submission_detail($viewId);

            return;
        }
    }

    $list_table = new HCTT_Submissions_List_Table();
    $list_table->prepare_items();

    $notice = isset($_GET['hctt_notice']) ? sanitize_key(wp_unslash($_GET['hctt_notice'])) : '';
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Tommee Tippee Natural Start Healthcare Centres Registration Submissions', 'hc-tt-registration'); ?></h1>
        <hr class="wp-header-end">
        <?php if ($notice === 'saved') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Submission updated.', 'hc-tt-registration'); ?></p></div>
        <?php elseif ($notice === 'deleted') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Submission deleted.', 'hc-tt-registration'); ?></p></div>
        <?php elseif ($notice === 'error') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(isset($_GET['hctt_err']) ? sanitize_text_field(wp_unslash((string) $_GET['hctt_err'])) : __('Something went wrong.', 'hc-tt-registration')); ?></p></div>
        <?php endif; ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(HCTT_ADMIN_PAGE_SLUG); ?>">
            <?php if (!empty($_GET['orderby'])) : ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr(sanitize_key(wp_unslash((string) $_GET['orderby']))); ?>">
            <?php endif; ?>
            <?php if (!empty($_GET['order'])) : ?>
                <input type="hidden" name="order" value="<?php echo esc_attr(strtoupper(sanitize_text_field(wp_unslash((string) $_GET['order']))) === 'ASC' ? 'ASC' : 'DESC'); ?>">
            <?php endif; ?>
            <?php $list_table->display(); ?>
        </form>
    </div>
    <?php
}

/**
 * @param int $id Submission primary key.
 */
function hctt_render_submission_detail($id)
{
    global $wpdb;

    $table = hctt_get_submissions_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        echo '<div class="wrap"><p>' . esc_html__('Submissions table is not available.', 'hc-tt-registration') . '</p></div>';

        return;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
    if (!$row) {
        echo '<div class="wrap"><p>' . esc_html__('Submission not found.', 'hc-tt-registration') . '</p></div>';

        return;
    }

    $hctt_no = isset($_GET['hctt_no']) ? absint(wp_unslash($_GET['hctt_no'])) : 0;

    $back_url = add_query_arg(['page' => HCTT_ADMIN_PAGE_SLUG], admin_url('admin.php'));
    $edit_url = add_query_arg(
        array_filter(
            [
                'page' => HCTT_ADMIN_PAGE_SLUG,
                'action' => 'edit',
                'id' => (int) $row->id,
                'hctt_no' => $hctt_no > 0 ? $hctt_no : null,
            ]
        ),
        admin_url('admin.php')
    );
    $delete_url = wp_nonce_url(
        add_query_arg(
            [
                'action' => 'hctt_delete_submission',
                'id' => (int) $row->id,
            ],
            admin_url('admin-post.php')
        ),
        'hctt_delete_submission_' . (int) $row->id
    );

    $fields = [
        'healthcare_id' => __('Healthcare ID', 'hc-tt-registration'),
        'organization_name' => __('Organization', 'hc-tt-registration'),
        'contact_first_name' => __('Contact first name', 'hc-tt-registration'),
        'contact_last_name' => __('Contact last name', 'hc-tt-registration'),
        'email' => __('Email', 'hc-tt-registration'),
        'job_title' => __('Job title', 'hc-tt-registration'),
        'department' => __('Department', 'hc-tt-registration'),
        'phone' => __('Phone', 'hc-tt-registration'),
        'extension' => __('Extension', 'hc-tt-registration'),
        'address_1' => __('Address', 'hc-tt-registration'),
        'suite' => __('Suite', 'hc-tt-registration'),
        'city' => __('City', 'hc-tt-registration'),
        'province' => __('Province', 'hc-tt-registration'),
        'postal_code' => __('Postal code', 'hc-tt-registration'),
        'category' => __('Organization Type', 'hc-tt-registration'),
        'category_other' => __('Services Available', 'hc-tt-registration'),
        'patients_type' => __('Patients type', 'hc-tt-registration'),
        'weekly_expecting_parents' => __('Expecting parents (weekly)', 'hc-tt-registration'),
        'receive_patient_samples' => __('Receive patient samples (Diapers, Sudocrem, wipes)', 'hc-tt-registration'),
        'confirmed_distribution' => __('Confirmed distribution', 'hc-tt-registration'),
        'comments' => __('Comments', 'hc-tt-registration'),
        'created_at' => __('Submitted', 'hc-tt-registration'),
    ];
    ?>
    <div class="wrap hctt-submission-detail">
        <h1><?php echo $hctt_no > 0 ? esc_html(sprintf(__('No. %d', 'hc-tt-registration'), $hctt_no)) : esc_html__('Submission', 'hc-tt-registration'); ?></h1>
        <?php if (!empty($_GET['hctt_notice']) && sanitize_key(wp_unslash($_GET['hctt_notice'])) === 'saved') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Submission updated.', 'hc-tt-registration'); ?></p></div>
        <?php endif; ?>
        <p><a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to list', 'hc-tt-registration'); ?></a></p>
        <table class="widefat striped" style="max-width:920px;">
            <tbody>
            <?php foreach ($fields as $key => $label) : ?>
                <tr>
                    <th scope="row" style="width:220px;"><?php echo esc_html($label); ?></th>
                    <td>
                        <?php
                        $val = isset($row->$key) ? (string) $row->$key : '';
                        if ($key === 'email' && $val !== '') {
                            echo '<a href="' . esc_url('mailto:' . $val) . '">' . esc_html($val) . '</a>';
                        } elseif ($key === 'created_at' && $val !== '') {
                            $ts = hctt_created_at_to_timestamp($val);
                            echo $ts ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts, hctt_created_at_timezone())) : esc_html($val);
                        } elseif ($key === 'confirmed_distribution') {
                            echo ((int) $val === 1) ? esc_html__('Yes', 'hc-tt-registration') : esc_html__('No', 'hc-tt-registration');
                        } elseif ($key === 'comments' && $val !== '') {
                            echo nl2br(esc_html($val));
                        } else {
                            echo $val !== '' ? esc_html($val) : '—';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="submit" style="max-width:920px;">
            <a class="button button-primary" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'hc-tt-registration'); ?></a>
            <a class="button button-link-delete hctt-delete-link" href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('Delete', 'hc-tt-registration'); ?></a>
        </p>
    </div>
    <?php
}

/**
 * @param int $id Submission primary key.
 */
function hctt_render_submission_edit($id)
{
    global $wpdb;

    $table = hctt_get_submissions_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        echo '<div class="wrap"><p>' . esc_html__('Submissions table is not available.', 'hc-tt-registration') . '</p></div>';

        return;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
    if (!$row) {
        echo '<div class="wrap"><p>' . esc_html__('Submission not found.', 'hc-tt-registration') . '</p></div>';

        return;
    }

    $hctt_no = isset($_GET['hctt_no']) ? absint(wp_unslash($_GET['hctt_no'])) : 0;

    $back_list = add_query_arg(['page' => HCTT_ADMIN_PAGE_SLUG], admin_url('admin.php'));
    $back_view = add_query_arg(
        array_filter(
            [
                'page' => HCTT_ADMIN_PAGE_SLUG,
                'view' => (int) $row->id,
                'hctt_no' => $hctt_no > 0 ? $hctt_no : null,
            ]
        ),
        admin_url('admin.php')
    );

    $v = static function ($key) use ($row) {
        return isset($row->$key) ? (string) $row->$key : '';
    };

    $provinces = hctt_get_canadian_provinces();
    ?>
    <div class="wrap">
        <h1><?php echo $hctt_no > 0 ? esc_html(sprintf(__('Edit — No. %d', 'hc-tt-registration'), $hctt_no)) : esc_html__('Edit submission', 'hc-tt-registration'); ?></h1>
        <?php
        $admNotice = isset($_GET['hctt_notice']) ? sanitize_key(wp_unslash($_GET['hctt_notice'])) : '';
        if ($admNotice === 'error' && !empty($_GET['hctt_err'])) :
            $errMsg = sanitize_text_field(wp_unslash((string) $_GET['hctt_err']));
            ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($errMsg); ?></p></div>
        <?php endif; ?>
        <p>
            <a href="<?php echo esc_url($back_list); ?>">&larr; <?php esc_html_e('Back to list', 'hc-tt-registration'); ?></a>
            &nbsp;|&nbsp;
            <a href="<?php echo esc_url($back_view); ?>"><?php esc_html_e('View detail', 'hc-tt-registration'); ?></a>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hctt-admin-edit-form">
            <?php wp_nonce_field('hctt_save_submission_' . (int) $row->id); ?>
            <input type="hidden" name="action" value="hctt_save_submission">
            <input type="hidden" name="submission_id" value="<?php echo (int) $row->id; ?>">
            <input type="hidden" name="hctt_display_no" value="<?php echo $hctt_no > 0 ? (int) $hctt_no : ''; ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="hctt-adm-hc-id"><?php esc_html_e('Healthcare ID', 'hc-tt-registration'); ?></label></th>
                    <td>
                        <?php $hcIdVal = $v('healthcare_id'); ?>
                        <input name="healthcare_id" id="hctt-adm-hc-id" type="text" placeholder="HCP" class="regular-text" value="<?php echo esc_attr($hcIdVal !== '' ? $hcIdVal : ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-org"><?php esc_html_e('Organization', 'hc-tt-registration'); ?></label></th>
                    <td><input name="organization_name" id="hctt-adm-org" type="text" class="regular-text" value="<?php echo esc_attr($v('organization_name')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-fn"><?php esc_html_e('Contact first name', 'hc-tt-registration'); ?></label></th>
                    <td><input name="contact_first_name" id="hctt-adm-fn" type="text" class="regular-text" value="<?php echo esc_attr($v('contact_first_name')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-ln"><?php esc_html_e('Contact last name', 'hc-tt-registration'); ?></label></th>
                    <td><input name="contact_last_name" id="hctt-adm-ln" type="text" class="regular-text" value="<?php echo esc_attr($v('contact_last_name')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-em"><?php esc_html_e('Email', 'hc-tt-registration'); ?></label></th>
                    <td><input name="email" id="hctt-adm-em" type="email" class="regular-text" value="<?php echo esc_attr($v('email')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-job"><?php esc_html_e('Job title', 'hc-tt-registration'); ?></label></th>
                    <td><input name="job_title" id="hctt-adm-job" type="text" class="regular-text" value="<?php echo esc_attr($v('job_title')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-dept"><?php esc_html_e('Department', 'hc-tt-registration'); ?></label></th>
                    <td><input name="department" id="hctt-adm-dept" type="text" class="regular-text" value="<?php echo esc_attr($v('department')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-ph"><?php esc_html_e('Phone', 'hc-tt-registration'); ?></label></th>
                    <td><input name="phone" id="hctt-adm-ph" type="text" class="regular-text" value="<?php echo esc_attr($v('phone')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-ext"><?php esc_html_e('Extension', 'hc-tt-registration'); ?></label></th>
                    <td><input name="extension" id="hctt-adm-ext" type="text" class="regular-text" value="<?php echo esc_attr($v('extension')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-a1"><?php esc_html_e('Address', 'hc-tt-registration'); ?></label></th>
                    <td><input name="address_1" id="hctt-adm-a1" type="text" class="regular-text" value="<?php echo esc_attr($v('address_1')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-suite"><?php esc_html_e('Suite', 'hc-tt-registration'); ?></label></th>
                    <td><input name="suite" id="hctt-adm-suite" type="text" class="regular-text" value="<?php echo esc_attr($v('suite')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-city"><?php esc_html_e('City', 'hc-tt-registration'); ?></label></th>
                    <td><input name="city" id="hctt-adm-city" type="text" class="regular-text" value="<?php echo esc_attr($v('city')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-prov"><?php esc_html_e('Province', 'hc-tt-registration'); ?></label></th>
                    <td>
                        <select name="province" id="hctt-adm-prov" required>
                            <option value=""><?php esc_html_e('Select…', 'hc-tt-registration'); ?></option>
                            <?php foreach ($provinces as $abbr => $label) : ?>
                                <option value="<?php echo esc_attr($abbr); ?>" <?php selected($v('province'), $abbr); ?>><?php echo esc_html($abbr . ' — ' . $label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-postal"><?php esc_html_e('Postal code', 'hc-tt-registration'); ?></label></th>
                    <td><input name="postal_code" id="hctt-adm-postal" type="text" class="regular-text" value="<?php echo esc_attr($v('postal_code')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Organization Type', 'hc-tt-registration'); ?></th>
                    <td>
                        <?php foreach (hctt_get_registration_categories() as $cat) : ?>
                            <label style="display:inline-block;margin-right:12px;">
                                <input type="radio" name="category" value="<?php echo esc_attr($cat); ?>" <?php checked($v('category'), $cat); ?> required>
                                <?php echo esc_html($cat); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-cat-other"><?php esc_html_e('Services Available', 'hc-tt-registration'); ?></label></th>
                    <td><input name="category_other" id="hctt-adm-cat-other" type="text" class="regular-text" value="<?php echo esc_attr($v('category_other')); ?>" maxlength="255"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Patients type', 'hc-tt-registration'); ?></th>
                    <td>
                        <?php foreach (hctt_get_patients_types() as $pt) : ?>
                            <label style="display:inline-block;margin-right:12px;">
                                <input type="radio" name="patients_type" value="<?php echo esc_attr($pt); ?>" <?php checked($v('patients_type'), $pt); ?> required>
                                <?php echo esc_html($pt); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-weekly"><?php esc_html_e('Expecting parents (weekly)', 'hc-tt-registration'); ?></label></th>
                    <td><input name="weekly_expecting_parents" id="hctt-adm-weekly" type="text" class="small-text" value="<?php echo esc_attr($v('weekly_expecting_parents')); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Receive patient samples', 'hc-tt-registration'); ?></th>
                    <td>
                        <label><input type="radio" name="receive_patient_samples" value="Yes" <?php checked($v('receive_patient_samples'), 'Yes'); ?> required> <?php esc_html_e('Yes', 'hc-tt-registration'); ?></label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="receive_patient_samples" value="No" <?php checked($v('receive_patient_samples'), 'No'); ?> required> <?php esc_html_e('No', 'hc-tt-registration'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Confirmed distribution', 'hc-tt-registration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="confirmed_distribution" value="1" <?php checked((int) $v('confirmed_distribution'), 1); ?> required>
                            <?php esc_html_e('I confirm that our healthcare centre will distribute Tommee Tippee Feeding Bottle to expecting parents only, and we will kindly encourage parents to register using the QR code provided on the package to access additional support and resources.', 'hc-tt-registration'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hctt-adm-comments"><?php esc_html_e('Comments', 'hc-tt-registration'); ?></label></th>
                    <td>
                        <textarea name="comments" id="hctt-adm-comments" class="large-text" rows="4" maxlength="1000"><?php echo esc_textarea($v('comments')); ?></textarea>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save changes', 'hc-tt-registration')); ?>
        </form>
    </div>
    <?php
}

function hctt_handle_admin_delete_submission()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-tt-registration'));
    }

    $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    if ($id < 1) {
        wp_safe_redirect(hctt_admin_submissions_list_url(['hctt_notice' => 'error', 'hctt_err' => __('Invalid submission.', 'hc-tt-registration')]));
        exit;
    }

    check_admin_referer('hctt_delete_submission_' . $id);

    global $wpdb;
    $table = hctt_get_submissions_table_name();
    $wpdb->delete($table, ['id' => $id], ['%d']);

    wp_safe_redirect(hctt_admin_submissions_list_url(['hctt_notice' => 'deleted']));
    exit;
}

function hctt_handle_admin_save_submission()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'hc-tt-registration'));
    }

    $id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
    $display_no = isset($_POST['hctt_display_no']) ? absint(wp_unslash((string) $_POST['hctt_display_no'])) : 0;
    if ($id < 1 || !isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'hctt_save_submission_' . $id)) {
        wp_safe_redirect(hctt_admin_submissions_list_url(['hctt_notice' => 'error', 'hctt_err' => __('Security check failed.', 'hc-tt-registration')]));
        exit;
    }

    $input = [
        'healthcare_id' => wp_unslash($_POST['healthcare_id'] ?? ''),
        'organization_name' => wp_unslash($_POST['organization_name'] ?? ''),
        'contact_first_name' => wp_unslash($_POST['contact_first_name'] ?? ''),
        'contact_last_name' => wp_unslash($_POST['contact_last_name'] ?? ''),
        'email' => wp_unslash($_POST['email'] ?? ''),
        'job_title' => wp_unslash($_POST['job_title'] ?? ''),
        'department' => wp_unslash($_POST['department'] ?? ''),
        'phone' => wp_unslash($_POST['phone'] ?? ''),
        'extension' => wp_unslash($_POST['extension'] ?? ''),
        'address_1' => wp_unslash($_POST['address_1'] ?? ''),
        'suite' => wp_unslash($_POST['suite'] ?? ''),
        'city' => wp_unslash($_POST['city'] ?? ''),
        'province' => wp_unslash($_POST['province'] ?? ''),
        'postal_code' => wp_unslash($_POST['postal_code'] ?? ''),
        'category' => wp_unslash($_POST['category'] ?? ''),
        'category_other' => wp_unslash($_POST['category_other'] ?? ''),
        'patients_type' => wp_unslash($_POST['patients_type'] ?? ''),
        'weekly_expecting_parents' => wp_unslash($_POST['weekly_expecting_parents'] ?? ''),
        'receive_patient_samples' => wp_unslash($_POST['receive_patient_samples'] ?? ''),
        'confirmed_distribution' => !empty($_POST['confirmed_distribution']) ? '1' : '',
        'comments' => wp_unslash($_POST['comments'] ?? ''),
    ];

    $validated = hctt_validate_submission_row($input, true);
    if (is_wp_error($validated)) {
        wp_safe_redirect(
            hctt_admin_submissions_list_url(
                array_filter(
                    [
                        'hctt_notice' => 'error',
                        'hctt_err' => $validated->get_error_message(),
                        'action' => 'edit',
                        'id' => $id,
                        'hctt_no' => $display_no > 0 ? $display_no : null,
                    ]
                )
            )
        );
        exit;
    }

    global $wpdb;
    $table = hctt_get_submissions_table_name();
    $wpdb->update(
        $table,
        $validated,
        ['id' => $id],
        [
            '%s', // healthcare_id
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%d', '%s',
        ],
        ['%d']
    );

    if ($wpdb->last_error !== '') {
        wp_safe_redirect(
            hctt_admin_submissions_list_url(
                array_filter(
                    [
                        'hctt_notice' => 'error',
                        'hctt_err' => __('Could not save changes.', 'hc-tt-registration'),
                        'action' => 'edit',
                        'id' => $id,
                        'hctt_no' => $display_no > 0 ? $display_no : null,
                    ]
                )
            )
        );
        exit;
    }

    wp_safe_redirect(
        add_query_arg(
            array_filter(
                [
                    'page' => HCTT_ADMIN_PAGE_SLUG,
                    'view' => $id,
                    'hctt_notice' => 'saved',
                    'hctt_no' => $display_no > 0 ? $display_no : null,
                ]
            ),
            admin_url('admin.php')
        )
    );
    exit;
}

/**
 * @param array<string,string> $args Query args merged onto admin list URL.
 */
function hctt_admin_submissions_list_url(array $args = [])
{
    return add_query_arg(array_merge(['page' => HCTT_ADMIN_PAGE_SLUG], $args), admin_url('admin.php'));
}

add_action('admin_post_hctt_delete_submission', 'hctt_handle_admin_delete_submission');
add_action('admin_post_hctt_save_submission', 'hctt_handle_admin_save_submission');
