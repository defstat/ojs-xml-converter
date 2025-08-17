<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_2_0_to_3_3_0
{
    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        $xp = Xml::xp($doc);

        $this->unwrapAffiliationName($doc, $xp, $debug);

        // 1) submission_file: remove child <id> (present in 3.2.0, dropped in 3.3.0)
        foreach (iterator_to_array($xp->query('//*[local-name()="submission_file"]')) as $sf) {
            /** @var DOMElement $sf */
            foreach (iterator_to_array($xp->query('./*[local-name()="id"]', $sf)) as $idChild) {
                $sf->removeChild($idChild);
            }

            // Optional: keep a stable order for your flavor, e.g. simple [name, file]
            // (adjust if your submission_file includes more direct children)
            Xml::reorderChildren($sf, ['creator','description','name','file','publication_format','sales_rights']);
        }

        // 2) publication: order unchanged, but it doesn’t hurt to normalize
        foreach (iterator_to_array($xp->query('//*[local-name()="publication"]')) as $pub) {
            /** @var DOMElement $pub */
            Xml::reorderChildren($pub, [
                'id',
                'title','prefix','subtitle','abstract','coverage','type','source','rights',
                'licenseUrl','copyrightHolder','copyrightYear',
                'keywords','agencies','languages','disciplines','subjects',
                'authors','article_galley','citations',
                // native extension stays the same in 3.3.0:
                'issue_identification','pages','covers','issueId',
            ]);
        }

        // 3) submission/article container (sequence unchanged, normalize anyway)
        foreach (iterator_to_array($xp->query('//*[local-name()="article"]')) as $article) {
            /** @var DOMElement $article */
            Xml::reorderChildren($article, ['id','submission_file','publication']);
        }

        return $doc;
    }

    /**
    * 3.3.0 schema: <affiliation> content is text; a nested <name> element is NOT allowed.
    * Convert:
    *   <affiliation><name locale="…">ACME University</name></affiliation>
    * to:
    *   <affiliation locale="…">ACME University</affiliation>
    */
    private function unwrapAffiliationName(DOMDocument $doc, DOMXPath $xp, bool $debug): void
    {
        // Find all <affiliation> elements that contain a child <name>
        $nodes = iterator_to_array($xp->query('//*[local-name()="affiliation"]/*[local-name()="name"]'));
        foreach ($nodes as $nameEl) {
            /** @var DOMElement $nameEl */
            $aff = $nameEl->parentNode instanceof DOMElement ? $nameEl->parentNode : null;
            if (!$aff) continue;

            // capture text and locale
            $text   = trim($nameEl->textContent);
            $locale = $nameEl->getAttribute('locale');

            // Remove ALL children from <affiliation>
            while ($aff->firstChild) {
                $aff->removeChild($aff->firstChild);
            }

            // Move locale from <name> to <affiliation> if useful
            if ($locale !== '' && !$aff->hasAttribute('locale')) {
                $aff->setAttribute('locale', $locale);
            }

            // Set the affiliation as plain text (if any)
            if ($text !== '') {
                $aff->appendChild($doc->createTextNode($text));
            }

            Xml::dbg($debug, 0, '➖', 'Affiliation: unwrapped <name> to plain text (3.3.0)');
        }
    }
}
