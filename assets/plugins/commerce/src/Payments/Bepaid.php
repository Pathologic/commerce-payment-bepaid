<?php

namespace Commerce\Payments;

class Bepaid extends Payment
{
    protected $debug = false;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('bepaid');
        $this->debug = $this->getSetting('debug') == '1';
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('shop_id')) && empty($this->getSetting('secret_key'))) {
            return '<span class="error" style="color: red;">' . $this->lang['bepaid.error.empty_client_credentials'] . '</span>';
        }
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $currency  = ci()->currency->getCurrency($order['currency']);
        $payment   = $this->createPayment($order['id'], $order['amount']);

        $payment['amount'] = round($payment['amount'] * 100, 2);
        $payment['amount'] = (int) $payment['amount'];

        $customer = [
            'email' => $order['email'],
            'phone' => $order['phone'],
        ];

        $data = [
            'checkout' => [
                'transaction_type' => 'payment',
                'test' => $this->getSetting('test') == '1',
                'settings' => [
                    'return_url' => MODX_SITE_URL . 'commerce/bepaid/payment-success',
                    'success_url' => MODX_SITE_URL . 'commerce/bepaid/payment-success',
                    'decline_url' => MODX_SITE_URL . 'commerce/bepaid/payment-failed',
                    'fail_url' => MODX_SITE_URL . 'commerce/bepaid/payment-failed',
                    'cancel_url' => MODX_SITE_URL . 'commerce/bepaid/payment-failed',
                    'notification_url' => MODX_SITE_URL . 'commerce/bepaid/payment-process?paymentHash=' . $payment['hash'],
                    'language' => "ru",
                ],
                'order' => [
                    'currency' => $currency['code'],
                    'amount' => $payment['amount'],
                    'description' => $this->lang['bepaid.order_description'] . ' ' . $order['id'],
                    'tracking_id' => $order['id'] . '-' . $payment['hash']
                ],
                'customer' => $customer
            ]
        ];

        if ($response = $this->request($data)) {
            if (isset($response['checkout']['redirect_url'])) {
                return $response['checkout']['redirect_url'];
            } elseif ($this->debug) {
                $this->modx->logEvent(0, 3, 'Request failed: <pre>' . print_r($data, true) . '</pre><pre>'. print_r($response, true) . '</pre>',
                    'Commerce Bepaid Payment');
            }
        }

        return false;
    }

    public function handleCallback()
    {
        if (!isset($_SERVER['PHP_AUTH_USER'])
            || !isset($_SERVER['PHP_AUTH_PW'])
            || $_SERVER['PHP_AUTH_USER'] != $this->getSetting('shop_id')
            || $_SERVER['PHP_AUTH_PW'] != $this->getSetting('secret_key')
        ) {
            $this->modx->logEvent(0, 3, 'Notify response can not be authorized', 'Commerce Bepaid Payment');

            return false;
        }

        $response = json_decode(file_get_contents('php://input'), true);

        if (isset($response['transaction']) && isset($response['transaction']['tracking_id'])
            && isset($response['transaction']['type']) && $response['transaction']['type'] == 'payment'
            && !empty($response['transaction']['status']) && $response['transaction']['status'] === "successful"
        ) {
            $paymentHash = $this->getRequestPaymentHash();
            $processor = $this->modx->commerce->loadProcessor();
            try {
                $payment = $processor->loadPaymentByHash($paymentHash);

                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($paymentHash, true)) . '" . not found!');
                }

                return $processor->processPayment($payment['id'], $payment['amount']);
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Bepaid Payment');

                return false;
            }
        }

        return false;
    }

    protected function request($data)
    {
        $ch = curl_init('https://checkout.bepaid.by/ctp/api/checkouts');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cache-Control: no-cache',
            'X-API-Version: 2'
        ]);

        curl_setopt($ch, CURLOPT_USERPWD, $this->getSetting('shop_id') . ':' . $this->getSetting('secret_key')
        );

        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Request failed: <pre>' . print_r($data, true) . '</pre><pre>'. print_r($error, true) . '</pre>',
                    'Commerce Bepaid Payment');
            }

            return false;
        }

        return json_decode($response, true);
    }

    public function getRequestPaymentHash()
    {
        if (!empty($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }
}