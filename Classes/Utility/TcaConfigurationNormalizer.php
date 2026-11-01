<?php

declare(strict_types = 1);

namespace T3\Dce\Utility;

/*  | This extension is made with love for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2012-2026 Armin Vieweg <armin@v.ieweg.de>
 */

/**
 * Normalizes DCE field configurations to the current TCA syntax.
 *
 * Stored field configurations may still use syntax that FlexFormTools
 * migrates on the fly, which is deprecated:
 *
 * - "required" inside the eval list instead of required = 1
 * - renderType="inputDateTime" instead of type="datetime"
 * - items defined as numeric numIndex arrays instead of named keys
 */
final class TcaConfigurationNormalizer
{
    public static function normalizeXml(string $configuration): string
    {
        $document = new \DOMDocument();
        if (!@$document->loadXML('<root>' . $configuration . '</root>', LIBXML_NONET)) {
            return $configuration;
        }
        $config = self::firstChild($document->documentElement, 'config');
        if (!$config instanceof \DOMElement) {
            return $configuration;
        }

        self::normalizeConfigElement($config);

        $normalized = '';
        foreach ($document->documentElement->childNodes as $childNode) {
            $normalized .= $document->saveXML($childNode);
        }

        return trim($normalized);
    }

    public static function normalizeConfigElement(\DOMElement $config): void
    {
        self::migrateEval($config);
        self::migrateDateTimeType($config);
        self::migrateItems($config);
    }

    private static function migrateEval(\DOMElement $config): void
    {
        $eval = self::firstChild($config, 'eval');
        if (!$eval instanceof \DOMElement) {
            return;
        }
        $tokens = array_filter(array_map('trim', explode(',', $eval->textContent)));
        if (!in_array('required', $tokens, true)) {
            return;
        }
        $tokens = array_values(array_diff($tokens, ['required']));

        if ($tokens === []) {
            $config->removeChild($eval);
        } else {
            $eval->nodeValue = implode(',', $tokens);
        }

        if (!self::firstChild($config, 'required') instanceof \DOMElement) {
            $config->appendChild($config->ownerDocument->createElement('required', '1'));
        }
    }

    private static function migrateDateTimeType(\DOMElement $config): void
    {
        $type = self::firstChild($config, 'type');
        $renderType = self::firstChild($config, 'renderType');
        if (!$type instanceof \DOMElement
            || (string)$type->textContent !== 'input'
            || !$renderType instanceof \DOMElement
            || (string)$renderType->textContent !== 'inputDateTime'
        ) {
            return;
        }
        $type->nodeValue = 'datetime';
        $config->removeChild($renderType);

        $eval = self::firstChild($config, 'eval');
        if ($eval instanceof \DOMElement) {
            $config->removeChild($eval);
        }
    }

    private static function migrateItems(\DOMElement $config): void
    {
        $items = self::firstChild($config, 'items');
        if (!$items instanceof \DOMElement) {
            return;
        }
        $itemMapping = [0 => 'label', 1 => 'value', 2 => 'icon', 3 => 'group', 4 => 'description'];
        foreach (iterator_to_array($items->childNodes) as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }
            $legacyValues = [];
            $invertStateDisplay = false;
            foreach ($item->childNodes as $value) {
                if (!$value instanceof \DOMElement || $value->tagName !== 'numIndex') {
                    continue;
                }
                if ($value->getAttribute('index') === 'invertStateDisplay') {
                    $invertStateDisplay = true;
                    continue;
                }
                $legacyValues[(int)$value->getAttribute('index')] = $value->textContent;
            }
            if ($legacyValues === [] && !$invertStateDisplay) {
                continue;
            }
            $newItem = $items->ownerDocument->createElement('numIndex');
            $newItem->setAttribute('index', $item->getAttribute('index'));
            $newItem->setAttribute('type', 'array');
            foreach ($legacyValues as $index => $value) {
                if (!isset($itemMapping[$index])) {
                    continue;
                }
                $newValue = $items->ownerDocument->createElement($itemMapping[$index]);
                $newValue->appendChild($items->ownerDocument->createTextNode($value));
                $newItem->appendChild($newValue);
            }
            if ($invertStateDisplay) {
                $newItem->appendChild($items->ownerDocument->createElement('invertStateDisplay', '1'));
            }
            $items->replaceChild($newItem, $item);
        }
    }

    private static function firstChild(\DOMElement $parent, string $tagName): ?\DOMElement
    {
        foreach ($parent->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement && $childNode->tagName === $tagName) {
                return $childNode;
            }
        }
        return null;
    }
}
