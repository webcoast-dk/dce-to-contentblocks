<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Utility;


use TYPO3\CMS\Core\Package\PackageManager;

readonly class ConfigurationUtility
{
    public function __construct(protected PackageManager $packageManager) {}

    public function buildMigrationConfiguration(): array
    {
        $configuration = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $configurationPath = $package->getPackagePath() . 'Configuration/DceMigration.php';
            if (file_exists($configurationPath)) {
                $importedConfiguration = require $configurationPath;
                foreach ($importedConfiguration as $dceIdentifier => $migrationInstructions) {
                    $configuration[$dceIdentifier]['package'] = $package;
                }
                $configuration = array_replace_recursive($configuration, require $configurationPath);
            }
        }

        return $configuration;
    }
}
