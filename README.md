# Minimal Configuration
*<sitepackage>/Configuration/DceMigration.php*
```php
<?php


/**
 * @var \TYPO3\CMS\Core\Package\PackageManager $packageManager
 */
$packageManager = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Package\PackageManager::class);
$package = $packageManager->getPackage('<sitepackage>');

return [
    '<DCE IDENTIFIER>' => [
        'vendor' => '<VENDOR>',
        'identifier' => '<CONTENT BLOCKS IDENTIFIER>',
        'package' => $package,
    ],
    // ... your dce_elements
];

```