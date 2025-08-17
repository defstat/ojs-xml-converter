<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use DOMElement;
use OJS\PhpTransform\Util\Xml;

final class Hop_3_1_1_to_3_2_0
{
    private const LOCALE_REGEX = '/^[a-z]{2}_[A-Z]{2}(?:@[a-z]+)?$/';

    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        $xp = Xml::xp($doc);
        $ns = Xml::PKP_NS;

        

        // // Build wanted revision map
        // $wantedRevBySfId = [];
        // foreach ($xp->query('//*[local-name()="submission_file_ref"][@id]') as $ref) {
        //     /** @var DOMElement $ref */
        //     $id = $ref->getAttribute('id');
        //     $rev = $ref->getAttribute('revision');
        //     if ($id && $rev !== '') $wantedRevBySfId[$id] = $rev;
        // }

        // No pre-scan needed (3.1.1 has no <publication>)
        $pubCounter = 1;

        // All <article> nodes in the document
        $articles = iterator_to_array($xp->query('//*[local-name()="article"]'));

        foreach ($articles as $article) {
            if (!$article->hasAttribute('status') || trim($article->getAttribute('status')) === '') {
                $article->setAttribute('status', '1');
                Xml::dbg($debug, 1, '➕', 'Set article@status="1" (default)');
            }

            // Rename article@language -> article@locale
            if ($article->hasAttribute('language')) {
                if (!$article->hasAttribute('locale')) {
                    $article->setAttribute('locale', $article->getAttribute('language'));
                }
                $article->removeAttribute('language');
                Xml::dbg($debug, 1, '✎', 'Renamed article@language → article@locale');
            }

            // authors: affiliation must contain <name> (3.2)
            $firstAuthorId = null;
            $primaryContactAuthorId = null;
            foreach ($xp->query('./*[local-name()="authors"]', $article) as $authors) {
                foreach ($xp->query('./*[local-name()="author"]', $authors) as $idx => $author) {
                    /** @var DOMElement $author */
                    if (!$author->hasAttribute('id'))  $author->setAttribute('id', (string)($idx+1));
                    if (!$author->hasAttribute('seq')) $author->setAttribute('seq', (string)($idx+1));
                    if ($firstAuthorId === null) $firstAuthorId = $author->getAttribute('id') ?: (string)($idx+1);

                    $pc = strtolower(trim((string)$author->getAttribute('primary_contact')));
                    if ($primaryContactAuthorId === null && ($pc === 'true' || $pc === '1' || $pc === 'yes')) {
                        $primaryContactAuthorId = $author->getAttribute('id');
                    }

                    foreach ($xp->query('./*[local-name()="affiliation"]', $author) as $aff) {
                        /** @var DOMElement $aff */
                        $text = trim($aff->textContent);
                        $locale = $aff->hasAttribute('locale') ? $aff->getAttribute('locale') : '';
                        while ($aff->firstChild) $aff->removeChild($aff->firstChild);
                        $name = $doc->createElementNS($ns, 'name', $text);
                        if ($locale !== '') {
                            $name->setAttribute('locale', $locale);
                            $aff->removeAttribute('locale');
                        }
                        $aff->appendChild($name);
                        Xml::dbg($debug, 3, '➕', 'Affiliation → <name> wrapper (3.2 requirement)');
                    }
                }
                // $pub->appendChild($authors->parentNode->removeChild($authors));
                // Xml::dbg($debug, 2, '➕', 'Moved <authors> into <publication>');
            }

            /** @var DOMElement $article */
            Xml::dbg($debug, 0, '—', 'Creating <publication> for <article>…');

            // 1) Create the <publication> node
            $pub = $doc->createElementNS($ns, 'publication');

            if (!$pub->hasAttribute('primary_contact_id')) {
                $chosen = $primaryContactAuthorId ?: $firstAuthorId;
                if ($chosen !== null && $chosen !== '') {
                    $pub->setAttribute('primary_contact_id', $chosen);
                    Xml::dbg($debug, 2, '➕', "publication@primary_contact_id={$chosen}");
                }
            }

            // 2) Move native extension attributes article → publication
            foreach (['section_ref','seq','access_status', 'date_published'] as $attr) {
                if ($article->hasAttribute($attr)) {
                    $pub->setAttribute($attr, $article->getAttribute($attr));
                    $article->removeAttribute($attr);
                }
            }

            // 3) Move submission-level metadata into <publication>
            foreach ([
                'id',
                'title','prefix','subtitle','abstract','coverage','type','source','rights',
                'licenseUrl','copyrightHolder','copyrightYear',
                'keywords','agencies','languages','disciplines','subjects',
                'authors','article_galley','citations', 'article_galley'
            ] as $ln) {
                Xml::moveAllChildren($xp, $doc, $article, $pub, $ln);
            }

            // 4) Move native (OJS) article extension pieces now under <publication>
            foreach (['issue_identification','pages','covers', ] as $ln) {
                Xml::moveAllChildren($xp, $doc, $article, $pub, $ln);
            }

            // 5) Assign unique internal id to <publication> and wire the article pointer
            $pubIdStr = (string) $pubCounter++;
            $intIdEl  = $doc->createElementNS($ns, 'id', $pubIdStr);
            $intIdEl->setAttribute('type', 'internal');
            $intIdEl->setAttribute('advice', 'ignore');

            // Put <id> first in <publication>
            if ($pub->firstChild) {
                $pub->insertBefore($intIdEl, $pub->firstChild);
            } else {
                $pub->appendChild($intIdEl);
            }

            $article->setAttribute('current_publication_id', $pubIdStr);

            Xml::dbg($debug, 1, '➕', "Created <publication> with internal id={$pubIdStr}");
            Xml::dbg($debug, 1, '➕', "Set article@current_publication_id={$pubIdStr}");

            $pub->setAttribute('version', '1');
            Xml::dbg($debug, 1, '➕', 'Set publication@version="1"');
            if (!$pub->hasAttribute('status')) {
                $pub->setAttribute('status', '1');
                Xml::dbg($debug, 1, '➕', 'Set publication@status="1" (default)');
            }

            // 7) Reorder <publication> to exact 3.2.0 order
            $pubOrderBase = [
                'id',
                'title','prefix','subtitle','abstract','coverage','type','source','rights',
                'licenseUrl','copyrightHolder','copyrightYear',
                'keywords','agencies','languages','disciplines','subjects',
                'authors','article_galley','citations',
            ];
            $pubOrderExt  = ['issue_identification','pages','covers','issueId']; // native extension
            Xml::reorderChildren($pub, array_merge($pubOrderBase, $pubOrderExt));

            $article->appendChild($pub);
            Xml::dbg($debug, 1, '➕', 'Inserted <publication> into <article>');

            // 8) Reorder <article> (pkp:submission) to 3.2.0 order: id*, submission_file*, publication+
            Xml::reorderChildren($article, ['id','submission_file','publication']);

            // Sanity: section_ref is required on <publication> by 3.2.0 native.xsd
            if (!$pub->hasAttribute('section_ref')) {
                Xml::dbg($debug, 1, '⚠', 'publication@section_ref is missing; derive from section abbrev if available.');
            }
        }

