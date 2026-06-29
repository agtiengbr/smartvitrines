<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/config/config.inc.php';

$pk = getenv('SV_PUBLIC_KEY') ?: '';
$sk = getenv('SV_SECRET_KEY') ?: '';
$apiUrl = getenv('SV_API_URL') ?: '';

if ($pk === '' || $sk === '') {
    fwrite(STDERR, "SV_PUBLIC_KEY and SV_SECRET_KEY required\n");
    exit(1);
}

if ($apiUrl === '') {
    fwrite(STDERR, "SV_API_URL required (ex.: http://localhost:18080)\n");
    exit(1);
}

Configuration::updateValue('SMARTVITRINES_PUBLIC_KEY', $pk);
Configuration::updateValue('SMARTVITRINES_SECRET_KEY', $sk);
Configuration::updateValue('SMARTVITRINES_API_URL', rtrim($apiUrl, '/'));
Configuration::updateValue('SMARTVITRINES_SKU_FIELD', 'reference');
Configuration::updateValue('SMARTVITRINES_THEME_LAYOUT', 'classic');
Configuration::updateValue('SMARTVITRINES_PDP_LIMIT', '4');
Configuration::updateValue('SMARTVITRINES_CART_LIMIT', '4');

$module = Module::getInstanceByName('smartvitrines');
if ($module) {
    $module->registerHook('productFooter');
    $module->registerHook('shoppingCartExtra');
}

if (method_exists('Tools', 'clearCache')) {
    Tools::clearCache();
}
if (method_exists('Tools', 'clearSmartyCache')) {
    Tools::clearSmartyCache();
}

echo "configured api={$apiUrl} pk=" . substr($pk, 0, 12) . "...\n";
