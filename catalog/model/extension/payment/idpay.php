<?php
/**
 * IDPay payment gateway
 *
 * @developer JMDMahdi, vispa, mnbp1371
 * @publisher IDPay
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
class ModelExtensionPaymentIdpay extends Model
{
    public function getMethod($address)
    {
        $method_data = [
            'code' => 'idpay',
            'title' => $this->config->get('payment_idpay_title'),
            'terms' => '',
            'sort_order' => $this->config->get('payment_idpay_sort_order')
        ];
        $status = $this->config->get('payment_idpay_status') == true;
        return $status == true ? $method_data : [];
    }
}

?>
