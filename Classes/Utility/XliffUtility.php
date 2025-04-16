<?php

declare(strict_types=1);

namespace WEBcoast\DceToContentblocks\Utility;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class XliffUtility
{
    public static function addLabelsToFile(string $file, array $labels, string $productName): void
    {
        if (!file_exists($file)) {
            self::createNewXliffFile($file, $productName);
        }

        $content = GeneralUtility::getUrl($file);
        $content = self::addLabels($content, $labels);

        GeneralUtility::writeFile($file, $content);
    }

    protected static function createNewXliffFile(string $file, string $productName): void
    {
        $baseName = basename($file);
        $date = (new \DateTime())->format(\DateTimeInterface::RFC3339);
        $content = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
                <file datatype="plaintext" original="{$baseName}" source-language="en" date="{$date}" product-name="{$productName}">
                    <header/>
                    <body>
                    </body>
                </file>
            </xliff>
            XML;

        GeneralUtility::writeFile($file, $content);
    }

    protected static function addLabels(string $content, array $labels)
    {
        // Detect the type of indentation used in the file
        $indentation = preg_match('/^(\s+)/m', $content, $matches) ? $matches[1] : "\t";
        $labelsString = '';
        foreach ($labels as $key => $value) {
            $labelsString .= <<<LLL
                $indentation$indentation$indentation<trans-unit id="{$key}">
                $indentation$indentation$indentation$indentation<source>{$value}</source>
                $indentation$indentation$indentation</trans-unit>

                LLL;
        }

        // Find the closing body tag and insert the labels before it
        return preg_replace(
            '/(<\/body>)/',
            PHP_EOL . $labelsString . $indentation . $indentation . '$1',
            $content
        );
    }
}
