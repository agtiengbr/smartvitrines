<?php

declare(strict_types=1);

$configCandidates = [
    dirname(__DIR__, 3) . '/config/config.inc.php',
    dirname(__DIR__, 3) . '/public_html/config/config.inc.php',
];

foreach ($configCandidates as $configPath) {
    if (is_file($configPath)) {
        require $configPath;
        break;
    }
}

$idOrder = (int) Db::getInstance()->getValue('SELECT id_order FROM ' . _DB_PREFIX_ . 'orders ORDER BY id_order DESC');
$order = new Order($idOrder);
$customer = new Customer((int) $order->id_customer);

$link = Context::getContext()->link;
$url = $link->getPageLink(
    'order-confirmation',
    true,
    null,
    [
        'id_cart' => (int) $order->id_cart,
        'id_module' => (int) $order->module,
        'id_order' => (int) $order->id,
        'key' => $customer->secure_key,
    ]
);

echo "Order: {$idOrder}\n";
echo "URL: {$url}\n";
