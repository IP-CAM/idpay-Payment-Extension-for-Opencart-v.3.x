<?php

/**
 * IDPay payment gateway
 *
 * @developer JMDMahdi,vispa, mnbp1371
 * @publisher IDPay
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
class ControllerExtensionPaymentIdpay extends Controller
{

    /**
     * @param $id
     * @return string
     */
    public function generateString($id)
    {
        return 'IDPay Transaction ID: ' . $id;
    }

    /**
     * @return mixed
     */
    public function index()
    {
        $this->load->language('extension/payment/idpay');

        $data['text_connect'] = $this->language->get('text_connect');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['text_wait'] = $this->language->get('text_wait');
        $data['button_confirm'] = $this->language->get('button_confirm');

        return $this->load->view('extension/payment/idpay', $data);
    }

    /**
     *
     */
    public function confirm()
    {
        $this->doPayment();
    }

    public function doPayment()
    {
        $this->load->language('extension/payment/idpay');

        $this->load->model('checkout/order');

        /** @var \ModelCheckoutOrder $model */
        $model = $this->model_checkout_order;

        $order_id = $this->session->data['order_id'];
        $order_info = $model->getOrder($order_id);

        $data['return'] = $this->url->link('checkout/success', '', true);
        $data['cancel_return'] = $this->url->link('checkout/payment', '', true);
        $data['back'] = $this->url->link('checkout/payment', '', true);
        $data['order_id'] = $this->session->data['order_id'];

        $api_key = $this->config->get('payment_idpay_api_key');
        $sandbox = $this->config->get('payment_idpay_sandbox') == 'yes' ? 'true' : 'false';
        $amount = $this->correctAmount($order_info);

        $desc = $this->language->get('text_order_no') . $order_info['order_id'];
        $callback = $this->url->link('extension/payment/idpay/callback', '', true);

        if (empty($amount)) {
            $json['error'] = 'واحد پول انتخاب شده پشتیبانی نمی شود.';
        }
        // Customer information
        $name = $order_info['firstname'] . ' ' . $order_info['lastname'];
        $mail = $order_info['email'];
        $phone = $order_info['telephone'];

        $idpay_data = array(
            'order_id' => $order_id,
            'amount' => $amount,
            'name' => $name,
            'phone' => $phone,
            'mail' => $mail,
            'desc' => $desc,
            'callback' => $callback,
        );

        $ch = curl_init('https://api.idpay.ir/v1.1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($idpay_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            // Set Order status id to 10 (Failed) and add a history.
            $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
            $model->addOrderHistory($order_id, 10, $msg, true);
            $json['error'] = $msg;
        } else {
            // Add a specific history to the order with order status 1 (Pending);
            $model->addOrderHistory($order_id, 1, $this->generateString($result->id), false);
            $model->addOrderHistory($order_id, 1, 'در حال هدایت به درگاه پرداخت آیدی پی', false);
            $data['action'] = $result->link;
            $json['success'] = $data['action'];
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }


    public function isNotDoubleSpending($orderId,$transactionId){
        $sql = $this->db->query('SELECT `comment`  FROM ' . DB_PREFIX . 'order_history WHERE order_id = ' . $orderId . ' AND `comment` LIKE "' . $this->generateString($transactionId) . '"');
       return  count($sql->row) != 0;
    }

    /**
     * http request callback
     */
    public function callback()
    {
        $checkTransactionByIdpay = $this->request->request['route'] ;
        if (strpos($checkTransactionByIdpay,'idpay') !== false) {
            $method = !empty($this->request->server['REQUEST_METHOD']) ? strtolower($this->request->server['REQUEST_METHOD']) : die;
            $status = empty($this->request->{$method}['status']) ? NULL : $this->request->{$method}['status'];
            $track_id = empty($this->request->{$method}['track_id']) ? NULL : $this->request->{$method}['track_id'];
            $id = empty($this->request->{$method}['id']) ? NULL : $this->request->{$method}['id'];
            $order_id = empty($this->request->{$method}['order_id']) ? NULL : $this->request->{$method}['order_id'];

            $this->load->language('extension/payment/idpay');

            $this->document->setTitle($this->config->get('payment_idpay_title'));

            $data['heading_title'] = $this->config->get('payment_idpay_title');
            $data['peyment_result'] = "";

            $data['breadcrumbs'] = array();
            $data['breadcrumbs'][] = array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/home', '', true)
            );
            $data['breadcrumbs'][] = array(
                'text' => $this->config->get('payment_idpay_title'),
                'href' => $this->url->link('extension/payment/idpay/callback', '', true)
            );

                $this->load->model('checkout/order');

                /** @var  \ModelCheckoutOrder $model */
                $model = $this->model_checkout_order;

                $order_info = $model->getOrder($order_id);

                if (!$order_info) {
                    $comment = $this->idpay_get_failed_message($track_id, $order_id);
                    // Set Order status id to 10 (Failed) and add a history.
                    $model->addOrderHistory($order_id, 10, $comment, true);
                    $data['peyment_result'] = $comment;
                    $data['button_continue'] = $this->language->get('button_view_cart');
                    $data['continue'] = $this->url->link('checkout/cart');
                } else {
                    if ($status != 10) {
                        $comment = $this->idpay_get_failed_message($track_id, $order_id, $status);
                        // Set Order status id to 10 (Failed) and add a history.
                        $model->addOrderHistory($order_id, 10, $this->otherStatusMessages($status), true);
                        $data['peyment_result'] = $comment;
                        $data['button_continue'] = $this->language->get('button_view_cart');
                        $data['continue'] = $this->url->link('checkout/cart');

                    } else {

                    if ($this->isNotDoubleSpending($order_id,$id) == false) {
                        $comment = $this->idpay_get_failed_message($track_id, $order_id, 0);
                        $model->addOrderHistory($order_id, 10, $this->otherStatusMessages($status), true);
                        $data['peyment_result'] = $comment;
                        $data['button_continue'] = $this->language->get('button_view_cart');
                        $data['continue'] = $this->url->link('checkout/cart');
                    }

                        $amount = $this->correctAmount($order_info);
                        $api_key = $this->config->get('payment_idpay_api_key');
                        $sandbox = $this->config->get('payment_idpay_sandbox') == 'yes' ? 'true' : 'false';

                        $idpay_data = array(
                            'id' => $id,
                            'order_id' => $order_id,
                        );

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($idpay_data));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            'Content-Type: application/json',
                            'X-API-KEY:' . $api_key,
                            'X-SANDBOX:' . $sandbox,
                        ));

                        $result = curl_exec($ch);
                        $result = json_decode($result);
                        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($http_status != 200) {
                            $comment = sprintf('خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                            // Set Order status id to 10 (Failed) and add a history.
                            $model->addOrderHistory($order_id, 10, $comment, true);
                            $data['peyment_result'] = $comment;
                            $data['button_continue'] = $this->language->get('button_view_cart');
                            $data['continue'] = $this->url->link('checkout/cart');
                        } else {
                            $verify_status = empty($result->status) ? NULL : $result->status;
                            $verify_track_id = empty($result->track_id) ? NULL : $result->track_id;
                            $verify_order_id = empty($result->order_id) ? NULL : $result->order_id;
                            $verify_amount = empty($result->amount) ? NULL : $result->amount;



                            if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount) || $verify_amount != $amount || $verify_status < 100) {
                                $comment = $this->idpay_get_failed_message($verify_track_id, $verify_order_id);
                                // Set Order status id to 10 (Failed) and add a history.
                                $model->addOrderHistory($order_id, 10, $comment, true);
                                $data['peyment_result'] = $comment;
                                $data['button_continue'] = $this->language->get('button_view_cart');
                                $data['continue'] = $this->url->link('checkout/cart');

                            } elseif ($order_id !== $result->order_id) {
                                $comment = $this->idpay_get_failed_message($track_id, $order_id, 0);
                                $model->addOrderHistory($order_id, 10, $this->otherStatusMessages($status), true);
                                $data['peyment_result'] = $comment;
                                $data['button_continue'] = $this->language->get('button_view_cart');
                                $data['continue'] = $this->url->link('checkout/cart');
                            } else {

                                $comment = $this->idpay_get_success_message($verify_track_id, $verify_order_id);
                                $config_successful_payment_status = $this->config->get('payment_idpay_order_status_id');
                                // Set Order status id to the configured status id and add a history.
                                $model->addOrderHistory($verify_order_id, $config_successful_payment_status, $comment, true);
                                // Add another history.
                                $comment2 = 'status: ' . $result->status . ' - track id: ' . $result->track_id . ' - card no: ' . $result->payment->card_no . ' - hashed card no: ' . $result->payment->hashed_card_no;
                                $model->addOrderHistory($verify_order_id, $config_successful_payment_status, $comment2, true);
                                $data['peyment_result'] = $comment;
                                $data['button_continue'] = $this->language->get('button_complete');
                                $data['continue'] = $this->url->link('checkout/success');
                            }
                        }
                    }
                }

            $data['column_left'] = $this->load->controller('common/column_left');
            $data['column_right'] = $this->load->controller('common/column_right');
            $data['content_top'] = $this->load->controller('common/content_top');
            $data['content_bottom'] = $this->load->controller('common/content_bottom');
            $data['footer'] = $this->load->controller('common/footer');
            $data['header'] = $this->load->controller('common/header');
            $this->response->setOutput($this->load->view('extension/payment/idpay_confirm', $data));

        }
    }

    /**
     * @param $order_info
     * @return int
     */
    private function correctAmount($order_info)
    {
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $amount = round($amount);
        $amount = $this->currency->convert($amount, $order_info['currency_code'], "RLS");
        return (int)$amount;
    }


    /**
     * @param $track_id
     * @param $order_id
     * @return mixed
     */
    public function idpay_get_success_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->config->get('payment_idpay_success_massage'));
    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return string
     */
    public function idpay_get_failed_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->config->get('payment_idpay_failed_massage')) . "<br>" . "$msg";
    }

    /**
     * @param $msgNumber
     * @get status from $_POST['status]
     * @return string
     */
    public function otherStatusMessages($msgNumber = null)
    {

        switch ($msgNumber) {
            case "1":
                $msg = "پرداخت انجام نشده است";
                break;
            case "2":
                $msg = "پرداخت ناموفق بوده است";
                break;
            case "3":
                $msg = "خطا رخ داده است";
                break;
            case "4":
                $msg = "بلوکه شده";
                break;
            case "5":
                $msg = "برگشت به پرداخت کننده";
                break;
            case "6":
                $msg = "برگشت خورده سیستمی";
                break;
            case "7":
                $msg = "انصراف از پرداخت";
                break;
            case "8":
                $msg = "به درگاه پرداخت منتقل شد";
                break;
            case "10":
                $msg = "در انتظار تایید پرداخت";
                break;
            case "100":
                $msg = "پرداخت تایید شده است";
                break;
            case "101":
                $msg = "پرداخت قبلا تایید شده است";
                break;
            case "200":
                $msg = "به دریافت کننده واریز شد";
                break;
            case "0":
                $msg = "سواستفاده از تراکنش قبلی";
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }

        return $msg . ' -وضعیت: ' . "$msgNumber";
    }

}

?>
