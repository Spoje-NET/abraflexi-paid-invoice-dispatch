<?php
/**
 * Debian autoloader for abraflexi-paid-invoice-dispatch
 */

// Dependencies
require_once '/usr/share/php/AbraFlexi/autoload.php';

// PSR-4 for this package (SpojeNet\AbraFlexiPaidInvoiceDispatch)
spl_autoload_register(function (string $class): void {
    $prefix = 'SpojeNet\\AbraFlexiPaidInvoiceDispatch\\';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

require_once '/usr/share/php/Composer/InstalledVersions.php';

(function (): void {
    $versions = [];
    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }
    $name    = 'unknown';
    $version = '0.0.0';
    $versions[$name] = ['pretty_version' => $version, 'version' => $version,
        'reference' => null, 'type' => 'library', 'install_path' => __DIR__,
        'aliases' => [], 'dev_requirement' => false];
    \Composer\InstalledVersions::reload([
        'root' => ['name' => $name, 'pretty_version' => $version, 'version' => $version,
            'reference' => null, 'type' => 'project', 'install_path' => __DIR__,
            'aliases' => [], 'dev' => false],
        'versions' => $versions,
    ]);
})();
