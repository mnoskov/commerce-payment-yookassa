<?php

namespace Commerce\Payments;

class YandexkassaPayment extends Payment implements \Commerce\Interfaces\Payment
{
    protected $debug = false;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('yandexkassa');
        $this->debug = !empty($this->getSetting('debug'));
    }

    public function getMarkup()
    {
        $out = [];

        if (empty($this->getSetting('shop_id'))) {
            $out[] = $this->lang['yandexkassa.error_empty_shop_id'];
        }

        if (empty($this->getSetting('secret'))) {
            $out[] = $this->lang['yandexkassa.error_empty_secret'];
        }

        $out = implode('<br>', $out);

        if (!empty($out)) {
            $out = '<span class="error" style="color: red;">' . $out . '</span>';
        }

        return $out;
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->getOrder();
        $currency = ci()->currency->getCurrency($order['currency']);
        $payment = $this->createPayment($order['id'],
            ci()->currency->convertToDefault($order['amount'], $currency['code']));

        $data = [
            'amount'       => [
                'value'    => number_format($order['amount'], 2, '.', ''),
                'currency' => 'RUB',
            ],
            'description'  => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                'order_id'  => $order['id'],
                'site_name' => $this->modx->getConfig('site_name'),
            ]),
            'confirmation' => [
                'type'       => 'redirect',
                'return_url' => $this->modx->getConfig('site_url') . 'commerce/yandexkassa/payment-success',
            ],
            'metadata'     => [
                'order_id'     => $order['id'],
                'payment_id'   => $payment['id'],
                'payment_hash' => $payment['hash'],
            ],
            'capture'      => true,
        ];

        if (!empty($order['phone']) || !empty($order['email'])) {
            $receipt = ['items' => []];

            foreach ($processor->getCart()->getItems() as $item) {
                $receipt['items'][] = [
                    'description' => mb_substr($item['name'], 0, 64),
                    'vat_code'    => $this->getSetting('vat_code'),
                    'quantity'    => $item['count'],
                    'amount'      => [
                        'value'    => number_format($item['price'] * $item['count'], 2, '.', ''),
                        'currency' => 'RUB',
                    ],
                ];
            }

            if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
                $receipt['email'] = $order['email'];
            }
            if (!empty($order['phone'])) {
                $receipt['phone'] = substr(preg_replace('/[^\d]+/', '', $order['phone']), 0, 15);
            }
            $receipt['tax_system_code'] = $this->getSetting('tax_system_code');

            $data['receipt'] = $receipt;
        }

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Request data: ' . print_r($data, true),
                'Commerce YandexKassa Payment Debug: payment start');
        }

        if (($result = $this->request('payments', $data)) === false) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Link is not received', 'Commerce YandexKassa Payment');
            }
            $docid = $this->modx->commerce->getSetting('payment_failed_page_id', $this->modx->getConfig('site_start'));
            $url = $this->modx->makeUrl($docid);
            
            return $url;
        }

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Response data: ' . print_r($result, true),
                'Commerce YandexKassa Payment Debug: payment start');
        }

        return $result['confirmation']['confirmation_url'];
    }

    public function handleCallback()
    {
        $processing_sid = !empty($this->getSetting('processing_status_id')) ? $this->getSetting('processing_status_id') : 2;
        $canceled_sid = !empty($this->getSetting('canceled_status_id')) ? $this->getSetting('canceled_status_id') : 5;
        $source = file_get_contents('php://input');

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Callback data: ' . print_r($source, true),
                'Commerce YandexKassa Payment Debug: callback start');
        }

        if (empty($source)) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Empty data', 'Commerce YandexKassa Payment');
            }

            return false;
        }

        $data = json_decode($source, true);

        if (empty($data) || empty($data['object'])) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Invalid json', 'Commerce YandexKassa Payment');
            }

            return false;
        }

        $payment = $data['object'];
        $processor = $this->modx->commerce->loadProcessor();
        switch ($payment['status']) {
            case 'succeeded':
            {
                if ($payment['paid'] === true) {
                    try {
                        $processor->processPayment($payment['metadata']['payment_id'],
                            floatval($payment['amount']['value']));
                        // $this->modx->invokeEvent('OnPageNotFound', ['callback' => &$payment]); // если необходимо обработать возвращаемые данные, н-р, отправить API-запрос в CRM
                    } catch (\Exception $e) {
                        if ($this->debug) {
                            $this->modx->logEvent(0, 3, 'JSON processing failed: ' . $e->getMessage(),
                                'Commerce YandexKassa Payment');
                        }

                        return false;
                    }
                }
                break;
            }
            case 'waiting_for_capture':
            {
                $this->request('payments/' . $payment['id'] . '/capture', [
                    'amount' => $payment['amount'],
                ]);
                try {
                    $processor->changeStatus($payment['metadata']['order_id'], $processing_sid,
                        $this->lang['yandexkassa.waiting_for_capture']);
                } catch (\Exception $e) {
                    if ($this->debug) {
                        $this->modx->logEvent(0, 3, 'JSON processing failed: ' . $e->getMessage(),
                            'Commerce YandexKassa Payment (changeStatus)');
                    }

                    return false;
                }
                break;
            }
            case 'canceled':
            {
                $_party = $payment['cancellation_details']['party'];
                $_reason = $payment['cancellation_details']['reason'];
                $reason_url = "https://kassa.yandex.ru/developers/payments/declined-payments#cancellation-details-reason";
                if ($this->debug) {
                    $this->modx->logEvent(0, 1, 'Initiator: ' . $_party . ', Reason: ' . $_reason,
                        'Commerce YandexKassa Payment (payment canceled)');
                }
                try {
                    $processor->changeStatus($payment['metadata']['order_id'], $canceled_sid,
                        $this->lang['yandexkassa.canceled_party'] . $this->lang['yandexkassa.' . $_party] . '. ' . $this->lang['yandexkassa.canceled_reason'] . $_reason . '. ' . $this->lang['yandexkassa.canceled_more'] . $reason_url,
                        true);
                } catch (\Exception $e) {
                    if ($this->debug) {
                        $this->modx->logEvent(0, 3, 'JSON processing failed: ' . $e->getMessage(),
                            'Commerce YandexKassa Payment (payment canceled error)');
                    }

                    return false;
                }
                break;
            }
        }

        return true;
    }

    public function getRequestPaymentHash()
    {
        if (!empty($_SERVER['HTTP_REFERER']) && is_scalar($_SERVER['HTTP_REFERER'])) {
            $url = parse_url($_SERVER['HTTP_REFERER']);

            if (isset($url['query']) && isset($url['host']) && $url['host'] == 'money.yandex.ru') {
                parse_str($url['query'], $query);

                if (isset($query['orderId']) && is_scalar($query['orderId'])) {
                    $response = $this->request('payments/' . $query['orderId']);

                    if (!empty($response) && !empty($response['metadata']['payment_hash'])) {
                        return $response['metadata']['payment_hash'];
                    }
                }
            }
        }

        return null;
    }

    protected function request($method, $data = [])
    {
        $url = 'https://payment.yandex.net/api/v3/';
        $shop_id = $this->getSetting('shop_id');
        $secret = $this->getSetting('secret');

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url . $method,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => "$shop_id:$secret",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE        => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Idempotence-Key: ' . uniqid(),
                'Content-Type: application/json',
                'Cache-Control: no-cache',
                'charset="utf-8"',
            ],
        ]);

        if (!empty($data)) {
            curl_setopt_array($curl, [
                CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
                CURLOPT_POST       => true,
            ]);
        }

        $result = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $result = json_decode($result, true);

        if ($code != 200) {
            if ($this->debug) {
                if (isset($result['type']) && $result['type'] == 'error') {
                    $msg = 'Server return error:<br>' . print_r($result, true);
                } else {
                    $msg = 'Server is not responding';
                }

                $this->modx->logEvent(0, 3, $msg, 'Commerce YandexKassa Payment');
            }

            return false;
        }

        return $result;
    }
}
