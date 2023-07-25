<?php

/*
Plugin Name: Fluent Cart Stripe Payment
Description: Stripe Payment Method For Fluent Cart
Author: Goutom Dash
Version: 1.0.0
Author URI: example.com
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'fluent_cart/register_payment_methods', function () {
	require_once('PaymentMethods/StripePayment.php');
	(new StripePayment())->init();
});


