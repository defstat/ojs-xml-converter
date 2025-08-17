<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_0_0_to_3_0_1
{
    public function transform(DOMDocument $doc, bool $debug = false): DOMDocument
    {
        $xp = Xml::xp($doc);
        $ns = Xml::PKP_NS;

        foreach ($xp->query('//*[local-name()="issue"]') as $issue) {
            /** @var DOMElement $issue */

            // 1) Ensure <issue_identification> exists (position doesn’t matter here)
            $ident = $xp->query('./*[local-name()="issue_identification"]', $issue)->item(0);
            if (!$ident) {
                $ident = $doc->createElementNS($ns, 'issue_identification');
                // append anywhere; final position will be handled by reorderChildren()
                $issue->appendChild($ident);
                Xml::dbg($debug, 1, '➕', 'Created <issue_identification>');
            }

            // 2) Move ONLY direct-child <title>, <number>, <volume>, <year> into <issue_identification>
            //    (Prevent stealing titles from nested nodes)
            foreach (['title','number','volume','year'] as $nm) {
                $toMove = [];
                foreach ($xp->query('./*[local-name()="'.$nm.'"]', $issue) as $el) {
                    if ($el instanceof DOMElement && $el->parentNode === $issue) {
                        $toMove[] = $el;
                    }
                }
                foreach ($toMove as $el) {
                    /** @var DOMElement $el */
                    $ident->appendChild($el->parentNode->removeChild($el));
                    Xml::dbg($debug, 2, '➕', "Moved <{$nm}> into <issue_identification>");
                }
            }

            // 3) Move @number/@volume/@year → elements inside <issue_identification>, then drop attrs
            foreach (['number','volume','year'] as $aname) {
                if ($issue->hasAttribute($aname)) {
                    $val = trim($issue->getAttribute($aname));
                    if ($val !== '') {
                        Xml::ensureSingleScalarChild($doc, $xp, $ident, $aname, $val, $ns);
                        Xml::dbg($debug, 2, '➕', "Moved @{$aname}=\"{$val}\" into <issue_identification>/<{$aname}>");
                    }
                    $issue->removeAttribute($aname);
                }
            }

            // 4) Remove display flags under <issue>
            foreach (['show_volume','show_number','show_year','show_title'] as $nm) {
                $rm = [];
                foreach ($xp->query('./*[local-name()="'.$nm.'"]', $issue) as $el) { $rm[] = $el; }
                foreach ($rm as $el) {
                    /** @var DOMElement $el */
                    $el->parentNode->removeChild($el);
                    Xml::dbg($debug, 2, '➖', "Removed <{$nm}>");
                }
            }

            // 5) Rename/wrap <issue_cover> → <issue_covers>/<cover> (keep existing behavior)
            $issueCovers = [];
            foreach ($xp->query('./*[local-name()="issue_cover"]', $issue) as $cov) { $issueCovers[] = $cov; }
            if ($issueCovers) {
                $wrap = $xp->query('./*[local-name()="issue_covers"]', $issue)->item(0);
                if (!$wrap) {
                    $wrap = $doc->createElementNS($ns, 'issue_covers');
                    $issue->appendChild($wrap);
                    Xml::dbg($debug, 2, '➕', 'Created <issue_covers>');
                }
                foreach ($issueCovers as $cov) {
                    /** @var DOMElement $cov */
                    Xml::renameElement($cov, 'cover');
                    $wrap->appendChild($cov->parentNode->removeChild($cov));
                    Xml::dbg($debug, 3, '➕', 'Renamed <issue_cover> → <cover> and wrapped');
                }
            }

            // 6) Inside <issue_identification>, enforce 3.0.1 order: volume?, number?, year?, title*
            Xml::reorderChildren($ident, ['volume','number','year','title']);
            Xml::dbg($debug, 2, '•', 'Ordered <issue_identification> as volume?, number?, year?, title*.');

            // Restore stashed <pages> per article (carried from 2.4.8 via PI), then reorder article children
            $allArticles = iterator_to_array($xp->query('./*[local-name()="articles"]/*[local-name()="article"]', $issue));
            foreach ($allArticles as $article) {
                /** @var DOMElement $article */
                $pages = Xml::getInstruction($article, 'pages', /* remove */ true);
                if ($pages !== null && $pages !== '') {
                    $article->appendChild($doc->createElementNS(Xml::PKP_NS, 'pages', $pages));
                    Xml::dbg($debug, 2, '📦', "Restored <pages>{$pages}</pages>");
                }

                // Ensure article child order conforms to 3.0.1
                Xml::reorderChildren($article, [
                    // base pkp:submission sequence
                    'id','title','prefix','subtitle','abstract','coverage','type','source','rights',
                    'keywords','agencies','disciplines','subjects','comments_to_editor','authors',
                    'submission_file','article_galley',
                    // 3.0.1 extension (native.xsd)
                    'issue_identification','pages',
                ]);
            }

            // 7) Reorder the <issue> children to 3.0.1 sequence so <date_published> is BELOW <issue_identification>
            Xml::reorderChildren($issue, [
                'id',
                'description',
                'issue_identification',
                'date_published',
                'date_notified',
                'last_modified',
                'open_access_date',
                'sections',
                'issue_covers',
                'issue_galleys',
                'articles',
            ]);
        }

        Xml::dbg($debug, 0, '✔', 'Hop 3.0.0 → 3.0.1 complete');
        return $doc;
    }
}
