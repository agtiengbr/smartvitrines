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

if (!class_exists('Configuration')) {
    fwrite(STDERR, "config not loaded\n");
    exit(1);
}

echo 'API URL: ' . Configuration::get('SMARTVITRINES_API_URL') . "\n";
echo 'Public key set: ' . (Configuration::get('SMARTVITRINES_PUBLIC_KEY') !== '' ? 'yes' : 'no') . "\n";

$module = Module::getInstanceByName('smartvitrines');
if (!$module) {
    echo "Module not found\n";
    exit(1);
}

$hooks = Hook::getHookModuleExecList('displayOrderConfirmation');
$found = false;
if (is_array($hooks)) {
    foreach ($hooks as $row) {
        if (($row['module'] ?? '') === 'smartvitrines') {
            $found = true;
            break;
        }
    }
}
echo 'On displayOrderConfirmation hook list: ' . ($found ? 'yes' : 'no') . "\n";
