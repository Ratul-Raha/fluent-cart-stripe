<?php

use FluentCart\Framework\Support\Arr;

class StripeApi
{


    private $createSessionUrl;

    public function __construct()
    {
        $this->createSessionUrl = 'https://api.stripe.com/v1/checkout/sessions';
    }

    public function makeApiCall($path, $args, $apiKey, $method = 'GET')
    {
        $stripeApiKey = $apiKey;

        $headers = array(
            'Authorization' => 'Bearer ' . $stripeApiKey,
        );


        if ($method === 'POST') {
            $responseData = $this->createCheckoutSession($stripeApiKey, $args);
        }

        if (is_wp_error($responseData)) {
            return $responseData;
        }



        if (empty($responseData['id'])) {
            $message = Arr::get($responseData, 'detail');
            if (!$message) {
                $message = Arr::get($responseData, 'error.message');
            }
            if (!$message) {
                $message = 'Unknown Stripe API request error';
            }

            return new \WP_Error(423, $message, $responseData);
        }

        return $responseData;
    }


    public function verifyIPN()
    {
        if (!isset($_REQUEST['fct_payment_listener'])) {
            return;
        }

        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        // Set initial post data to empty string
        $post_data = '';

        // Fallback just in case post_max_size is lower than needed
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            ini_set('post_max_size', '12M');
        }

        $data =  json_decode($post_data);



        if (!property_exists($data, 'event')) {
            return $data;
        } else {
            error_log("specific event");
            error_log(print_r($data));
            return false;
        }

        exit(200);
    }

    public function createCheckoutSession($stripeApiKey, $args)
    {
        $items = $args['items'];
        $lineItems = [];

        foreach ($items as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int) ($item['price']),
                    'product_data' => [
                        'name' => $item['title'],

                    ],
                ],
                'quantity' => (int) $item['quantity'],
            ];
        }

        $invoiceData = [
            'account_tax_ids' => [],
            'custom_fields' => [],
            'description' => 'Invoice for Order #' . $args['client_reference_id'],
            'footer' => '',
            'metadata' => [],
            'rendering_options' => [],
        ];

        $sessionPayload = array(
            'client_reference_id' => $args['client_reference_id'],
            'success_url' => $args['success_url'],
            'cancel_url' => 'http://example.com/cancel',
            'payment_method_types' => $args['payment_method_type'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'invoice_creation' => array(
                'enabled' => 'true',
                'invoice_data' => $invoiceData,
            ),
        );

        $sessionPayloadEncoded = http_build_query($sessionPayload);

        $sessionHeaders = array(
            'Authorization' => 'Bearer ' . $stripeApiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        );

        $sessionArgs = array(
            'headers' => $sessionHeaders,
            'body' => $sessionPayloadEncoded,
            'method' => 'POST',
        );

        $sessionResponse = wp_remote_post($this->createSessionUrl, $sessionArgs);
     
        if (is_wp_error($sessionResponse)) {
            echo "API Error: " . $sessionResponse->get_error_message();
            exit;
        }

        $sessionResponseData = wp_remote_retrieve_body($sessionResponse);

        $sessionData = json_decode($sessionResponseData, true);

        return $sessionData;
    }

    public function getInvoice($checkoutSessionId, $apiKey)
    {
        $apiUrl = 'https://api.stripe.com/v1/checkout/sessions/' . $checkoutSessionId;
        error_log(print_r($apiUrl, true), 3, __DIR__ . '/debug.log');


        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $apiKey,
            ),
        );

        $checkoutSessionResponse = wp_remote_get($apiUrl, $args);

        if (!is_wp_error($checkoutSessionResponse) && $checkoutSessionResponse['response']['code'] === 200) {
            $checkoutSession = json_decode($checkoutSessionResponse['body']);
            error_log(print_r($checkoutSession, true), 3, __DIR__ . '/debug.log');

            if (isset($checkoutSession->invoice)) {
                $invoiceId = $checkoutSession->invoice;
                error_log(print_r($invoiceId, true), 3, __DIR__ . '/debug.log');

                $invoiceUrl = 'https://api.stripe.com/v1/invoices/' . $invoiceId;


                $invoiceResponse = wp_remote_get($invoiceUrl, $args);


                if (!is_wp_error($invoiceResponse) && $invoiceResponse['response']['code'] === 200) {
                    $invoice = json_decode($invoiceResponse['body']);
                    error_log(print_r($invoice, true), 3, __DIR__ . '/debug.log');
                    return $invoice;
                } else {

                    error_log('Error fetching invoice: ' . print_r($invoiceResponse, true), 3, __DIR__ . '/debug.log');
                    return null;
                }
            }
        } else {
            error_log('Error fetching checkout session: ' . print_r($checkoutSessionResponse, true), 3, __DIR__ . '/debug.log');
            return null;
        }
    }
}
