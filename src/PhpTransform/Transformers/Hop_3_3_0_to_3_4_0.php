<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_3_0_to_3_4_0
{
    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        $xp = Xml::xp($doc);

        // 1) Normalize *all* locale attributes across the document (en_US → en, pt_BR → pt, …)
        $this->normalizeAllLocales($xp, $debug);

        // 2) 3.4.0: <affiliation> MUST be plain text (no <name> child)
        $this->unwrapAffiliationName($doc, $xp, $debug);

        return $doc;
    }

    /**
     * Convert every @locale and @primary_locale in the document using Xml::mapLocale():
     *   en_US → en, pt_BR → pt, etc. Leaves unknown/other formats untouched.
     */
    private function normalizeAllLocales(DOMXPath $xp, bool $debug): void
    {
        // Map any attribute literally named "locale"
        foreach ($xp->query('//@locale') as $attr) {
            /** @var \DOMAttr $attr */
            $old = (string)$attr->value;
            $new = Xml::mapLocale($old);
            if ($new !== $old) {
                $attr->value = (string)$new;
                Xml::dbg($debug, 0, '➕', "locale: {$old} → {$new}");
            }
        }

        // Map any attribute literally named "primary_locale"
        foreach ($xp->query('//@primary_locale') as $attr) {
            /** @var \DOMAttr $attr */
            $old = (string)$attr->value;
            $new = Xml::mapLocale($old);
            if ($new !== $old) {
                $attr->value = (string)$new;
                Xml::dbg($debug, 0, '➕', "primary_locale: {$old} → {$new}");
            }
        }
    }

    private function unwrapAffiliationName(DOMDocument $doc, DOMXPath $xp, bool $debug): void
    {
        // Find all <affiliation> elements that contain a child <name>
        $nodes = iterator_to_array($xp->query('//*[local-name()="affiliation"]/*[local-name()="name"]'));
        foreach ($nodes as $nameEl) {
            /** @var DOMElement $nameEl */
            $aff = $nameEl->parentNode instanceof DOMElement ? $nameEl->parentNode : null;
            if (!$aff) continue;

            // Get the text content from <name>
            $text = trim($nameEl->textContent);

            // Remove ALL children from <affiliation>
            while ($aff->firstChild) {
                $aff->removeChild($aff->firstChild);
            }

            // Set the affiliation to plain text (if any)
            if ($text !== '') {
                $aff->appendChild($doc->createTextNode($text));
            }

            Xml::dbg($debug, 0, '➖', 'Affiliation: unwrapped <name> to plain text (3.4.0)');
        }
    }
}
