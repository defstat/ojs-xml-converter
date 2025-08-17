<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OJS\PhpTransform\Util\Xml;

/**
 * Hop 2.4.8 → 3.0.0 (Native)
 *
 * Implements:
 * - Removes legacy 2.4.8 DOCTYPE declarations (not used in 3.x).
 * - Issue-level:
 *   • @published/@current: 'true'/'false' → "1"/"0"
 *   • Drop @identification
 *   • Move <volume>/<number>/<year> child elements to ISSUE ATTRIBUTES (kept as attrs in 3.0.0)
 *   • Remove legacy <open_access> element (schema uses <open_access_date>)
 *   • Ensure <sections> and <articles> containers exist
 *   • Reorder <issue> children to 3.0.0 order:
 *       id*, description*, title*, date_published?, date_notified?, last_modified?, open_access_date?,
 *       sections, issue_cover?, issue_galleys?, articles
 *
 * - Sections:
 *   • Convert legacy <section>… into <sections>/<section>
 *   • Children order: id*, abbrev*, policy*, title*  (title AFTER abbrev)
 *   • Set defaults on each NEW <section>:
 *       seq="1" editor_restricted="0" meta_indexed="1" meta_reviewed="1"
 *       abstracts_not_required="0" hide_title="0" hide_author="0" hide_about="0" abstract_word_count="0"
 *
 * - Articles:
 *   • Move all <section>/<article> into top-level <articles>
 *   • For each moved article, set @section_ref to the FIRST non-empty <abbrev> text of its parent section
 *     (capture BEFORE any movement; fallback POST-COPY if needed; extra safety backfill at end)
 *   • Ensure EVERY <author> has user_group_ref="Author" (wrap direct <author>… into <authors>)
 *   • Default article @stage="submission" if missing
 *   • permissions → rights
 *   • Convert <galley>/<file> → <submission_file> (stage="submission") + <article_galley>
 *       <submission_file stage="submission" id="…">
 *         <revision number="1" genre="Article Text" user_group_ref="Author"
 *                   filename="…" filetype="…" filesize="…">
 *           <name locale="…">FILENAME</name>
 *           <embed …>…</embed> | <href src="…"/>
 *         </revision>
 *       </submission_file>
 *       <article_galley>
 *         <name locale="en_US">PDF</name><seq>0</seq>
 *         <submission_file_ref id="…" revision="1"/>
 *       </article_galley>
 *   • Remove legacy <indexing>, <open_access> under <article>
 *   • Stash <pages> under <article> as <article_identification>/<pages> (if non-empty)
 */
