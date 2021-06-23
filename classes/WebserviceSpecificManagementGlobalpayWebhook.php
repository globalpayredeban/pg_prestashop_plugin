<?php

class WebserviceSpecificManagementGlobalpayWebhook implements WebserviceSpecificManagementInterface

{
    /** @var WebserviceOutputBuilder */
    protected $objOutput;
    protected $output;

    /** @var WebserviceRequest */
    protected $wsObject;

    public function setUrlSegment($segments)
    {
        $this->urlSegment = $segments;
        return $this;
    }

    public function getUrlSegment()
    {
        return $this->urlSegment;
    }

    public function getWsObject()
    {
        return $this->wsObject;
    }

    public function getObjectOutput()
    {
        return $this->objOutput;
    }

    /**
     * This must be return a string with specific values as WebserviceRequest expects.
     *
     * @return string
     */
    public function getContent()
    {
        return $this->objOutput->getObjectRender()->overrideContent($this->output);
    }

    public function setWsObject(WebserviceRequestCore $obj)
    {
        $this->wsObject = $obj;
        return $this;
    }

    /**
     * @param WebserviceOutputBuilderCore $obj
     * @return WebserviceSpecificManagementInterface
     */
    public function setObjectOutput(WebserviceOutputBuilderCore $obj)
    {
        $this->objOutput = $obj;
        return $this;
    }

    /**
     * @throws PrestaShopException
     * @throws PrestaShopDatabaseException
     * @throws WebserviceException
     */
    public function manage()
    {
        $this->wsObject->setOutputEnabled(true);

        $requestBody      = file_get_contents('php://input');
        $requestBodyJs    = json_decode($requestBody, true);
        $transaction_id   = $requestBodyJs["transaction"]['id'];
        $status_detail    = $requestBodyJs["transaction"]['status_detail'] ?? 999;
        $dev_reference    = $requestBodyJs["transaction"]['dev_reference'];
        $pg_stoken        = $requestBodyJs["transaction"]['stoken'];
        $application_code = $requestBodyJs["transaction"]['application_code'];

        $orderId = Order::getIdByCartId($dev_reference);
        if (!$orderId)
        {
            throw new WebserviceException('Order not found', [1, 400]);
        }
        $order = new Order($orderId);
        $this->update_order_status($application_code, $order, $transaction_id, $pg_stoken, $status_detail);
    }

    /**
     * @throws PrestaShopException
     * @throws WebserviceException
     */
    private function update_order_status($application_code, $order, $transaction_id, $pg_stoken, $status_detail)
    {
        $codes_keys = [
            Configuration::get('app_code_client') => Configuration::get('app_key_client'),
            Configuration::get('app_code_server') => Configuration::get('app_key_server'),
        ];
        // TODO: Meter todo esto en una funcion validateStoken()
        $app_code = $application_code;
        $app_key = $codes_keys[$app_code];
        $user_id = $order->id_customer;
        $for_md5 = "{$transaction_id}_{$app_code}_{$user_id}_{$app_key}";
        $stoken = md5($for_md5);
        if ($stoken !== $pg_stoken) {
            throw new WebserviceException('Stoken invalid', [1, 401]);
        }
        $history = new OrderHistory();
        $history->id_order = $order->id;
        $status = $this->map_status((int)$status_detail);
        if ($order->current_state == $status)
        {
            throw new WebserviceException('Order already updated', [1, 200]);
        }
        $history->changeIdOrderState($status, $order->id);
        $history->save();
        $collection = OrderPayment::getByOrderReference($order->reference);
        foreach ($collection as $order_payment)
        {
            if ($order_payment->payment_method == FLAVOR.' Prestashop Plugin' or !$order_payment->payment_method) {
                $order_payment->transaction_id = $transaction_id;
                $order_payment->save();
            }
        }
        $this->objOutput->setStatus(200);
    }

    private function map_status($status_detail): int
    {
        $pg_status_ps = [
            0 => 10, // “Awaiting”
            3 => 2, // “Payment accepted”
            7 => 7, // “Refunded”
            8 => 13, // “Awaiting Cash On Delivery validation” (chargeback)
        ];
        return $pg_status_ps[$status_detail] ?? 8; // 8 => "Payment Error"
    }
}