<?php

/**
 * One-off: docker exec ps_modulos_web php modules/smartvitrines/scripts/register_hooks.php
 */
$configCandidates = [
    dirname(__DIR__, 3) . '/config/config.inc.php',
    dirname(__DIR__, 3) . '/public_html/config/config.inc.php',
    dirname(__DIR__, 4) . '/shop/config/config.inc.php',
];

$configLoaded = false;
foreach ($configCandidates as $configPath) {
    if (is_file($configPath)) {
        require $configPath;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    fwrite(STDERR, "PrestaShop config.inc.php not found\n");
    exit(1);
}

$module = Module::getInstanceByName('smartvitrines');
if (!$module) {
    fwrite(STDERR, "Module smartvitrines not found\n");
    exit(1);
}

$register = ['displayOrderConfirmation', 'displayHeader'];
if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
    $register[] = 'displayFooterProduct';
    $register[] = 'displayShoppingCart';
    $register[] = 'actionCartUpdateQuantityBefore';
} else {
    $register[] = 'productFooter';
    $register[] = 'shoppingCartExtra';
    $register[] = 'actionBeforeCartUpdateQty';
}

$unregister = ['actionValidateOrder'];

foreach ($register as $hook) {
    echo ($module->registerHook($hook) ? 'OK' : 'FAIL') . " registerHook({$hook})\n";
}

foreach ($unregister as $hook) {
    echo ($module->unregisterHook($hook) ? 'OK' : 'FAIL') . " unregisterHook({$hook})\n";
}

echo "Module version: {$module->version}\n";
echo 'PS version: ' . _PS_VERSION_ . "\n";