final class Hop_2_4_8_to_3_0_0
{
    public function transform(DOMDocument $doc, bool $debug = false): DOMDocument
    {
        $xp = Xml::xp($doc);

        if ($doc->doctype !== null) {
            $doc->removeChild($doc->doctype);
        }

        // ───────────── Issues ─────────────
        foreach ($xp->query('//*[local-name()="issue"]') as $issue) {
            /** @var DOMElement $issue */

            Xml::dbg($debug, 0, '—', 'Processing <issue>…');

            // @published/@current → "1"/"0"
            foreach (['published', 'current'] as $attr) {
                if ($issue->hasAttribute($attr)) {
                    $orig = $issue->getAttribute($attr);
                    $v = strtolower(trim($orig));
                    if ($v === 'true')      { $issue->setAttribute($attr, '1'); Xml::dbg($debug, 1, '✎', "Normalized @$attr '{$orig}' → '1'"); }
                    elseif ($v === 'false') { $issue->setAttribute($attr, '0'); Xml::dbg($debug, 1, '✎', "Normalized @$attr '{$orig}' → '0'"); }
                }
            }

            // Drop @identification
            if ($issue->hasAttribute('identification')) {
                $issue->removeAttribute('identification');
                Xml::dbg($debug, 1, '➖', 'Removed @identification');
            }

            // Move <volume>/<number>/<year> elements → attributes, then remove elements
            foreach (['volume','number','year'] as $nm) {
                $nodes = iterator_to_array($xp->query('./*[local-name()="'.$nm.'"]', $issue));
                foreach ($nodes as $el) {
                    /** @var DOMElement $el */
                    $val = trim($el->textContent);
                    if ($val !== '' && !$issue->hasAttribute($nm)) {
                        $issue->setAttribute($nm, $val);
                        Xml::dbg($debug, 1, '✳', "Issue: moved <{$nm}> → @{$nm}='{$val}'");
                    } else {
                        Xml::dbg($debug, 1, '…', "Issue: skipped moving <{$nm}> (empty or already on attribute)");
                    }
                    $issue->removeChild($el);
                }
            }

            // Remove legacy <open_access> under <issue>
            foreach (iterator_to_array($xp->query('./*[local-name()="open_access"]', $issue)) as $oa) {
                $issue->removeChild($oa);
                Xml::dbg($debug, 1, '➖', 'Issue: removed legacy <open_access> element.');
            }

            // Ensure <sections> and <articles> containers
            $sectionsWrap = $this->ensureSingleChild($doc, $xp, $issue, 'sections', $debug);
            $articlesWrap = $this->ensureSingleChild($doc, $xp, $issue, 'articles', $debug);

            // Map to remember original section_ref for each article (safety backfill later)
            $articleRefMap = new \SplObjectStorage();

            // ───────────── Sections → Sections/Articles ─────────────
            $legacySections = iterator_to_array($xp->query('./*[local-name()="section"]', $issue));
            Xml::dbg($debug, 1, 'ℹ', 'Legacy <section> count: ' . count($legacySections));

            foreach ($legacySections as $secIdx => $sec) {
                /** @var DOMElement $sec */
                Xml::dbg($debug, 1, '⮞', "Lifting legacy <section> #".($secIdx+1));

                // Capture abbrev BEFORE any child movement
                $sectionRef = $this->firstNonEmptyAbbrev($xp, $sec, $debug, 'pre-copy');

                // Create new 3.0.0 <section> with required child order
                $secOut = $doc->createElementNS(Xml::PKP_NS, 'section');

                // Defaults on NEW sections
                $secOut->setAttribute('seq', '1');
                $secOut->setAttribute('editor_restricted', '0');
                $secOut->setAttribute('meta_indexed', '1');
                $secOut->setAttribute('meta_reviewed', '1');
                $secOut->setAttribute('abstracts_not_required', '0');
                $secOut->setAttribute('hide_title', '0');
                $secOut->setAttribute('hide_author', '0');
                $secOut->setAttribute('abstract_word_count', '0');

                foreach (['id','abbrev','policy','title'] as $name) {
                    $copied = 0;
                    foreach (iterator_to_array($xp->query('./*[local-name()="'.$name.'"]', $sec)) as $child) {
                        /** @var DOMElement $child */
                        $secOut->appendChild($doc->importNode($child, true));
                        $copied++;
                    }
                    if ($copied) Xml::dbg($debug, 2, '↳', "Copied {$copied} <{$name}> node(s) into new <section>");
                }

                $sectionsWrap->appendChild($secOut);
                Xml::dbg($debug, 2, '➕', 'Appended new <section> with defaults to <sections>');

                // Fallback: if nothing found pre-copy, try on the NEW <section> we just built
                if ($sectionRef === '') {
                    $sectionRef = $this->firstNonEmptyAbbrev($xp, $secOut, $debug, 'post-copy');
                }

                // Move nested <article> → <articles>, stamp @section_ref, and remember mapping
                $movedCount = 0;
                foreach (iterator_to_array($xp->query('./*[local-name()="article"]', $sec)) as $art) {
                    /** @var DOMElement $art */
                    $movedCount++;
                    $desc = $this->articleDesc($art);

                    if ($sectionRef !== '' && !$art->hasAttribute('section_ref')) {
                        $art->setAttribute('section_ref', $sectionRef);
                        Xml::dbg($debug, 3, '🏷', "Stamped section_ref='{$sectionRef}' on article {$desc}");
                    } elseif ($art->hasAttribute('section_ref')) {
                        Xml::dbg($debug, 3, '…', "Article {$desc} already had section_ref='{$art->getAttribute('section_ref')}', leaving as-is");
                    } else {
                        Xml::dbg($debug, 3, '⚠', "Article {$desc} did NOT get section_ref (empty abbrev)");
                    }

                    if ($sectionRef !== '') { $articleRefMap[$art] = $sectionRef; }

                    $articlesWrap->appendChild($art); // move
                    Xml::dbg($debug, 3, '⇢', "Moved article {$desc} into <articles>");
                }
                Xml::dbg($debug, 2, 'ℹ', "Moved {$movedCount} nested <article> node(s) from legacy <section>");

                // Remove original legacy <section>
                $issue->removeChild($sec);
                Xml::dbg($debug, 2, '➖', 'Removed legacy <section>');
            }

            // Move any leftover direct-child <article> into <articles> (no section_ref available)
            $danglingCount = 0;
            foreach (iterator_to_array($xp->query('./*[local-name()="article"]', $issue)) as $dangling) {
                /** @var DOMElement $dangling */
                $danglingCount++;
                $desc = $this->articleDesc($dangling);
                $articlesWrap->appendChild($dangling);
                Xml::dbg($debug, 2, '⇢', "Moved dangling direct <article> {$desc} into <articles> (no parent section)");
            }
            if ($danglingCount) {
                Xml::dbg($debug, 1, '⚠', "Found {$danglingCount} direct <article> node(s) at <issue> level; no section_ref will be available for them.");
            }

            // Normalize each article
            $allArticles = iterator_to_array($xp->query('./*[local-name()="articles"]/*[local-name()="article"]', $issue));
            Xml::dbg($debug, 1, 'ℹ', 'Normalizing articles: ' . count($allArticles));

            $runningId = 0;
            foreach ($allArticles as $article) {
                /** @var DOMElement $article */
                $this->normalizeArticle($doc, $article, $xp, $debug, $runningId);
            }

            // Safety backfill: ensure every article that originally had a section gets @section_ref
            $backfilled = 0;
            foreach ($allArticles as $article) {
                /** @var DOMElement $article */
                if (!$article->hasAttribute('section_ref') && isset($articleRefMap[$article])) {
                    $article->setAttribute('section_ref', (string)$articleRefMap[$article]);
                    $backfilled++;
                    Xml::dbg($debug, 2, '🏷', 'Backfilled section_ref=\'' . (string)$articleRefMap[$article] . '\' on ' . $this->articleDesc($article));
                }
            }
            if ($backfilled) {
                Xml::dbg($debug, 1, '✚', "Backfilled section_ref on {$backfilled} article(s).");
            }

            // Report if any articles are still missing section_ref
            $missing = 0;
            foreach ($allArticles as $article) {
                if (!$article->hasAttribute('section_ref')) {
                    $missing++;
                    Xml::dbg($debug, 1, '⚠', 'Article missing section_ref: ' . $this->articleDesc($article));
                }
            }
            if ($missing === 0) {
                Xml::dbg($debug, 1, '✔', 'All articles have section_ref.');
            }

            // Reorder <issue> children to 3.0.0 sequence
            Xml::reorderChildren($issue, [
                'id',
                'description',
                'title',
                'date_published',
                'date_notified',
                'last_modified',
                'open_access_date',
                'sections',
                'issue_cover',
                'issue_galleys',
                'articles',
            ]);
        }

        Xml::dbg($debug, 0, '✔', 'Hop 2.4.8 → 3.0.0 complete');
        return $doc;
    }

