<?php
defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('adapter.payment.payment');

class AbstractPaystackPayment extends JPayment
{
    /**
     * Build admin parameters
     */
    protected function buildAdminParameters()
    {
        $logo_img = VIKPAYSTACK_URI . 'assets/paystack.png';

        return [
            'logo' => [
                'label' => '',
                'type'  => 'custom',
                'html'  => '<img src="' . esc_url($logo_img) . '" width="150"/>',
            ],
            'secret_key' => [
                'label' => __('Secret Key', 'vikbooking'),
                'type'  => 'text',
            ],
            'testmode' => [
                'label'   => __('Test Mode', 'vikbooking'),
                'type'    => 'select',
                'options' => ['Yes', 'No'],
            ],
        ];
    }

    /**
     * Constructor
     */
    public function __construct($alias, $order, $params = [])
    {
        parent::__construct($alias, $order, $params);
    }

    /**
     * Begin Transaction
     */
    protected function beginTransaction()
    {
        /**
         * 🔒 PREVENT PAYMENT LOOP
         * If booking is already paid, DO NOT re-init Paystack
         */
        if ($this->get('payment_status') === 'PAID') {
            return true;
        }

        $secret_key = trim($this->getParam('secret_key'));

        if (empty($secret_key)) {
            wp_die('Paystack secret key not configured.');
        }

        $amount    = round($this->get('total_to_pay') * 100);
        $reference = 'VB-' . $this->get('sid') . '-' . time();
        $email     = $this->get('custmail') ?: get_option('admin_email');
        $callback  = $this->get('notify_url');

        $data = [
            'email'        => $email,
            'amount'       => $amount,
            'reference'    => $reference,
            'callback_url' => $callback,
        ];

        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secret_key,
                'Content-Type: application/json',
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if (!empty($result['status']) && $result['status'] === true) {
            JFactory::getApplication()->redirect($result['data']['authorization_url']);
            exit;
        }

        wp_die('Paystack init failed: ' . ($result['message'] ?? 'Unknown error'));
    }

    /**
     * Validate Transaction
     */
    protected function validateTransaction(JPaymentStatus &$status)
    {
        $secret_key = trim($this->getParam('secret_key'));
        $reference  = $_GET['reference'] ?? '';

        if (!$reference) {
            $status->appendLog('No reference returned from Paystack');
            return false;
        }

        $ch = curl_init("https://api.paystack.co/transaction/verify/{$reference}");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secret_key,
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if (!empty($result['status']) && $result['data']['status'] === 'success') {
            $status->verified();
            $status->paid($result['data']['amount'] / 100);
            return true;
        }

        $status->appendLog('Paystack verification failed');
        return false;
    }

    /**
     * Complete Transaction
     */
    protected function complete($res = 0)
    {
        $app = JFactory::getApplication();

        if ($res) {
            $url = $this->get('return_url');
            $app->enqueueMessage(__('Payment successful.', 'vikpaystack'));
        } else {
            $url = $this->get('error_url');
            $app->enqueueMessage(__('Payment verification failed.', 'vikpaystack'));
        }

        $app->redirect($url);
        exit;
    }
}
