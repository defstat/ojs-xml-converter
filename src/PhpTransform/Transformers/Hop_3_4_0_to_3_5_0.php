<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use DOMElement;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_4_0_to_3_5_0
{
    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        $xp = Xml::xp($doc);
        $ns = Xml::PKP_NS;

        foreach ($xp->query('//*[local-name()="authors"]/*[local-name()="author"]/*[local-name()="affiliation"]') as $aff) {
            /** @var DOMElement $aff */
            $this->affiliationToName($aff, $ns, $debug);
        }
        // foreach ($xp->query('//*[local-name()="user"]/*[local-name()="affiliation"]') as $aff) {
        //     /** @var DOMElement $aff */
        //     $this->affiliationToName($aff, $ns, $debug);
        // }

        return $doc;
    }

    private function affiliationToName(DOMElement $aff, string $ns, bool $debug): void
    {
        $doc = $aff->ownerDocument;
        foreach ($aff->childNodes as $ch) {
            if ($ch instanceof DOMElement && $ch->localName === 'name') {
                if ($aff->hasAttribute('locale')) $aff->removeAttribute('locale');
                Xml::dbg($debug, 1, '✓', 'Affiliation already has <name> (left as-is)');
                return;
            }
        }

        $text = trim($aff->textContent);
        $locale = $aff->hasAttribute('locale') ? $aff->getAttribute('locale') : '';
        while ($aff->firstChild) $aff->removeChild($aff->firstChild);
        $name = $doc->createElementNS($ns, 'name', $text);
        if ($locale !== '') {
            $name->setAttribute('locale', $locale);
            $aff->removeAttribute('locale');
        }
        $aff->appendChild($name);
        Xml::dbg($debug, 1, '➕', 'Affiliation → <name> (3.5 requirement)');
    }
}