        $submissionFiles = iterator_to_array($xp->query('//*[local-name()="submission_file"]'));

        foreach ($submissionFiles as $sf) {
            /** @var DOMElement $sf */
            $sfId = $sf->getAttribute('id');
            if (!$sfId) {
                $idEl = $xp->query('./*[local-name()="id"][1]', $sf)->item(0);
                if ($idEl instanceof DOMElement) $sfId = trim($idEl->textContent);
            }
            $wanted = $sfId; //&& isset($wantedRevBySfId[$sfId]) ? $wantedRevBySfId[$sfId] : '';

            $revs = iterator_to_array($xp->query('./*[local-name()="revision"]', $sf));
            $chosen = null;
            if ($revs) {
                if ($wanted !== '') {
                    foreach ($revs as $rev) {
                        if ($rev->hasAttribute('number') && $rev->getAttribute('number') === $wanted) { $chosen = $rev; break; }
                    }
                }
                if (!$chosen) $chosen = end($revs);
            }

            $existingName = $xp->query('./*[local-name()="name"]', $sf)->item(0);
            if (!$existingName) {
                $nameFromChosen = $chosen ? $xp->query('./*[local-name()="name"]', $chosen)->item(0) : null;
                if ($nameFromChosen instanceof DOMElement) {
                    $sf->appendChild($nameFromChosen->parentNode->removeChild($nameFromChosen));
                    Xml::dbg($debug, 2, '➕', 'submission_file: promoted <name> from chosen revision');
                // } else {
                //     $fallback = $chosen ? trim((string)$xp->query('./*[local-name()="mimetype"]', $chosen)->item(0)?->textContent) : '';
                //     if ($fallback === '') $fallback = 'file';
                //     $sf->appendChild(Xml::newEl($doc, 'name', $fallback));
                //     Xml::dbg($debug, 2, '➕', "submission_file: synthesized <name>{$fallback}</name>");
                }
            }

            $file = $xp->query('./*[local-name()="file"]', $sf)->item(0);
            if (!$file) {
                $file = $doc->createElementNS($ns, 'file');
                Xml::dbg($debug, 2, '➕', 'submission_file: created <file>');
            } else {
                foreach ($xp->query('./*[local-name()="embed" or local-name()="href"]', $file) as $old) {
                    $old->parentNode?->removeChild($old);
                }
                foreach (['filename','mime_type'] as $bad) {
                    if ($file->hasAttribute($bad)) $file->removeAttribute($bad);
                }
            }

            if ($chosen) {
                // --- filesize: prefer @filesize, else child <filesize> ---
                $sz = '';
                if ($chosen->hasAttribute('filesize')) {
                    $sz = trim($chosen->getAttribute('filesize'));
                }
                if ($sz === '') {
                    $node = $xp->query('./*[local-name()="filesize"]', $chosen)->item(0);
                    if ($node) $sz = trim($node->textContent);
                }
                if ($sz !== '' && is_numeric($sz)) {
                    $file->setAttribute('filesize', (string)((int)$sz));
                    Xml::dbg($debug, 3, '➕', "file@filesize={$sz}");
                }

                // --- extension resolution (robust) ---
                $ext = '';

                // 0) child <extension>
                $extNode = $xp->query('./*[local-name()="extension"]', $chosen)->item(0);
                if ($extNode) $ext = strtolower(trim($extNode->textContent));

                // 1) attribute @extension
                if ($ext === '' && $chosen->hasAttribute('extension')) {
                    $ext = strtolower(trim($chosen->getAttribute('extension')));
                }

                // 2) from @filename
                if ($ext === '' && $chosen->hasAttribute('filename')) {
                    $fn = trim($chosen->getAttribute('filename'));
                    $dot = strrpos($fn, '.');
                    if ($dot !== false && $dot < strlen($fn) - 1) {
                        $ext = strtolower(substr($fn, $dot + 1));
                        Xml::dbg($debug, 3, '…', "extension from filename={$ext}");
                    }
                }

                // 3) from @filetype (MIME), e.g. application/pdf
                if ($ext === '' && $chosen->hasAttribute('filetype')) {
                    $mt = strtolower(trim($chosen->getAttribute('filetype')));
                    // strip any parameters (e.g. "; charset=binary")
                    $mt = explode(';', $mt, 2)[0];

                    $mimeMapExact = [
                        'application/pdf' => 'pdf',
                        'application/msword' => 'doc',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                        'application/vnd.ms-excel' => 'xls',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                        'application/vnd.ms-powerpoint' => 'ppt',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                        'application/zip' => 'zip',
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/png' => 'png',
                        'text/xml' => 'xml',
                        'application/xml' => 'xml',
                        'text/plain' => 'txt',
                        'application/rtf' => 'rtf',
                        'application/vnd.oasis.opendocument.text' => 'odt',
                        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
                    ];
                    $mimeMapLoose = [
                        'pdf' => 'pdf',
                        'msword' => 'doc',
                        'wordprocessingml' => 'docx',
                        'vnd.ms-excel' => 'xls',
                        'spreadsheetml' => 'xlsx',
                        'vnd.ms-powerpoint' => 'ppt',
                        'presentationml' => 'pptx',
                        'zip' => 'zip',
                        'jpeg' => 'jpg',
                        'jpg' => 'jpg',
                        'png' => 'png',
                        'xml' => 'xml',
                        'plain' => 'txt',
                        'rtf' => 'rtf',
                        'opendocument.text' => 'odt',
                        'opendocument.spreadsheet' => 'ods',
                    ];

                    if (isset($mimeMapExact[$mt])) {
                        $ext = $mimeMapExact[$mt];
                    } else {
                        foreach ($mimeMapLoose as $needle => $val) {
                            if (strpos($mt, $needle) !== false) { $ext = $val; break; }
                        }
                    }
                    if ($ext !== '') Xml::dbg($debug, 3, '…', "extension from filetype={$mt} → {$ext}");
                }

                // 4) fallback: child <mimetype>
                if ($ext === '') {
                    $mtNode = $xp->query('./*[local-name()="mimetype"]', $chosen)->item(0);
                    if ($mtNode) {
                        $mt = strtolower(trim($mtNode->textContent));
                        foreach ($mimeMapExact as $k => $v) { if ($mt === $k) { $ext = $v; break; } }
                        if ($ext === '') {
                            foreach ($mimeMapLoose as $needle => $val) {
                                if (strpos($mt, $needle) !== false) { $ext = $val; break; }
                            }
                        }
                        if ($ext !== '') Xml::dbg($debug, 3, '…', "extension from mimetype={$mt} → {$ext}");
                    }
                }

                // normalize common variants
                if ($ext === 'jpeg') $ext = 'jpg';
                if ($ext === 'tif')  $ext = 'tiff';

                if ($ext === '') $ext = 'bin';
                $file->setAttribute('extension', $ext);
                Xml::dbg($debug, 3, '➕', "file@extension={$ext}");

                // --- move binary/link into <file> ---
                foreach ($xp->query('./*[local-name()="embed" or local-name()="href" or local-name()="remote"]', $chosen) as $kid) {
                    /** @var DOMElement $kid */
                    if (strcasecmp($kid->localName, 'remote') === 0) {
                        Xml::renameElement($doc, $kid, 'href');
                        Xml::dbg($debug, 3, '✎', 'revision:<remote> → <href>');
                    }
                    $file->appendChild($kid->parentNode->removeChild($kid));
                    Xml::dbg($debug, 3, '↳', 'moved data child into <file>');
                }
            }

            foreach ($xp->query('./*[local-name()="revision"]', $sf) as $revLeft) {
                $revLeft->parentNode?->removeChild($revLeft);
                Xml::dbg($debug, 2, '➖', 'dropped legacy <revision>');
            }

            foreach ($xp->query('./*[local-name()="embed" or local-name()="href" or local-name()="remote"]', $sf) as $stray) {
                $stray->parentNode?->removeChild($stray);
                Xml::dbg($debug, 2, '➖', 'removed stray embed/href under <submission_file>');
            }

            $order = ['id','creator','description','name','publisher','source','sponsor','subject','submission_file_ref'];
            $insertAfter = null;
            foreach ($order as $nm) {
                $nodes = $xp->query('./*[local-name()="'.$nm.'"]', $sf);
                if ($nodes->length) $insertAfter = $nodes->item($nodes->length - 1);
            }
            if ($file->parentNode !== $sf) {
                if ($insertAfter instanceof DOMElement && $insertAfter->parentNode === $sf) {
                    if ($insertAfter->nextSibling) $sf->insertBefore($file, $insertAfter->nextSibling);
                    else $sf->appendChild($file);
                } else {
                    $sf->appendChild($file);
                }
                Xml::dbg($debug, 2, '➕', 'placed <file> after metadata block');
            }
        }

