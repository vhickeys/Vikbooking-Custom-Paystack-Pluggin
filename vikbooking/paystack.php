<?php
/**
 * @package     VikPaystack
 * @subpackage  vikbooking
 * @author      You
 * @license     GNU General Public License version 2 or later
 * @link        https://yourwebsite.com
 */

defined('ABSPATH') or die('No script kiddies please!');

// Import the main Paystack plugin
JLoader::import('paystack', VIKPAYSTACK_DIR);

// Prepend the deposit message before the payment form (if specified)
add_action('payment_after_begin_transaction_vikbooking', function(&$payment, &$html)
{
    if (!$payment->isDriver('paystack')) return;

    if ($payment->get('leave_deposit')) {
        $html = '<p class="vbo-leave-deposit">
            <span>' . JText::_('VBLEAVEDEPOSIT') . '</span>' . 
            $payment->get('currency_symb') . ' ' . number_format($payment->get('total_to_pay'), 2) . 
        '</p><br/>' . $html;
    }

    // Save total_to_pay in a transient (fallback to file if needed)
    $was_using_cache = wp_using_ext_object_cache(false);

    $transient = set_transient(
        'vikpaystack_vikbooking_' . $payment->get('oid') . '_' . $payment->get('sid'),
        $payment->get('total_to_pay'),
        10 * MINUTE_IN_SECONDS
    );

    wp_using_ext_object_cache($was_using_cache);

    if (!$transient) {
        $txname = $payment->get('sid') . '-' . $payment->get('oid') . '.tx';
        $fp = fopen(VIKPAYSTACK_DIR . DIRECTORY_SEPARATOR . 'Paystack' . DIRECTORY_SEPARATOR . $txname, 'w+');
        fwrite($fp, $payment->get('total_to_pay'));
        fclose($fp);
    }
}, 10, 2);

// Retrieve total_to_pay before validating the transaction
add_action('payment_before_validate_transaction_vikbooking', function($payment)
{
    if (!$payment->isDriver('paystack')) return;

    $txname = $payment->get('sid') . '-' . $payment->get('oid') . '.tx';
    $path   = VIKPAYSTACK_DIR . DIRECTORY_SEPARATOR . 'Paystack' . DIRECTORY_SEPARATOR . $txname;

    $was_using_cache = wp_using_ext_object_cache(false);
    $transient = 'vikpaystack_vikbooking_' . $payment->get('oid') . '_' . $payment->get('sid');

    $data = get_transient($transient);

    if ($data) {
        $payment->set('total_to_pay', $data);
        delete_transient($transient);
        wp_using_ext_object_cache($was_using_cache);
    } elseif (is_file($path)) {
        $fp = fopen($path, 'rb');
        $txdata = fread($fp, filesize($path));
        fclose($fp);

        if (!empty($txdata)) {
            $payment->set('total_to_pay', $txdata);
        } else {
            $payment->set('total_to_pay', $payment->get('total_to_pay', 0));
        }

        unlink($path);
    }
});

// Redirect user after validation
add_action('payment_on_after_validation_vikbooking', function(&$payment, $res)
{
    if (!$payment->isDriver('paystack')) return;

    $url = 'index.php?option=com_vikbooking&task=vieworder&sid=' . $payment->get('sid') . '&ts=' . $payment->get('ts');

    $model  = JModel::getInstance('vikbooking', 'shortcodes', 'admin');
    $itemid = $model->all('post_id');

    if (count($itemid)) {
        $url = JRoute::_($url . '&Itemid=' . $itemid[0]->post_id, false);
    }

    JFactory::getApplication()->redirect($url);
    exit;
}, 10, 2);

/**
 * VikBooking Paystack Payment Class
 */
class VikBookingPaystackPayment extends AbstractPaystackPayment
{
    public function __construct($alias, $order, $params = array())
    {
        parent::__construct($alias, $order, $params);

        $details = $this->get('details', array());

        $this->set('oid', $this->get('id', null));
        if (!$this->get('oid')) {
            $this->set('oid', isset($details['id']) ? $details['id'] : 0);
        }

        if (!$this->get('sid')) {
            $this->set('sid', isset($details['sid']) ? $details['sid'] : 0);
        }

        if (!$this->get('ts')) {
            $this->set('ts', isset($details['ts']) ? $details['ts'] : 0);
        }

        if (!$this->get('custmail')) {
            $this->set('custmail', isset($details['custmail']) ? $details['custmail'] : '');
        }
    }
}
