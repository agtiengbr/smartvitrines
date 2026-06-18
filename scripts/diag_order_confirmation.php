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

$module = Module::getInstanceByName('smartvitrines');
if (!$module) {
    fwrite(STDERR, "module missing\n");
    exit(1);
}

$idOrder = (int) Db::getInstance()->getValue('SELECT id_order FROM ' . _DB_PREFIX_ . 'orders ORDER BY id_order DESC');
if ($idOrder <= 0) {
    fwrite(STDERR, "no orders\n");
    exit(1);
}

$order = new Order($idOrder);
echo "Latest order id: {$idOrder}\n";

$html = $module->hookDisplayOrderConfirmation(['order' => $order]);
echo "Hook HTML length: " . strlen($html) . "\n";
echo substr($html, 0, 400) . (strlen($html) > 400 ? "\n...\n" : "\n");

$header = $module->hookDisplayHeader([]);
echo "Header has sv.min.js: " . (str_contains($header, 'sv.min.js') ? 'yes' : 'no') . "\n";
