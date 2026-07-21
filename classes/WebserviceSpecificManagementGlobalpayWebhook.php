<?php

class WebserviceSpecificManagementGlobalpayWebhook implements WebserviceSpecificManagementInterface

{
    /** @var WebserviceOutputBuilder */
    protected $objOutput;
    protected $output;
    protected $urlSegment;

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

        $requestBody   = file_get_contents('php://input');
        $requestBodyJs = json_decode($requestBody, true);
        $transaction   = $requestBodyJs['transaction'] ?? null;

        if (!is_array($transaction)) {
            throw new WebserviceException('Invalid payload', [1, 400]);
        }

        $transaction_id   = $transaction['id'] ?? null;
        $status_detail    = $transaction['status_detail'] ?? 999;
        $dev_reference    = $transaction['dev_reference'] ?? null;
        $pg_stoken        = $transaction['stoken'] ?? null;
        $application_code = $transaction['application_code'] ?? null;

        if (!$transaction_id || !$dev_reference || !$pg_stoken || !$application_code) {
            throw new WebserviceException('Missing required fields', [1, 400]);
        }

        $orderId = Order::getIdByCartId($dev_reference);
        if (!$orderId) {
            throw new WebserviceException('Order not found', [1, 400]);
        }

        $order = new Order($orderId);

        if ((int) $status_detail === 3) {
            $transaction_amount = (float) ($transaction['amount'] ?? 0);
            if ($transaction_amount <= 0 || abs($transaction_amount - (float) $order->total_paid) > 0.01) {
                throw new WebserviceException('Amount mismatch', [1, 400]);
            }
        } elseif ($this->isRefundStatus((int) $status_detail)) {
            $transaction_amount = (float) ($transaction['amount'] ?? 0);
            $this->validateRefundAmount($order, $transaction_id, $transaction_amount);
        }

        $this->update_order_status($application_code, $order, $transaction_id, $pg_stoken, $status_detail);
    }

    /**
     * @throws PrestaShopException
     * @throws WebserviceException
     */
    private function update_order_status($application_code, $order, $transaction_id, $pg_stoken, $status_detail)
    {
        $app_code = (string) Configuration::get('app_code_server');
        $app_key = (string) Configuration::get('app_key_server');

        if ($app_code === '' || $app_key === '') {
            throw new WebserviceException('Webhook credentials not configured', [1, 500]);
        }

        if ($application_code !== $app_code) {
            throw new WebserviceException('Application code invalid', [1, 401]);
        }

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
            if ($order_payment->payment_method == GP_FLAVOR.' Prestashop Plugin' or !$order_payment->payment_method) {
                $order_payment->transaction_id = $transaction_id;
                $order_payment->save();
            }
        }
        $this->objOutput->setStatus(200);
    }

    private function validateRefundAmount(Order $order, string $transaction_id, float $transaction_amount): void
    {
        if ($transaction_amount <= 0) {
            throw new WebserviceException('Refund amount invalid', [1, 400]);
        }

        if ($transaction_amount - (float) $order->total_paid > 0.01) {
            throw new WebserviceException('Refund amount mismatch', [1, 400]);
        }

        $sql = 'SELECT 1
            FROM `' . _DB_PREFIX_ . GP_REFUND_GUARD_TABLE . '`
            WHERE `id_order` = ' . (int) $order->id . '
              AND `transaction_id` = "' . pSQL($transaction_id) . '"
              AND ABS(`amount` - ' . (float) $transaction_amount . ') <= 0.01
              AND `status` IN ("pending", "processed")
            LIMIT 1';

        if (!Db::getInstance()->getValue($sql)) {
            throw new WebserviceException('Refund transaction not recognized', [1, 400]);
        }
    }

    private function map_status($status_detail): int
    {
        $pg_status_ps = [
            0 => $this->getOrderStateId('PS_OS_PREPARATION', 10), // Awaiting / pending
            3 => $this->getOrderStateId('PS_OS_PAYMENT', 2), // Payment accepted
            7 => $this->getOrderStateId('PS_OS_REFUND', 7), // Refunded
            34 => $this->getOrderStateId('PS_OS_REFUND', 7), // Partial refund
            8 => $this->getOrderStateId('PS_OS_ERROR', 8), // Chargeback / error
        ];
        return $pg_status_ps[$status_detail] ?? $this->getOrderStateId('PS_OS_ERROR', 8);
    }

    private function isRefundStatus(int $status_detail): bool
    {
        return in_array($status_detail, [7, 34], true);
    }

    private function getOrderStateId(string $configKey, int $fallback): int
    {
        $stateId = (int) Configuration::get($configKey);
        return $stateId > 0 ? $stateId : $fallback;
    }
}