    // ───────────── Helpers ─────────────

    private function ensureSingleChild(DOMDocument $doc, DOMXPath $xp, DOMElement $parent, string $localName, bool $debug): DOMElement
    {
        $existing = $xp->query('./*[local-name()="'.$localName.'"]', $parent)->item(0);
        if ($existing instanceof DOMElement) {
            Xml::dbg($debug, 2, '✓', "Found existing <{$localName}>");
            return $existing;
        }
        $el = $doc->createElementNS(Xml::PKP_NS, $localName);
        $parent->appendChild($el);
        Xml::dbg($debug, 2, '➕', "Created <{$localName}>");
        return $el;
    }

    // private function reorderIssueChildren(DOMElement $issue, array $order): void
    // {
    //     $buckets = [];
    //     for ($n = $issue->firstChild; $n; $n = $n->nextSibling) {
    //         if ($n instanceof DOMElement) {
    //             $name = $n->localName ?: $n->nodeName;
    //             $buckets[$name][] = $n;
    //         }
    //     }
    //     for ($n = $issue->firstChild; $n;) {
    //         $next = $n->nextSibling;
    //         if ($n instanceof DOMElement) $issue->removeChild($n);
    //         $n = $next;
    //     }
    //     foreach ($order as $name) {
    //         if (!empty($buckets[$name])) {
    //             foreach ($buckets[$name] as $el) $issue->appendChild($el);
    //             unset($buckets[$name]);
    //         }
    //     }
    //     foreach ($buckets as $leftovers) {
    //         foreach ($leftovers as $el) $issue->appendChild($el);
    //     }
    // }

