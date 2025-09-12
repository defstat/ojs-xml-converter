<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use DOMElement;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_1_0_to_3_1_1
{
    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        $xp = Xml::xp($doc);
        $ns = Xml::PKP_NS;

        // 0) Normalize tag typos
        foreach ($xp->query('//*[local-name()="disciplin"]') as $el) {
            /** @var DOMElement $el */
            Xml::renameElement($doc, $el, 'discipline');
            Xml::dbg($debug, 1, '➕', 'Renamed <disciplin> → <discipline>');
        }

        // 1) Ensure @section_ref on every <article>
        foreach ($xp->query('//*[local-name()="article"]') as $article) {
            /** @var DOMElement $article */
            if (!$article->hasAttribute('section_ref') || trim($article->getAttribute('section_ref')) === '') {
                $sectionRef = 'ART';
                $p = $article->parentNode;
                // Try to find enclosing <section> and use its <abbrev> as the ref
                $section = null;
                if ($p instanceof DOMElement && $p->localName === 'section') {
                    $section = $p;
                } elseif ($p instanceof DOMElement && $p->parentNode instanceof DOMElement && $p->parentNode->localName === 'section') {
                    $section = $p->parentNode;
                }
                if ($section instanceof DOMElement) {
                    $abbr = $xp->query('./*[local-name()="abbrev"]', $section)->item(0);
                    if ($abbr instanceof DOMElement) {
                        $val = trim($abbr->textContent);
                        if ($val !== '') $sectionRef = $val;
                    }
                }
                $article->setAttribute('section_ref', $sectionRef);
                Xml::dbg($debug, 1, '•', 'Added @section_ref="' . $sectionRef . '" to <article>');
            }
        }

        // 2) Authors: ensure @user_group_ref and rename personal name parts to 3.1.1 form
        foreach ($xp->query('//*[local-name()="authors"]/*[local-name()="author"]') as $author) {
            /** @var DOMElement $author */
            // Ensure required attribute on author
            if (!$author->hasAttribute('user_group_ref') || trim($author->getAttribute('user_group_ref')) === '') {
                $author->setAttribute('user_group_ref', 'Author');
                Xml::dbg($debug, 1, '•', 'Added @user_group_ref="Author" to <author>');
            }

            // Collect name parts (could be multi-locale)
            $firstnames  = iterator_to_array($xp->query('./*[local-name()="firstname"]', $author));
            $middlenames = iterator_to_array($xp->query('./*[local-name()="middlename"]', $author));
            $lastnames   = iterator_to_array($xp->query('./*[local-name()="lastname"]', $author));

            // Map middlename text per locale (join multiples with a space)
            $midByLocale = [];
            foreach ($middlenames as $mid) {
                /** @var DOMElement $mid */
                $loc = $mid->hasAttribute('locale') ? $mid->getAttribute('locale') : '';
                $txt = trim($mid->textContent);
                if ($txt === '') continue;
                $midByLocale[$loc] = isset($midByLocale[$loc]) && $midByLocale[$loc] !== ''
                    ? $midByLocale[$loc] . ' ' . $txt
                    : $txt;
            }

            // Emit <givenname> (firstname + middlename with same locale), then remove originals
            foreach ($firstnames as $fn) {
                /** @var DOMElement $fn */
                $loc = $fn->hasAttribute('locale') ? $fn->getAttribute('locale') : '';
                $parts = [trim($fn->textContent)];
                if (isset($midByLocale[$loc]) && $midByLocale[$loc] !== '') {
                    $parts[] = $midByLocale[$loc];
                    $midByLocale[$loc] = ''; // consumed
                }
                $txt = trim(implode(' ', array_filter($parts, fn($p) => $p !== '')));
                if ($txt !== '') {
                    $gn = $author->ownerDocument->createElementNS($ns, 'givenname', $txt);
                    if ($loc !== '') $gn->setAttribute('locale', $loc);
                    $author->insertBefore($gn, $fn);
                    Xml::dbg($debug, 2, '➕', 'Emitted <givenname>' . ($loc ? " (locale={$loc})" : ''));
                }
                $author->removeChild($fn);
            }

            // Remove any remaining <middlename> nodes
            foreach ($middlenames as $mid) {
                if ($mid->parentNode === $author) {
                    $author->removeChild($mid);
                }
            }

            // lastname → familyname (preserve @locale)
            foreach ($lastnames as $ln) {
                /** @var DOMElement $ln */
                $loc = $ln->hasAttribute('locale') ? $ln->getAttribute('locale') : '';
                $txt = trim($ln->textContent);
                $fam = $author->ownerDocument->createElementNS($ns, 'familyname', $txt);
                if ($loc !== '') $fam->setAttribute('locale', $loc);
                $author->insertBefore($fam, $ln);
                $author->removeChild($ln);
                Xml::dbg($debug, 2, '➕', 'Emitted <familyname>' . ($loc ? " (locale={$loc})" : ''));
            }

            // Safety net: if no <givenname> exists, synthesize one
            if ($xp->query('./*[local-name()="givenname"]', $author)->length === 0) {
                $fallback = '';
                if (!empty($firstnames)) $fallback = trim($firstnames[0]->textContent ?? '');
                if ($fallback === '' && !empty($middlenames)) $fallback = trim($middlenames[0]->textContent ?? '');
                if ($fallback !== '') {
                    $gn = $author->ownerDocument->createElementNS($ns, 'givenname', $fallback);
                    $author->appendChild($gn);
                    Xml::dbg($debug, 2, '➕', 'Synthesized fallback <givenname>');
                }
            }
        }

        foreach ($xp->query('//*[local-name()="submission_file"]/*[local-name()="revision"]') as $rev) {
            /** @var DOMElement $rev */
            if ($rev->hasAttribute('user_group_ref')) {
                $rev->removeAttribute('user_group_ref');
                Xml::dbg($debug, 1, '➖', 'Removed @user_group_ref from <revision> for 3.1.1 compatibility');
            }
        }

        return $doc;
    }
}
