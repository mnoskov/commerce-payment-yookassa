//<?php
/**
 * Payment Yandexkassa
 *
 * Yandex Kassa payments processing
 *
 * @category    plugin
 * @version     0.1.2
 * @author      mnoskov
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender
 * @internal    @properties &title=Название;text;Яндекс.Касса (Visa, MasterCard и прочее) &shop_id=Идентификатор магазина (shop_id);text;  &secret=Секретный ключ;text; &tax_system_code=Код системы налогообложения;list;Общая система налогообложения==1||Упрощенная (УСН, доходы)==2||Упрощенная (УСН, доходы минус расходы)==3||Единый налог на вмененный доход (ЕНВД)==4||Единый сельскохозяйственный налог (ЕСН)==5||Патентная система налогообложения==6;1 &vat_code=Код ставки НДС;list;Без НДС==1||НДС по ставке 0%==2||НДС по ставке 10%==3||НДС чека по ставке 20%==4||НДС чека по расчетной ставке 10/110==5||НДС чека по расчетной ставке 20/120==6;1 &processing_status_id=ID статуса В обработке (order.status.processing);text;2 &canceled_status_id=ID статуса Отменён (order.status.canceled);text;5 &debug=Отладка;list;Нет==0||Да==1;0
 * @internal    @modx_category Commerce
 * @internal    @disabled 1
 * @internal    @installset base
*/

if (empty($modx->commerce) && !defined('COMMERCE_INITIALIZED')) {
    return;
}

$isSelectedPayment = !empty($order['fields']['payment_method']) && $order['fields']['payment_method'] == 'yandexkassa';
$commerce = ci()->commerce;
$lang = $commerce->getUserLanguage('yandexkassa');

switch ($modx->event->name) {
    case 'OnRegisterPayments': {
        $class = new \Commerce\Payments\YandexkassaPayment($modx, $params);

        if (empty($params['title'])) {
            $params['title'] = $lang['yandexkassa.caption'];
        }

        $commerce->registerPayment('yandexkassa', $params['title'], $class);
        break;
    }

    case 'OnBeforeOrderSending': {
        if ($isSelectedPayment) {
            $FL->setPlaceholder('extra', $FL->getPlaceholder('extra', '') . $commerce->loadProcessor()->populateOrderPaymentLink());
        }

        break;
    }

    case 'OnManagerBeforeOrderRender': {
        if (isset($params['groups']['payment_delivery']) && $isSelectedPayment) {
            $params['groups']['payment_delivery']['fields']['payment_link'] = [
                'title'   => $lang['yandexkassa.link_caption'],
                'content' => function($data) use ($commerce) {
                    return $commerce->loadProcessor()->populateOrderPaymentLink('@CODE:<a href="[+link+]" target="_blank">[+link+]</a>');
                },
                'sort' => 50,
            ];
        }

        break;
    }
}