    private function normalizeArticle(DOMDocument $doc, DOMElement $article, DOMXPath $xp, bool $debug, int &$runningId): void
    {
        $desc = $this->articleDesc($article);
        Xml::dbg($debug, 2, '—', "Normalizing article {$desc}");

        // Ensure EVERY author has user_group_ref="Author"
        $directAuthors = iterator_to_array($xp->query('./*[local-name()="author"]', $article));
        if ($directAuthors) {
            $wrap = $doc->createElementNS(Xml::PKP_NS, 'authors');
            foreach ($directAuthors as $a) {
                /** @var DOMElement $a */
                if (!$a->hasAttribute('user_group_ref')) {
                    $a->setAttribute('user_group_ref', 'Author');
                }
                $wrap->appendChild($a); // move
            }
            $article->appendChild($wrap);
            Xml::dbg($debug, 3, '✚', "Wrapped " . count($directAuthors) . " direct <author> node(s) into <authors>");
        }
        $fixed = 0;
        foreach (iterator_to_array($xp->query('.//*[local-name()="author"]', $article)) as $a2) {
            /** @var DOMElement $a2 */
            if (!$a2->hasAttribute('user_group_ref')) {
                $a2->setAttribute('user_group_ref', 'Author');
                $fixed++;
            }
        }
        if ($fixed) Xml::dbg($debug, 3, '✎', "Set user_group_ref='Author' on {$fixed} nested author(s)");

        // Default article stage if missing
        if (!$article->hasAttribute('stage')) {
            $article->setAttribute('stage', 'submission');
            Xml::dbg($debug, 3, '✎', "Set article @stage='submission'");
        }

        // Move <date_published> child → @date_published
        foreach (iterator_to_array($xp->query('./*[local-name()="date_published"]', $article)) as $dp) {
            /** @var DOMElement $dp */
            $date = trim($dp->textContent);
            if ($date !== '' && !$article->hasAttribute('date_published')) {
                $article->setAttribute('date_published', $date);
                Xml::dbg($debug, 3, '✎', "Moved <date_published> '{$date}' → @date_published");
            }
            $article->removeChild($dp);
        }

        // ── STASH <permissions> (2.4.8) to restore as 3.1.0 fields later ─────────────────
        foreach (iterator_to_array($xp->query('./*[local-name()="permissions"]', $article)) as $perm) {
            /** @var DOMElement $perm */
            $permXml = $doc->saveXML($perm); // store full outer XML
            if ($permXml !== false && trim($permXml) !== '') {
                Xml::addInstruction($article, 'permissions', $permXml); // PI survives reparses
                Xml::dbg($debug, 3, '📦', 'Stashed <permissions> block as PI');
            }
            // Remove legacy wrapper so 3.0.0 schema stays happy
            $article->removeChild($perm);
        }
        // ─────────────────────────────────────────────────────────────────────────────────

        // Galley/file → submission_file + article_galley
        
        foreach (iterator_to_array($xp->query('./*[local-name()="galley"]', $article)) as $galley) {
            /** @var DOMElement $galley */
            $galleyLocale = $galley->getAttribute('locale') ?: null;
            $fileCount = 0;
            foreach (iterator_to_array($xp->query('./*[local-name()="file"]', $galley)) as $file) {
                /** @var DOMElement $file */
                $runningId = $runningId + 1;
                $fileCount++;
                $this->emitSubmissionFileAndGalley($doc, $article, $file, $runningId, $galleyLocale, $debug);
            }
            Xml::dbg($debug, 3, '✚', "Converted {$fileCount} galley <file>(s) to submission_file/article_galley");
            $article->removeChild($galley);
        }

        // Stash legacy <pages> so we can restore it in 3.0.1 (3.0.0 disallows <pages>)
        foreach (iterator_to_array($xp->query('./*[local-name()="pages"]', $article)) as $p) {
            /** @var DOMElement $p */
            $val = trim($p->textContent);
            if ($val !== '') {
                Xml::addInstruction($article, 'pages', $val);
                Xml::dbg($debug, 3, '📦', "Stashed <pages>{$val}</pages> as PI");

                $article->removeChild($p);
                Xml::dbg($debug, 3, '➖', "Removed <{$val}> pages node(s)");
            }
        }

        // Drop legacy/unsupported elements
        foreach (['indexing', 'open_access'] as $drop) {
            $removed = 0;
            foreach (iterator_to_array($xp->query('./*[local-name()="'.$drop.'"]', $article)) as $el) {
                $article->removeChild($el);
                $removed++;
            }
            if ($removed) Xml::dbg($debug, 3, '➖', "Removed {$removed} <{$drop}> node(s)");
        }

        // Reorder article children (lightweight)
        Xml::reorderChildren($article, [
            'id','title','prefix','subtitle','abstract',
            'coverage','type','source','rights',
            'comments_to_editor','authors','submission_file','article_galley','representation'
        ]);
    }

