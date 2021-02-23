<?php

$requestBody = file_get_contents('php://input');
$requestBodyJs = json_decode($requestBody, true);

$status = $requestBodyJs["transaction"]['status'];
$status_detail = $requestBodyJs["transaction"]['status_detail'] ?? 999;
$transaction_id = $requestBodyJs["transaction"]['id'];
$dev_reference = $requestBodyJs["transaction"]['dev_reference'];
$pg_stoken = $requestBodyJs["transaction"]['stoken'];
$application_code = $requestBodyJs["transaction"]['application_code'];

$orderId = Order::getIdByCartId($dev_reference);
if (!$orderId)
{
    header("HTTP/1.0 409 order not found");
}
$order = new Order($orderId);
// TODO: Crear un "get_payment_id()" para no copiar y pegar código
$collection = OrderPayment::getByOrderReference($order->reference);
if (count($collection) > 0)
{
    foreach ($collection as $order_payment)
    {
        if ($order_payment->payment_method == FLAVOR . ' Prestashop Plugin')
        {
            $codes_keys = [
                Configuration::get('app_code_client') => Configuration::get('app_key_client'),
                Configuration::get('app_code_server') => Configuration::get('app_key_server'),
            ];
            // TODO: Meter todo esto en una funcion validateStoken()
            $transaction_id = $order_payment->transaction_id;
            $app_code = $application_code;
            $app_key = $codes_keys[$app_code];
            $user_id = $order->id_customer;
            $for_md5 = "{$transaction_id}_{$app_code}_{$user_id}_{$app_key}";
            $stoken = md5($for_md5);
            if ($stoken !== $pg_stoken) {
                header("HTTP/1.0 401 stoken invalid");
            }
            $history = new OrderHistory();
            $history->id_order = (int)$order->id;
            $status = map_status((int)$status_detail);
            $history->changeIdOrderState($status, (int)($order->id));
            $history->save();
            header("HTTP/1.0 201 order {$order->id} updated to {$status}");
        }
    }
}

function map_status($status_detail): int
{
    $pg_status_ps = [
        0 => 3, // “Processing in progress”
        3 => 2, // “Payment accepted”
        7 => 7, // “Refunded”
        8 => 13, // “Awaiting Cash On Delivery validation” (chargeback)
    ];
    return $pg_status_ps[$status_detail] ?? 8; // 8 => "Payment Error"
}
