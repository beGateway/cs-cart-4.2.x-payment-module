<?php
use Tygh\Registry;
require Registry::get('config.dir.addons') . 'begateway/payments/lib/lib/beGateway.php';

if ( !defined('AREA') ) { die('Access denied'); }

if (defined('PAYMENT_NOTIFICATION')) {

  if ($mode == 'result') {
    if (fn_check_payment_script('begateway.php', $_REQUEST['order_id'])) {
      $order_info = fn_get_order_info($_REQUEST['order_id'], true);
      if ($order_info['status'] == 'N') {
        fn_change_order_status($_REQUEST['order_id'], 'O', '', false);
      }
    }
    fn_order_placement_routines('route', $_REQUEST['order_id']);

  } elseif ($mode == 'cancel') {
    if (fn_check_payment_script('begateway.php', $_REQUEST['order_id'])) {
      $pp_response = array();
      $pp_response['order_status'] = 'N';
      $pp_response['reason_text']  = __('text_transaction_cancelled');
      fn_finish_payment($_REQUEST['order_id'], $pp_response);
    }
    fn_order_placement_routines('checkout_redirect', $_REQUEST['order_id'], false);

  } elseif ($mode == 'notify') {
    $webhook = new \beGateway\Webhook;
    $order_info = fn_get_order_info($webhook->getTrackingId());

    if (empty($processor_data)) {
      $processor_data = fn_get_processor_data($order_info['payment_id']);
    }

    \beGateway\Settings::$shopId = $processor_data['processor_params']['begateway_shop_id'];
    \beGateway\Settings::$shopKey = $processor_data['processor_params']['begateway_shop_pass'];

    if ($webhook->isAuthorized()) {
      $pp_response = array();
      $order_id = $webhook->getTrackingId();

      if ($webhook->isSuccess()) {
        $pp_response['order_status'] = 'P';
      } elseif ($webhook->isIncomplete() || $webhook->isPending()) {
        $pp_response['order_status'] = 'O';
      } elseif ($webhook->isFailed()) {
        $pp_response['order_status'] = 'F';
      }

      $pp_response['reason_text'] = $webhook->getMessage();
      if ($webhook->isTest())
        $pp_response['reason_text'] .= ' *** Test Mode ***';

      $pp_response['transaction_id'] = $webhook->getUid();

      if (fn_check_payment_script('begateway.php', $order_id)) {
        fn_finish_payment($order_id, $pp_response); // Force customer notification
      }
    }
  }
} else {

  $order_id = $order_info['repaid'] ? $order_id . '_' . $order_info['repaid'] : $order_id;

  \beGateway\Settings::$shopId = $processor_data['processor_params']['begateway_shop_id'];
  \beGateway\Settings::$shopKey = $processor_data['processor_params']['begateway_shop_pass'];
  \beGateway\Settings::$checkoutBase = 'https://' . $processor_data['processor_params']['begateway_checkout_domain'];

  $transaction = new \beGateway\GetPaymentToken;

  if ($processor_data['processor_params']['begateway_payment_type'] == 'authorization') {
    $transaction->setAuthorizationTransactionType();
  }

  $state = $order_info['b_state'];
  if (empty($state)) {
    $state_val = NULL;
  } else {
    $state_val = $state;
  }

  $ipn_url = fn_url("payment_notification.notify?payment=begateway&order_id=$order_id", AREA, 'current');
  $ipn_url = str_replace('carts.local', 'webhook.begateway.com:8443', $ipn_url);
  $success_url = fn_url("payment_notification.result?payment=begateway&order_id=$order_id", AREA, 'current');
  $fail_url = fn_url("payment_notification.result?payment=begateway&order_id=$order_id", AREA, 'current');
  $cancel_url = fn_url("payment_notification.cancel?payment=begateway&order_id=$order_id", AREA, 'current');

  $lang_iso_code = CART_LANGUAGE;
  $currency_code = $order_info['secondary_currency'];
  $amount = $order_info['total'];
  $description = __("order") . ' # '.$order_id;

  $transaction->setNotificationUrl($ipn_url);
  $transaction->setSuccessUrl($success_url);
  $transaction->setDeclineUrl($fail_url);
  $transaction->setFailUrl($fail_url);
  $transaction->setCancelUrl($cancel_url);
  $transaction->setLanguage($lang_iso_code);
  $transaction->setTrackingId($order_id);
  $transaction->money->setAmount($amount);
  $transaction->money->setCurrency($currency_code);
  $transaction->setDescription($description);
  $transaction->customer->setFirstName($order_info['b_firstname']);
  $transaction->customer->setLastName($order_info['b_lastname']);
  $transaction->customer->setAddress($order_info['b_address']);
  $transaction->customer->setCountry($order_info['b_country']);
  $transaction->customer->setAddress($order_info['b_address']);
  $transaction->customer->setAddress($order_info['b_address']);
  $transaction->customer->setState($state_val);
  $transaction->customer->setZip($order_info['b_zipcode']);
  $transaction->customer->setPhone($order_info['phone']);
  $transaction->customer->setEmail($order_info['email']);
  $transaction->setAddressHidden();
  $transaction->setEmailReadonly();

  if ($processor_data['processor_params']['begateway_bankcard'] == 'Y') {
    $cc = new \beGateway\PaymentMethod\CreditCard;
    $transaction->addPaymentMethod($cc);
  }

  if ($processor_data['processor_params']['begateway_bankcard_halva'] == 'Y') {
    $halva = new \beGateway\PaymentMethod\CreditCardHalva;
    $transaction->addPaymentMethod($halva);
  }

  if ($processor_data['processor_params']['begateway_erip'] == 'Y') {
    $erip = new \beGateway\PaymentMethod\Erip(array(
      'order_id' => $order_id,
      'account_number' => $order_id,
      'service_no' => $processor_data['processor_params']['begateway_erip_service_code']
    ));
    $transaction->addPaymentMethod($erip);
  }

  try {
    $response = $transaction->submit();
  } catch (Exception $e) { }

  if ($response->isSuccess()) {
    fn_echo('<meta http-equiv="Refresh" content="0;URL=' . $response->getRedirectUrl() . '" />');
  } else {
    $result = '<strong>'. __('payments.begateway.token_error') . '</strong><br>';
    $result.= __('payments.begateway.return_checkout', ['[return_url]' => $cancel_url]);
    $result.='<br><br><pre>' . $result->getMessage() . '</pre>';
    fn_echo($result);
  }
  exit;
}
?>