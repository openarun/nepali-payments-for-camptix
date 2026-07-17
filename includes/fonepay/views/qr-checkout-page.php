<?php
/**
 * Fonepay QR checkout page template.
 *
 * Layout follows Checkout by Fonepay Brand & Identity Guidelines 2026
 * (Desktop Preview — QR checkout). Ticket summary sits beside the QR card
 * on wide screens so the guideline composition stays primary.
 *
 * @package CampTix_Nepali_Payments
 *
 * @var array $data Checkout data passed from the gateway.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$camptix_fonepay_logo_url     = isset( $data['logoUrl'] ) ? $data['logoUrl'] : '';
$camptix_fonepay_event_name   = isset( $data['eventName'] ) ? $data['eventName'] : '';
$camptix_fonepay_ticket_items = ( isset( $data['ticketItems'] ) && is_array( $data['ticketItems'] ) ) ? $data['ticketItems'] : array();
$camptix_fonepay_attendees    = ( isset( $data['attendees'] ) && is_array( $data['attendees'] ) ) ? $data['attendees'] : array();
$camptix_fonepay_has_order    = ( $camptix_fonepay_event_name || $camptix_fonepay_ticket_items || $camptix_fonepay_attendees );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Pay with Fonepay QR', 'nepali-payments-for-camptix' ); ?></title>
	<?php
	// Print only this gateway's stylesheet (standalone page; avoid theme CSS via wp_head).
	wp_print_styles( array( 'camptix-fonepay-qr' ) );
	?>
</head>

<body class="camptix-fonepay-body">
	<div class="camptix-fonepay-shell<?php echo $camptix_fonepay_has_order ? ' has-order' : ''; ?>">
		<div class="camptix-fonepay-layout">
			<div class="camptix-fonepay-main">
				<div class="camptix-fonepay-topbar">
					<a href="<?php echo esc_url( $data['cancelUrl'] ); ?>" id="camptix-fonepay-cancel" class="camptix-fonepay-cancel">
						&larr; <?php esc_html_e( 'Cancel', 'nepali-payments-for-camptix' ); ?>
					</a>
				</div>

				<div class="camptix-fonepay-card">
					<p class="camptix-fonepay-scanline">
						<?php
						printf(
							/* translators: %s: emphasized "Mobile Banking or Wallets App" text */
							esc_html__( 'Use your %s to scan', 'nepali-payments-for-camptix' ),
							'<strong>' . esc_html__( 'Mobile Banking or Wallets App', 'nepali-payments-for-camptix' ) . '</strong>'
						);
						?>
					</p>

					<?php if ( $camptix_fonepay_logo_url ) : ?>
						<img
							class="camptix-fonepay-logo"
							src="<?php echo esc_url( $camptix_fonepay_logo_url ); ?>"
							alt="<?php esc_attr_e( 'Checkout by Fonepay', 'nepali-payments-for-camptix' ); ?>"
							width="220"
							height="52"
						/>
					<?php endif; ?>

					<p class="camptix-fonepay-title"><?php esc_html_e( 'Scan & Pay', 'nepali-payments-for-camptix' ); ?></p>

					<div class="camptix-fonepay-amount">
						<?php
						printf(
							/* translators: %s: payable amount */
							esc_html__( 'NPR %s', 'nepali-payments-for-camptix' ),
							esc_html( number_format( (float) $data['amount'], 2 ) )
						);
						?>
					</div>

					<div class="camptix-fonepay-qr-wrap">
						<div id="camptix-fonepay-qrcode" class="camptix-fonepay-qr"></div>
					</div>

					<p id="camptix-fonepay-status" class="camptix-fonepay-status"><?php esc_html_e( 'Waiting for payment confirmation...', 'nepali-payments-for-camptix' ); ?></p>

					<button type="button" id="camptix-fonepay-verify" class="camptix-fonepay-btn" disabled>
						<?php esc_html_e( 'Check Status', 'nepali-payments-for-camptix' ); ?>
					</button>

					<div class="camptix-fonepay-help">
						<h3><?php esc_html_e( 'How to pay using this QR code:', 'nepali-payments-for-camptix' ); ?></h3>
						<ul>
							<li><?php esc_html_e( 'Open your mobile banking app or digital wallet.', 'nepali-payments-for-camptix' ); ?></li>
							<li><?php esc_html_e( 'Log in, or simply tap the scan button (login not always required).', 'nepali-payments-for-camptix' ); ?></li>
							<li><?php esc_html_e( 'Scan the QR code.', 'nepali-payments-for-camptix' ); ?></li>
							<li><?php esc_html_e( 'Verify the payment details.', 'nepali-payments-for-camptix' ); ?></li>
							<li><?php esc_html_e( 'Tap Confirm to complete your payment.', 'nepali-payments-for-camptix' ); ?></li>
						</ul>
					</div>
				</div>
			</div>

			<?php if ( $camptix_fonepay_has_order ) : ?>
				<aside class="camptix-fonepay-order" aria-label="<?php esc_attr_e( 'Order summary', 'nepali-payments-for-camptix' ); ?>">
					<p class="camptix-fonepay-order-heading"><?php esc_html_e( 'Order summary', 'nepali-payments-for-camptix' ); ?></p>

					<?php if ( $camptix_fonepay_event_name ) : ?>
						<p class="camptix-fonepay-event"><?php echo esc_html( $camptix_fonepay_event_name ); ?></p>
					<?php endif; ?>

					<?php if ( $camptix_fonepay_ticket_items ) : ?>
						<ul class="camptix-fonepay-tickets">
							<?php foreach ( $camptix_fonepay_ticket_items as $camptix_fonepay_item ) : ?>
								<li>
									<span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: ticket name, 2: quantity */
												__( '%1$s × %2$d', 'nepali-payments-for-camptix' ),
												$camptix_fonepay_item['name'],
												(int) $camptix_fonepay_item['quantity']
											)
										);
										?>
									</span>
									<span class="camptix-fonepay-meta">
										<?php
										printf(
											/* translators: %s: line-item amount */
											esc_html__( 'NPR %s', 'nepali-payments-for-camptix' ),
											esc_html( number_format( (float) $camptix_fonepay_item['price'] * (int) $camptix_fonepay_item['quantity'], 2 ) )
										);
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $camptix_fonepay_attendees ) : ?>
						<ul class="camptix-fonepay-attendees">
							<?php foreach ( $camptix_fonepay_attendees as $camptix_fonepay_attendee ) : ?>
								<li>
									<span><?php echo esc_html( $camptix_fonepay_attendee['name'] ); ?></span>
									<?php if ( ! empty( $camptix_fonepay_attendee['ticket'] ) ) : ?>
										<span class="camptix-fonepay-meta"><?php echo esc_html( $camptix_fonepay_attendee['ticket'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</aside>
			<?php endif; ?>
		</div>
	</div>
	<?php
	// Print only this gateway's scripts. Avoid wp_footer(), which loads
	// theme/CampTix assets and can surface admin notices on this page.
	wp_print_scripts( array( 'camptix-fonepay-qr' ) );
	?>
</body>

</html>
