<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_0_1_to_3_0_2
{
    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        $xp = Xml::xp($doc);

        foreach ($xp->query('//*[local-name()="hide_about" or local-name()="comments_to_editor"]') as $el) {
            $el->parentNode->removeChild($el);
            Xml::dbg($debug, 1, '➖', 'Removed <hide_about>/<comments_to_editor>');
        }

        return $doc;
    }
}