    private function foldPermissionsToRights(DOMDocument $doc, DOMElement $permissions): array
    {
        $parts = [];
        foreach (iterator_to_array($permissions->childNodes) as $child) {
            if (!$child instanceof DOMElement) continue;
            $lname = $child->localName ?: $child->nodeName;
            $txt = trim($child->textContent);
            if ($txt === '') continue;
            if ($lname === 'license_url')          $parts[] = $txt;
            elseif ($lname === 'copyright_holder') $parts[] = $txt;
            elseif ($lname === 'copyright_year')   $parts[] = '© ' . $txt;
        }
        if (!$parts) return [];
        $r = $doc->createElementNS(Xml::PKP_NS, 'rights', implode(' ', $parts));
        if ($permissions->hasAttribute('locale')) {
            $r->setAttribute('locale', $permissions->getAttribute('locale'));
        }
        return [$r];
    }

    private function emitSubmissionFileAndGalley(
        DOMDocument $doc,
        DOMElement $article,
        DOMElement $fileIn,
        int $id,
        ?string $galleyLocale,
        bool $debug
    ): void {
        // <submission_file stage="submission" id="...">
        $sf = $doc->createElementNS(Xml::PKP_NS, 'submission_file');
        $sf->setAttribute('stage', 'submission');
        $sf->setAttribute('id', (string)$id);

        // <revision number="1" genre="Article Text" user_group_ref="Author">
        $rev = $doc->createElementNS(Xml::PKP_NS, 'revision');
        $rev->setAttribute('number', '1');
        $rev->setAttribute('genre', 'Article Text');
        $rev->setAttribute('user_group_ref', 'Author');

        // Decide embed vs href
        $embed = $this->firstChildNoNs($fileIn, 'embed');
        $href  = $this->firstChildNoNs($fileIn, 'href');

        if ($embed instanceof DOMElement) {
            $filetype = $embed->getAttribute('mime_type') ?: null;
            $filename = $embed->getAttribute('filename') ?: 'file';
            $filesize = $this->filesizeFromEmbed($embed);

            $rev->setAttribute('filename', $filename);
            if ($filetype) $rev->setAttribute('filetype', $filetype);
            $rev->setAttribute('filesize', (string)$filesize);

            if ($galleyLocale) {
                $nm = $doc->createElementNS(Xml::PKP_NS, 'name', $filename);
                $nm->setAttribute('locale', $galleyLocale);
                $rev->appendChild($nm);
            }

            $embOut = $doc->createElementNS(Xml::PKP_NS, 'embed');
            if ($embed->hasAttribute('encoding')) {
                $embOut->setAttribute('encoding', $embed->getAttribute('encoding'));
            }
            foreach (iterator_to_array($embed->childNodes) as $n) {
                $embOut->appendChild($doc->importNode($n, true));
            }
            $rev->appendChild($embOut);

            Xml::dbg($debug, 4, '📎', "Built submission_file#{$id} (embed) filename='{$filename}', type='{$filetype}', size={$filesize}");
        } elseif ($href instanceof DOMElement) {
            $filetype = $href->getAttribute('mime_type') ?: null;
            $src = $href->getAttribute('src') ?: '';
            $filename = $this->parseFilename($src) ?: 'file';

            $rev->setAttribute('filename', $filename);
            if ($filetype) $rev->setAttribute('filetype', $filetype);
            $rev->setAttribute('filesize', '0');

            $nm = $doc->createElementNS(Xml::PKP_NS, 'name', $filename);
            if ($galleyLocale) $nm->setAttribute('locale', $galleyLocale);
            $rev->appendChild($nm);

            $hrefOut = $doc->createElementNS(Xml::PKP_NS, 'href');
            if ($src !== '') $hrefOut->setAttribute('src', $src);
            $rev->appendChild($hrefOut);

            Xml::dbg($debug, 4, '📎', "Built submission_file#{$id} (href) filename='{$filename}', type='{$filetype}', src='{$src}'");
        } else {
            // Fallback
            $rev->setAttribute('filename', 'file');
            $rev->setAttribute('filetype', 'application/octet-stream');
            $rev->setAttribute('filesize', '0');
            Xml::dbg($debug, 4, '📎', "Built submission_file#{$id} (fallback) filename='file', type='application/octet-stream', size=0");
        }

        $sf->appendChild($rev);
        $article->appendChild($sf);

        // <article_galley> referencing the submission_file
        $gal = $doc->createElementNS(Xml::PKP_NS, 'article_galley');
        $nameNode = $doc->createElementNS(Xml::PKP_NS, 'name', 'PDF');
        $nameNode->setAttribute('locale', $galleyLocale ?: 'en_US');
        $gal->appendChild($nameNode);
        $gal->appendChild($doc->createElementNS(Xml::PKP_NS, 'seq', '0'));

        $ref = $doc->createElementNS(Xml::PKP_NS, 'submission_file_ref');
        $ref->setAttribute('id', (string)$id);
        $ref->setAttribute('revision', '1');
        $gal->appendChild($ref);

        $article->appendChild($gal);
        Xml::dbg($debug, 4, '📎', "Built article_galley referencing submission_file#{$id} rev=1");
    }

