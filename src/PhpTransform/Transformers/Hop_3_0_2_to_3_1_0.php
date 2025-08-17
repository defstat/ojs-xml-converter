<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_0_2_to_3_1_0
{
    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        // Restore permissions fields on every <article> (no $issue needed)
        $xp = Xml::xp($doc);
        $ns = Xml::PKP_NS;

        // All <author> nodes (no $issue needed)
        $authors = iterator_to_array($xp->query('//*[local-name()="author"]'));

        foreach ($authors as $author) {
            /** @var DOMElement $author */

            // Only add if <orcid> is missing
            if ($xp->query('./*[local-name()="orcid"]', $author)->length === 0) {

                // Check each <url> under this author
                foreach (iterator_to_array($xp->query('./*[local-name()="url"]', $author)) as $urlEl) {
                    /** @var DOMElement $urlEl */
                    $val = trim($urlEl->textContent ?? '');
                    if ($val === '') continue;

                    $orcidValue = null;

                    // If it's an ORCID URL, copy the URL *exactly* as <orcid> content
                    if (preg_match('~^https?://(?:www\.)?(?:sandbox\.)?orcid\.org/\S+~i', $val)) {
                        $orcidValue = $val; // exact URL as requested
                    }
                    // Else if it's a bare ORCID ID, use the ID as-is
                    elseif (preg_match('~^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9xX]$~', $val)) {
                        $orcidValue = strtoupper($val);
                    }

                    if ($orcidValue !== null) {
                        $orcid = $doc->createElementNS($ns, 'orcid', $orcidValue);
                        // Place right after this <url> if possible
                        if ($urlEl->nextSibling) {
                            $author->insertBefore($orcid, $urlEl->nextSibling);
                        } else {
                            $author->appendChild($orcid);
                        }
                        Xml::dbg($debug, 2, '➕', "Added <orcid>{$orcidValue}</orcid> from <url>");
                        break; // done with this author
                    }
                }
            }

            // Reorder author children to 3.1.0 pkp:identity order
            Xml::reorderChildren($author, [
                'firstname',
                'middlename',
                'lastname',
                'affiliation',
                'country',
                'email',
                'url',
                'orcid',
                'biography',
            ]);
        }

        // All article nodes anywhere in the doc
        $allArticles = iterator_to_array($xp->query('//*[local-name()="article"]'));

        foreach ($allArticles as $article) {
            /** @var DOMElement $article */

            // Prefer a single PI that holds the whole <permissions>...</permissions> XML
            $licenseUrl = null;
            $year = null;
            $holders = []; // each: ['text' => ..., 'locale' => (string|null)]

            $permXml = Xml::getInstruction($article, 'permissions', /* remove */ true);
            if ($permXml !== null && trim($permXml) !== '') {
                $tmp = new DOMDocument('1.0', 'UTF-8');
                if (@$tmp->loadXML($permXml, LIBXML_NONET)) {
                    $root = $tmp->documentElement;
                    if ($root && strcasecmp($root->localName, 'permissions') === 0) {
                        // license_url -> licenseUrl
                        $n = $root->getElementsByTagName('license_url')->item(0);
                        if ($n) $licenseUrl = trim($n->textContent);

                        // copyright_holder -> copyrightHolder (repeatable, keep locale)
                        foreach ($root->getElementsByTagName('copyright_holder') as $ch) {
                            /** @var DOMElement $ch */
                            $text = trim($ch->textContent);
                            if ($text !== '') {
                                $holders[] = [
                                    'text'   => $text,
                                    'locale' => $ch->hasAttribute('locale') ? $ch->getAttribute('locale') : null,
                                ];
                            }
                        }

                        // copyright_year -> copyrightYear
                        $n = $root->getElementsByTagName('copyright_year')->item(0);
                        if ($n) $year = trim($n->textContent);
                    }
                }
            }

            // Optional fallback if you stashed individual keys instead of the whole block
            if ($licenseUrl === null || $licenseUrl === '') {
                $licenseUrl = Xml::getInstruction($article, 'licenseUrl', true)
                        ?: Xml::getInstruction($article, 'license_url', true);
            }
            if ($year === null || $year === '') {
                $year = Xml::getInstruction($article, 'copyrightYear', true)
                    ?: Xml::getInstruction($article, 'copyright_year', true);
            }

            // Emit 3.1.x fields (only if not already present)
            if ($licenseUrl && $xp->query('./*[local-name()="licenseUrl"]', $article)->length === 0) {
                $article->appendChild($doc->createElementNS($ns, 'licenseUrl', $licenseUrl));
            }
            if ($year && $xp->query('./*[local-name()="copyrightYear"]', $article)->length === 0) {
                $article->appendChild($doc->createElementNS($ns, 'copyrightYear', $year));
            }
            foreach ($holders as $h) {
                $el = $doc->createElementNS($ns, 'copyrightHolder', $h['text']);
                if ($h['locale']) $el->setAttribute('locale', $h['locale']);
                $article->appendChild($el);
            }

            // Drop any legacy <permissions> wrapper if present
            foreach (iterator_to_array($xp->query('./*[local-name()="permissions"]', $article)) as $legacy) {
                $article->removeChild($legacy);
            }

            // (Optional) Reorder article children to your 3.1.0 order
            Xml::reorderChildren($article, [
                'id','title','prefix','subtitle','abstract','coverage','type','source','rights',
                'licenseUrl','copyrightHolder','copyrightYear',
                'keywords','agencies','disciplines','subjects','comments_to_editor',
                'authors','submission_file','article_galley',
                'issue_identification','pages',
            ]);
        }

        return $doc;
    }
}
