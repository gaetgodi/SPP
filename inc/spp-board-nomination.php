<?php
/* =========================================================
   SPP Board Nominee Information Form
   Version: 1.0.0
   Date: 2026-09-23
   - Reusable [spp_board_nomination] form for logged-in members.
   - Emails the completed form to the board; no public records.
   - Signature and date come from the authenticated user/server.
   - The board checks the ten-day deadline against the AGM date.
   ========================================================= */
defined( 'ABSPATH' ) || exit;

function spp_bn_fields() {
    return array(
        'first_name' => array( 'label' => 'Nominee first name', 'type' => 'text', 'max' => 100 ),
        'last_name' => array( 'label' => 'Nominee last name', 'type' => 'text', 'max' => 100 ),
        'mobile' => array( 'label' => 'Mobile phone', 'type' => 'tel', 'max' => 50 ),
        'home' => array( 'label' => 'Home phone', 'type' => 'tel', 'max' => 50 ),
        'address' => array( 'label' => 'Mailing address', 'type' => 'text', 'max' => 300 ),
        'town' => array( 'label' => 'Town', 'type' => 'text', 'max' => 100 ),
        'email' => array( 'label' => 'Nominee email address', 'type' => 'email', 'max' => 254 ),
        'qualifications' => array( 'label' => 'Nominee qualifications', 'type' => 'textarea', 'max' => 10000,
            'help' => 'Describe education, work experience, board experience, and specific skills that will benefit SPP.' ),
        'contribution' => array( 'label' => 'Why is the nominee interested in serving on the board, and how will they contribute to the organization?', 'type' => 'textarea', 'max' => 10000 ),
    );
}

function spp_bn_validate( $input ) {
    $values = array();
    foreach ( spp_bn_fields() as $key => $field ) {
        $raw = $input[ $key ] ?? '';
        if ( ! is_string( $raw ) ) {
            return new WP_Error( 'invalid', 'Please check the ' . $field['label'] . ' field.' );
        }
        $value = trim( $raw );
        if ( strlen( $value ) > $field['max'] * 4 ) {
            return new WP_Error( 'long', $field['label'] . ' is too long.' );
        }
        $value = $field['type'] === 'textarea' ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
        if ( $length > $field['max'] ) {
            return new WP_Error( 'long', $field['label'] . ' is too long.' );
        }
        if ( $value === '' && ! in_array( $key, array( 'mobile', 'home' ), true ) ) {
            return new WP_Error( 'required', 'Please complete: ' . $field['label'] . '.' );
        }
        if ( $key === 'email' && ! is_email( $value ) ) {
            return new WP_Error( 'email', 'Please enter a valid nominee email address.' );
        }
        $values[ $key ] = $value;
    }
    if ( $values['mobile'] === '' && $values['home'] === '' ) {
        return new WP_Error( 'phone', 'Please provide a mobile or home phone number.' );
    }
    return $values;
}

function spp_bn_email_body( $values, $user, $submitted_at ) {
    $body = '<h1>SPP Board Nominee Information Form</h1>';
    $body .= '<p>Nominations must be submitted at least 10 days before the AGM.</p>';
    foreach ( spp_bn_fields() as $key => $field ) {
        $body .= '<h3>' . esc_html( $field['label'] ) . '</h3><p>' . nl2br( esc_html( $values[ $key ] ?: 'Not provided' ) ) . '</p>';
    }
    $body .= '<hr><p><strong>Submitted by / signature:</strong> ' . esc_html( $user->user_login ) . '</p>';
    $body .= '<p><strong>Submission date:</strong> ' . esc_html( $submitted_at ) . '</p>';
    $body .= '<p><strong>Submitter email:</strong> ' . esc_html( $user->user_email ) . '</p>';
    return $body;
}