    private function firstChildNoNs(DOMElement $parent, string $name): ?DOMElement
    {
        for ($n = $parent->firstChild; $n; $n = $n->nextSibling) {
            if ($n instanceof DOMElement && ($n->localName === $name || $n->nodeName === $name)) {
                return $n;
            }
        }
        return null;
    }

    private function parseFilename(?string $src): ?string
    {
        if ($src === null) return null;
        $src = trim($src);
        if ($src === '') return null;
        $parts = preg_split('~[/\\\\\r\n\t]+~', $src);
        return $parts ? end($parts) : $src;
    }

    private function filesizeFromEmbed(DOMElement $embed): int
    {
        $encoding = strtolower($embed->getAttribute('encoding'));
        $data = '';
        foreach (iterator_to_array($embed->childNodes) as $n) {
            if ($n->nodeType === XML_TEXT_NODE || $n->nodeType === XML_CDATA_SECTION_NODE) {
                $data .= $n->nodeValue;
            }
        }
        $data = preg_replace('~\s+~', '', (string)$data);
        if ($encoding !== 'base64' || $data === '') {
            return max(0, strlen($data));
        }
        $len = strlen($data);
        if ($len === 0) return 0;
        $pad = 0;
        if ($data[$len - 1] === '=') $pad++;
        if ($len > 1 && $data[$len - 2] === '=') $pad++;
        return max(0, (int) floor($len * 3 / 4) - $pad);
    }