        // submission_file_ref: drop @revision
        foreach ($xp->query('//*[local-name()="submission_file_ref"]/@revision') as $a) {
            $a->ownerElement?->removeAttribute('revision');
            Xml::dbg($debug, 1, '➕', 'Removed @revision from <submission_file_ref>');
        }

        // Locale sanity
        foreach ($xp->query('//*[@locale]') as $nodeWithLocale) {
            /** @var DOMElement $nodeWithLocale */
            $loc = $nodeWithLocale->getAttribute('locale');
            if ($loc !== '' && !preg_match(self::LOCALE_REGEX, $loc)) {
                $nodeWithLocale->removeAttribute('locale');
                Xml::dbg($debug, 1, '➖', "Removed invalid @locale=\"{$loc}\"");
            }
        }

        

        return $doc;
    }

    // /**
    // * 3.2.0 migration:
    // * - Remove @date_published from <article>
    // * - Remove stray <article>/<date_published> elements (invalid in 3.2.0)
    // * - Ensure the first <publication> (or <pkppublication>) under the article has a <date_published> child.
    // */
    // private function migrateArticleDatePublishedToPublication(\DOMDocument $doc, \DOMXPath $xp, bool $debug): void
    // {
    //     // Use the document’s default namespace (PKP)
    //     $ns = $doc->documentElement && $doc->documentElement->namespaceURI
    //         ? $doc->documentElement->namespaceURI
    //         : 'http://pkp.sfu.ca';

    //     foreach ($xp->query('//*[local-name()="article"]') as $article) {
    //         /** @var \DOMElement $article */

    //         // 1) Collect any date value from @date_published or a (now invalid) direct child <date_published>
    //         $val = null;

    //         if ($article->hasAttribute('date_published')) {
    //             $v = trim($article->getAttribute('date_published'));
    //             if ($v !== '') $val = $v;
    //             $article->removeAttribute('date_published');
    //             \OJS\PhpTransform\Util\Xml::dbg($debug, 1, '➖', 'Removed <article>@date_published');
    //         }

    //         // Remove any invalid direct child <date_published> and keep its value if we don’t have one yet
    //         foreach (iterator_to_array($xp->query('./*[local-name()="date_published"]', $article)) as $dpEl) {
    //             /** @var \DOMElement $dpEl */
    //             if ($val === null) {
    //                 $t = trim($dpEl->textContent);
    //                 if ($t !== '') $val = $t;
    //             }
    //             $article->removeChild($dpEl);
    //             \OJS\PhpTransform\Util\Xml::dbg($debug, 1, '➖', 'Removed invalid <article>/<date_published>');
    //         }

    //         // 2) Find the first publication container
    //         $pub = $xp->query('./*[local-name()="publication" or local-name()="pkppublication"]', $article)->item(0);
    //         if (!$pub instanceof \DOMElement) {
    //             // If your existing hop guarantees a publication node, we can require it.
    //             // Otherwise, bail gracefully (no place to put the date) and rely on earlier logic.
    //             if ($val !== null) {
    //                 \OJS\PhpTransform\Util\Xml::dbg($debug, 1, '⚠️', 'No <publication> under <article>; cannot place <date_published>');
    //             }
    //             continue;
    //         }

    //         // 3) If there is already a <publication>/<date_published>, don’t duplicate — prefer the existing element.
    //         $existing = $xp->query('./*[local-name()="date_published"]', $pub)->item(0);
    //         if ($existing instanceof \DOMElement) {
    //             if ($val !== null && trim($existing->textContent) === '') {
    //                 $existing->nodeValue = $val;
    //                 \OJS\PhpTransform\Util\Xml::dbg($debug, 1, '✎', 'Filled empty <publication>/<date_published> from attribute value');
    //             } else {
    //                 \OJS\PhpTransform\Util\Xml::dbg($debug, 1, '•', 'Kept existing <publication>/<date_published>');
    //             }
    //             continue;
    //         }

    //         // 4) Create <date_published> under publication if we have a value
    //         if ($val !== null) {
    //             $dp = $doc->createElementNS($ns, 'date_published', $val);

    //             // Optional: place it early among publication children (before text/title/abstract if you prefer).
    //             // Here we insert as the first element child for a stable order.
    //             $ref = null;
    //             for ($n = $pub->firstChild; $n; $n = $n->nextSibling) {
    //                 if ($n instanceof \DOMElement) { $ref = $n; break; }
    //             }
    //             if ($ref) $pub->insertBefore($dp, $ref);
    //             else $pub->appendChild($dp);

    //             \OJS\PhpTransform\Util\Xml::dbg($debug, 1, '➕', 'Inserted <publication>/<date_published>');
    //         }
    //     }
    // }


}