add_shortcode( 'spp_board_nomination', 'spp_bn_shortcode' );
function spp_bn_shortcode() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    if ( ! headers_sent() ) nocache_headers();
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to submit a board nomination.</p>';
    }
    $id = wp_unique_id( 'spp-bn-' );
    wp_enqueue_script( 'spp-board-nomination', get_stylesheet_directory_uri() . '/js/spp-board-nomination.js', array(), '1.0.0', true );
    ob_start();
    ?>
    <div class="spp-bn-wrap" style="max-width:800px;margin:0 auto;color:var(--spp-text,#2c2c2c);">
        <h2>Board Nominee Information Form</h2>
        <p>Nominations must be submitted at least 10 days before the AGM.</p>
        <p>The completed form will be emailed to board@pickleballstouffville.ca. All fields are required except that only one phone number is needed.</p>
        <form class="spp-bn-form" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
            <input type="hidden" name="action" value="spp_bn_submit">
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'spp_bn_submit' ) ); ?>">
            <?php foreach ( spp_bn_fields() as $key => $field ) :
                $field_id = $id . '-' . $key;
                $required = ! in_array( $key, array( 'mobile', 'home' ), true );
                ?>
                <div style="margin-bottom:18px;">
                    <label for="<?php echo esc_attr( $field_id ); ?>" style="display:block;font-weight:600;margin-bottom:6px;"><?php echo esc_html( $field['label'] ); ?></label>
                    <?php if ( isset( $field['help'] ) ) : ?>
                        <p id="<?php echo esc_attr( $field_id . '-help' ); ?>" style="font-size:0.95em;"><?php echo esc_html( $field['help'] ); ?></p>
                    <?php endif; ?>
                    <?php if ( $field['type'] === 'textarea' ) : ?>
                        <textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="6" maxlength="<?php echo (int) $field['max']; ?>" required <?php if ( isset( $field['help'] ) ) echo 'aria-describedby="' . esc_attr( $field_id . '-help' ) . '"'; ?> style="box-sizing:border-box;width:100%;padding:12px;border:1px solid var(--spp-border-color,#ccc);border-radius:5px;resize:vertical;background:var(--spp-bg-white,#fff);color:var(--spp-text,#2c2c2c);"></textarea>
                    <?php else : ?>
                        <input id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $key ); ?>" type="<?php echo esc_attr( $field['type'] ); ?>" maxlength="<?php echo (int) $field['max']; ?>" <?php if ( $required ) echo 'required'; ?> style="box-sizing:border-box;width:100%;padding:12px;border:1px solid var(--spp-border-color,#ccc);border-radius:5px;background:var(--spp-bg-white,#fff);color:var(--spp-text,#2c2c2c);">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p><strong>Submitted by / signature:</strong> <?php echo esc_html( wp_get_current_user()->user_login ); ?></p>
            <p>Your username serves as your signature. The submission date and time are recorded automatically when you submit.</p>
            <button type="submit" style="padding:12px 22px;border:0;border-radius:5px;background:var(--spp-primary,#00897b);color:var(--spp-text-light,#fff);cursor:pointer;font-size:1rem;">Submit nomination</button>
            <noscript><p>Please enable JavaScript to submit this form.</p></noscript>
        </form>
        <p class="spp-bn-status" role="status" aria-live="polite" tabindex="-1" style="margin-top:16px;"></p>
    </div>
    <?php
    return ob_get_clean();
}

add_action( 'wp_ajax_spp_bn_submit', 'spp_bn_submit' );
add_action( 'wp_ajax_nopriv_spp_bn_submit', 'spp_bn_submit' );
function spp_bn_submit() {
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in before submitting a nomination.', 401 );
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) wp_send_json_error( 'Please submit the form using the submit button.', 405 );
    if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! check_ajax_referer( 'spp_bn_submit', 'nonce', false ) ) {
        wp_send_json_error( 'Your session has expired. Open this page in a new tab and copy your answers there to submit.', 403 );
    }
    $values = spp_bn_validate( wp_unslash( $_POST ) );
    if ( is_wp_error( $values ) ) wp_send_json_error( $values->get_error_message(), 400 );
    $user = wp_get_current_user();
    // Cache only a digest, never the nominee's personal information.
    $key = 'spp_bn_sent_' . $user->ID . '_' . md5( wp_json_encode( $values ) );
    if ( get_transient( $key ) ) {
        wp_send_json_success( 'This nomination was already submitted recently. Thank you.' );
    }
    $submitted_at = wp_date( 'F j, Y \a\t g:i:s a T', null, new DateTimeZone( 'America/Toronto' ) );
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    if ( is_email( $user->user_email ) ) $headers[] = 'Reply-To: ' . $user->user_email;
    $sent = wp_mail(
        'board@pickleballstouffville.ca',
        'SPP Board Nomination: ' . $values['first_name'] . ' ' . $values['last_name'],
        spp_bn_email_body( $values, $user, $submitted_at ),
        $headers
    );
    if ( ! $sent ) {
        wp_send_json_error( 'The nomination could not be emailed. Your answers are still here. Please try again, or contact board@pickleballstouffville.ca.', 500 );
    }
    set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
    wp_send_json_success( 'Thank you. Your nomination has been submitted for email delivery to the board. Submission date: ' . $submitted_at . '.' );
}