    private function articleDesc(DOMElement $article): string
    {
        $title = '';
        for ($n = $article->firstChild; $n; $n = $n->nextSibling) {
            if ($n instanceof DOMElement && $n->localName === 'title') {
                $title = trim($n->textContent);
                if ($title !== '') break;
            }
        }
        $id = '';
        for ($n = $article->firstChild; $n; $n = $n->nextSibling) {
            if ($n instanceof DOMElement && $n->localName === 'id') {
                $idType = $n->getAttribute('type');
                $idVal = trim($n->textContent);
                if ($idVal !== '') { $id = $idType ? "{$idType}:{$idVal}" : $idVal; break; }
            }
        }
        $bits = [];
        if ($title !== '') $bits[] = "title=\"{$title}\"";
        if ($id !== '')    $bits[] = "id={$id}";
        return $bits ? ('[' . implode(' ', $bits) . ']') : '(untitled)';
    }

    // Unicode-aware trim: also strips NBSP (U+00A0) and collapses whitespace
    private function uTrim(string $s): string
    {
        $s = preg_replace('/\x{00A0}/u', ' ', $s); // NBSP → space
        $s = preg_replace('/\s+/u', ' ', $s);      // collapse whitespace
        return trim($s);
    }

    /**
     * Find the FIRST non-empty <abbrev> under $ctx; Unicode-trims and logs.
     * $phase is an optional label for dbg (e.g., 'pre-copy', 'post-copy').
     */
    private function firstNonEmptyAbbrev(DOMXPath $xp, DOMElement $ctx, bool $debug, string $phase = ''): string
    {
        $nodes = iterator_to_array($xp->query('./*[local-name()="abbrev"]', $ctx));
        $who = $ctx->localName ?: $ctx->nodeName;
        Xml::dbg($debug, 2, 'ℹ', ($phase ? "{$phase}: " : '') . "in <{$who}> found " . count($nodes) . " <abbrev> node(s)");

        foreach ($nodes as $i => $el) {
            /** @var DOMElement $el */
            $raw = $el->textContent;
            $txt = $this->uTrim($raw);
            $loc = $el->getAttribute('locale') ?: '—';
            Xml::dbg(
                $debug,
                3,
                '·',
                ($phase ? "{$phase}: " : '') .
                "abbrev[".($i+1)."] locale='{$loc}' raw='".str_replace(["\n","\r","\t"],['⏎','⏎','⇥'],$raw)."' trimmed='{$txt}'"
            );
            if ($txt !== '') {
                Xml::dbg($debug, 2, '✚', ($phase ? "{$phase}: " : '') . "using section_ref='{$txt}'");
                return $txt;
            }
        }
        Xml::dbg($debug, 2, '⚠', ($phase ? "{$phase}: " : '') . 'all <abbrev> values empty after trim');
        return '';
    }
}
