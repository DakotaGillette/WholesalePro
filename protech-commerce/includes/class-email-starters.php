<?php
/**
 * The starter email templates: complete, designed emails an admin can use
 * as they are or copy and change, so nothing starts from a blank page.
 *
 * Each is the raw input EmailTemplates::validate() takes, so a starter goes
 * through exactly the sanitizing a hand-built template does. Written for
 * the Protech Sleeves store: plain sentences, no exclamation marks.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailStarters
 */
class EmailStarters {

	/**
	 * @return array<string, array<string, mixed>> key => template input.
	 */
	public static function all(): array {
		return array(
			'welcome'              => self::welcome(),
			'application_received' => self::application_received(),
			'application_approved' => self::application_approved(),
			'application_rejected' => self::application_rejected(),
			'restock_reminder'     => self::restock_reminder(),
			'winback'              => self::winback(),
			'announcement'         => self::announcement(),
			'blank'                => self::blank(),
		);
	}

	/** @param array<string, mixed> $attrs */
	private static function block( string $type, array $attrs = array() ): array {
		return array(
			'type'  => $type,
			'attrs' => $attrs,
		);
	}

	/**
	 * The welcome email for accounts upgraded to wholesale, block for block
	 * what templates/welcome-email.php sends today.
	 *
	 * @return array<string, mixed>
	 */
	private static function welcome(): array {
		return array(
			'name'      => __( 'Welcome to wholesale', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_TRANSACTIONAL,
			'subject'   => __( 'Your Protech Sleeves wholesale account is ready', 'protech-wholesale' ),
			'preheader' => __( 'How to log in and how ordering works.', 'protech-wholesale' ),
			'blocks'    => array(
				self::block( 'text', array( 'html' => __( "Hi {first_name},\n\nYour Protech Sleeves account now has wholesale pricing. Here is how to log in and how ordering works.", 'protech-wholesale' ), 'pt' => 16 ) ),
				self::block( 'heading', array( 'text' => __( 'How to log in', 'protech-wholesale' ), 'size' => 20, 'pt' => 12 ) ),
				self::block(
					'text',
					array(
						'html' => __( "1. Go to <a href=\"{login_url}\">our wholesale login page</a>.\n\n2. Sign in with <strong>{email}</strong> and the password you already use. Not sure of it? <a href=\"{lost_password_url}\">Reset your password</a>.\n\n3. Once you are in, every price in the shop is your wholesale price.", 'protech-wholesale' ),
					)
				),
				self::block( 'button', array( 'label' => __( 'Log in to wholesale', 'protech-wholesale' ), 'url' => '{login_url}', 'align' => 'left', 'pb' => 20 ) ),
				self::block( 'explainer_quantities' ),
				self::block( 'explainer_ladder', array( 'pt' => 16 ) ),
				self::block( 'text', array( 'html' => __( "To restock fast, open any past order in My Account and choose Reorder.\n\nQuestions? Reply to this email and we will help.", 'protech-wholesale' ), 'pb' => 16 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function application_received(): array {
		return array(
			'name'      => __( 'Application received', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_TRANSACTIONAL,
			'subject'   => __( 'We received your wholesale application', 'protech-wholesale' ),
			'preheader' => __( 'We will email you as soon as a decision is made.', 'protech-wholesale' ),
			'blocks'    => array(
				self::block( 'heading', array( 'text' => __( 'Application received', 'protech-wholesale' ), 'pt' => 16 ) ),
				self::block( 'text', array( 'html' => __( "Thanks for applying for a Protech Sleeves wholesale account.\n\nOur team typically reviews applications within 1 to 3 business days. We will email you as soon as a decision is made.", 'protech-wholesale' ), 'pb' => 16 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function application_approved(): array {
		return array(
			'name'      => __( 'Application approved', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_TRANSACTIONAL,
			'subject'   => __( 'Your Protech Sleeves wholesale account is approved', 'protech-wholesale' ),
			'preheader' => __( 'Set your password and log in.', 'protech-wholesale' ),
			'blocks'    => array(
				self::block( 'heading', array( 'text' => __( 'Your account is approved', 'protech-wholesale' ), 'pt' => 16 ) ),
				self::block( 'text', array( 'html' => __( "Good news, your wholesale application has been approved.\n\nUse the button below to set your password and log in. The link is valid for 24 hours. If it has expired, choose Lost your password on the login page with this email address to get a new one.", 'protech-wholesale' ) ) ),
				self::block( 'button', array( 'label' => __( 'Set your password and log in', 'protech-wholesale' ), 'url' => '{set_password_url}', 'align' => 'left', 'pb' => 20 ) ),
				self::block( 'text', array( 'html' => __( "Once you are logged in you will see wholesale pricing throughout the shop. Quantity tiers apply to your whole cart combined, and Reorder on any past order adds it straight back to your cart.", 'protech-wholesale' ), 'pb' => 16 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function application_rejected(): array {
		return array(
			'name'      => __( 'Application rejected', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_TRANSACTIONAL,
			'subject'   => __( 'Update on your Protech Sleeves wholesale application', 'protech-wholesale' ),
			'preheader' => __( 'About your wholesale application.', 'protech-wholesale' ),
			'blocks'    => array(
				self::block( 'heading', array( 'text' => __( 'Your wholesale application', 'protech-wholesale' ), 'pt' => 16 ) ),
				self::block( 'text', array( 'html' => __( "Thanks for your interest in a Protech Sleeves wholesale account. After review, we are not able to approve your application at this time.\n\n{application_reject_reason}\n\nIf you think this was a mistake, or your business has changed since you applied, just reply to this email.", 'protech-wholesale' ), 'pb' => 16 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function restock_reminder(): array {
		return array(
			'name'      => __( 'Restock reminder', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_MARKETING,
			'subject'   => __( 'Time to restock, {first_name}', 'protech-wholesale' ),
			'preheader' => __( 'It has been {days_since_last_order} days since your last order.', 'protech-wholesale' ),
			'blocks'    => array(
				self::block( 'heading', array( 'text' => __( 'Time to restock, {first_name}', 'protech-wholesale' ), 'pt' => 16 ) ),
				self::block( 'text', array( 'html' => __( "It has been {days_since_last_order} days since your last order. Your shelves may be getting light, and your wholesale prices are waiting.", 'protech-wholesale' ) ) ),
				self::block( 'product_grid', array( 'mode' => 'newest', 'limit' => 3, 'columns' => 3 ) ),
				self::block( 'button', array( 'label' => __( 'Reorder from your account', 'protech-wholesale' ), 'url' => '{orders_url}', 'align' => 'center', 'pb' => 20 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function winback(): array {
		return array(
			'name'      => __( 'Win-back offer', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_MARKETING,
			'subject'   => __( 'We miss you, {first_name}', 'protech-wholesale' ),
			'preheader' => __( 'Your wholesale account is still open.', 'protech-wholesale' ),
			'blocks'    => array(
				self::block( 'heading', array( 'text' => __( 'It has been a while', 'protech-wholesale' ), 'pt' => 16 ) ),
				self::block( 'text', array( 'html' => __( "Hi {first_name},\n\nWe have not seen an order from {store_name} in a while. Your wholesale account is still open, and the more you order, the more you save.", 'protech-wholesale' ) ) ),
				self::block( 'explainer_ladder', array( 'title' => __( 'What each order size unlocks', 'protech-wholesale' ) ) ),
				self::block( 'button', array( 'label' => __( 'Shop wholesale', 'protech-wholesale' ), 'url' => '{shop_url}', 'align' => 'center', 'pt' => 16, 'pb' => 20 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function announcement(): array {
		return array(
			'name'      => __( 'New arrivals announcement', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_MARKETING,
			'subject'   => __( 'New at Protech Sleeves', 'protech-wholesale' ),
			'preheader' => __( 'Something new just landed.', 'protech-wholesale' ),
			'blocks'    => array(
				self::block( 'image', array( 'alt' => __( 'New at Protech Sleeves', 'protech-wholesale' ) ) ),
				self::block( 'heading', array( 'text' => __( 'Something new just landed', 'protech-wholesale' ), 'pt' => 16 ) ),
				self::block( 'text', array( 'html' => __( "Hi {first_name},\n\nTell your customers what is new: a color, a bundle, a restock. Two or three sentences is plenty.", 'protech-wholesale' ) ) ),
				self::block( 'product_grid', array( 'mode' => 'newest', 'limit' => 3, 'columns' => 3 ) ),
				self::block( 'button', array( 'label' => __( 'See what is new', 'protech-wholesale' ), 'url' => '{shop_url}', 'align' => 'center', 'pb' => 20 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function blank(): array {
		return array(
			'name'      => __( 'Blank', 'protech-wholesale' ),
			'kind'      => EmailTemplates::KIND_MARKETING,
			'subject'   => '',
			'preheader' => '',
			'blocks'    => array(
				self::block( 'heading', array( 'pt' => 16 ) ),
				self::block( 'text', array( 'pb' => 16 ) ),
			),
		);
	}
}
