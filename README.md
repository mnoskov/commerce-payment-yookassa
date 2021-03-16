# Платежный плагин ЮKassa для Evolution CMS Commerce

### Информация
Поддерживает проведение платежей и отправку чеков в онлайн-кассу в соответсвии с 54-ФЗ.
Протестирована работа в режиме автоплатежа с подключённой онлайн-кассой Кит Инвест + Яндекс.ОФД

### Порядок настройки плагина
- В Анкете ЮKassa можно указать "API (для самописных сайтов)", либо "Платежный модуль" и в дальнейшем выбрать MODX ShopKeeper2 (главное не забыть изменить адрес для HTTP-уведомлений)
- Выпустить [Секретный ключ](https://yookassa.ru/my/merchant/integration/api-keys) в соответствующем разделе ЮKassa
- В ЮKassa в разделе [HTTP-уведомления](https://yookassa.ru/my/merchant/integration/http-notifications):
 - указать/проверить **URL для уведомлений:** `https://<site_url>/commerce/yandexkassa/payment-process`
 - в подразделе **О каких событиях уведомлять** включить все, кроме `refund.succeeded` (оно не обрабатывает плагином)
- Заполнить настройки в конфигурации плагина **Payment Yandexkassa**

### Полезные ссылки
- [Основной модуль Evolution CMS Commerce](https://github.com/mnoskov/commerce)
- [Документация для разработчиков ЮKassa](https://yookassa.ru/developers/payments/quick-start)
- [Справочник API ЮKassa](https://yookassa.ru/developers/api)
- [Инструкция по первому подключению Яндекс.Кассы для другой CMS](https://help-ru.creatium.io/ru/articles/2241544-%D1%8F%D0%BD%D0%B4%D0%B5%D0%BA%D1%81-%D0%BA%D0%B0%D1%81%D1%81%D0%B0) (подробно расписано для новичков)
