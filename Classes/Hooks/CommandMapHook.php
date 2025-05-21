<?php

declare(strict_types=1);


namespace WEBcoast\DceToContentblocks\Hooks;


use TYPO3\CMS\Core\DataHandling\DataHandler;

class CommandMapHook
{
    /**
     * Replace "NEW..." ids with real IDs in the command map, e.g. when moving elements into newly created container elements.
     */
    public function processCmdmap_beforeStart(DataHandler $dataHandler)
    {
        foreach ($dataHandler->substNEWwithIDs as $newId => $realId) {
            foreach ($dataHandler->cmdmap as $table => $records) {
                foreach ($records as $id => $commands) {
                    foreach ($commands as $command => $value) {
                        if (is_array($value)) {
                            foreach ($value as $property => $propertyValue) {
                                if (is_array($propertyValue)) {
                                    foreach ($propertyValue as $subProperty => $subPropertyValue) {
                                        if ($subPropertyValue === $newId) {
                                            $dataHandler->cmdmap[$table][$id][$command][$property][$subProperty] = $realId;
                                        }
                                    }
                                } elseif ($propertyValue === $newId) {
                                    $dataHandler->cmdmap[$table][$id][$command][$property] = $realId;
                                }
                            }
                        } elseif (is_string($value) && $value === $newId) {
                            $dataHandler->cmdmap[$table][$id][$command] = $realId;
                        }
                    }
                }
            }
        }
    }
}
