<?php

declare(strict_types=1);

use WEBcoast\DceToContentblocks\Hooks\CommandMapHook;

if (!defined('TYPO3')) {
    die('Access denied.');
}

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][1747215926] = CommandMapHook::class;
