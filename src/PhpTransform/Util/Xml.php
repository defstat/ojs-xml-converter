<?php
namespace OJS\PhpTransform\Util;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class Xml
{
    /** Default PKP namespace used by OJS native XML (3.x). */
    public const PKP_NS = 'http://pkp.sfu.ca';
    /** XML Schema Instance namespace (xsi). */
    public const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';

    /* =======================
     * Processing-Instruction helpers (safe “sticky notes” in XML)
     * ======================= */

    /** PI target used for inline instructions in the XML. */
    public const INSTR_PI = 'ojs-instr';

    // Helper: move all children with a given local-name from $from → $to
    static function moveAllChildren (DOMXPath $xp, DOMDocument $doc, DOMElement $from, DOMElement $to, string $lname): void {
        foreach (iterator_to_array($xp->query('./*[local-name()="'.$lname.'"]', $from)) as $n) {
            /** @var DOMElement $n */
            $to->appendChild($n); // move
        }
    }

    /**
     * Add or update a small instruction on $ctx, stored as a Processing Instruction.
     * - Key will be stored as k="..."; value is base64-url and stored as v="b64:...".
     * - Validators/importers ignore PIs, so this won't “mess” with import code.
     */
    public static function addInstruction(DOMElement $ctx, string $key, string $value, bool $overwrite = true, bool $atStart = true): void
    {
        $doc = $ctx->ownerDocument;
        if (!$doc) return;

        $xp = new DOMXPath($doc);
        foreach ($xp->query('./processing-instruction("' . self::INSTR_PI . '")', $ctx) as $pi) {
            /** @var \DOMProcessingInstruction $pi */
            $kv = self::parsePiData($pi->data);
            if (($kv['k'] ?? null) === $key) {
                if (!$overwrite) return;
                $pi->data = self::formatPiData($key, $value);
                return;
            }
        }

        $pi = $doc->createProcessingInstruction(self::INSTR_PI, self::formatPiData($key, $value));
        if ($atStart && $ctx->firstChild) {
            $ctx->insertBefore($pi, $ctx->firstChild);
        } else {
            $ctx->appendChild($pi);
        }
    }

    /**
     * Read a previously stored instruction by key from $ctx.
     * @param bool $remove Remove the PI after reading (default: true)
     * @return string|null Decoded value or null if not found
     */
    public static function getInstruction(DOMElement $ctx, string $key, bool $remove = true): ?string
    {
        $doc = $ctx->ownerDocument;
        if (!$doc) return null;

        $xp = new DOMXPath($doc);
        foreach ($xp->query('./processing-instruction("' . self::INSTR_PI . '")', $ctx) as $pi) {
            /** @var \DOMProcessingInstruction $pi */
            $kv = self::parsePiData($pi->data);
            if (($kv['k'] ?? null) === $key && isset($kv['v'])) {
                $val = self::decodePi($kv['v']);
                if ($remove) {
                    $pi->parentNode?->removeChild($pi);
                }
                return $val;
            }
        }
        return null;
    }

    /** Internal: format PI data as k=".." v="b64:.." */
    private static function formatPiData(string $key, string $value): string
    {
        return 'k="' . self::escapePi($key) . '" v="' . self::escapePi(self::encodePi($value)) . '"';
    }

    /** Internal: parse PI data into assoc array of key/value pairs. */
    private static function parsePiData(string $data): array
    {
        $out = [];
        if (preg_match_all('/(\w+)\s*=\s*"([^"]*)"/u', $data, $m, PREG_SET_ORDER)) {
            foreach ($m as $p) {
                $out[$p[1]] = $p[2];
            }
        }
        return $out;
    }

    /** Internal: base64-url encode and mark with b64: */
    private static function encodePi(string $v): string
    {
        $b = rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        return 'b64:' . $b;
    }

    /** Internal: decode value produced by encodePi(). */
    private static function decodePi(string $v): string
    {
        if (!str_starts_with($v, 'b64:')) return $v;
        $b = substr($v, 4);
        $b = strtr($b, '-_', '+/');
        $pad = strlen($b) % 4;
        if ($pad) $b .= str_repeat('=', 4 - $pad);
        $res = base64_decode($b, true);
        return $res === false ? '' : $res;
    }

    /** Internal: avoid closing the PI accidentally. */
    private static function escapePi(string $s): string
    {
        return str_replace('?>', '?&gt;', $s);
    }


    /**
     * Simple debug printer used everywhere.
     */
    public static function dbg(bool $enabled, int $indent, string $symbol, string $message): void
    {
        if (!$enabled) return;
        $pad = str_repeat('  ', max(0, $indent));
        // Print to STDOUT to behave like the rest of the CLI messages
        fwrite(STDOUT, "{$pad}{$symbol} {$message}\n");
    }

    /**
     * Create a DOMXPath for a document and pre-register common namespaces when present.
     * Works with both non-namespaced (e.g. OJS 2.4.x) and PKP-namespaced (OJS 3.x) XML.
     */
    public static function xp(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);

        // Register well-known namespaces if they’re present in the document.
        $root = $doc->documentElement;
        if ($root instanceof DOMElement) {
            $ns = $root->namespaceURI;
            if ($ns) {
                // Default PKP namespace (from the document)
                $xp->registerNamespace('pkp', $ns);
            }
            // XSI is commonly used for schemaLocation
            $xp->registerNamespace('xsi', self::XSI_NS);
        }
        return $xp;
    }

    /**
     * Iterate XPath results as DOMElement items, auto-rewriting bare element names to
     * namespace-agnostic selectors when the document has a default namespace.
     *
     * Example:
     *   iter($xp, '//article') →
     *     - in non-NS docs: //article
     *     - in NS docs:     //*[local-name()="article"]
     */
    public static function iter(DOMXPath $xp, string $expr, ?DOMNode $ctx = null): \Generator
    {
        $doc = $xp->document;
        $hasDefaultNs = $doc->documentElement instanceof DOMElement
            && (string)$doc->documentElement->namespaceURI !== '';

        $query = $expr;

        // If document has a default namespace but the XPath is unprefixed and not already using local-name(),
        // rewrite simple node tests like //article/label → //*[…="article"]/*[…="label"]
        if ($hasDefaultNs && strpos($expr, ':') === false && stripos($expr, 'local-name(') === false) {
            $query = self::nsAgnostic($expr);
        }

        $nodes = $xp->query($query, $ctx);
        if ($nodes) {
            /** @var \DOMNode $n */
            foreach ($nodes as $n) {
                if ($n instanceof DOMElement) {
                    yield $n;
                }
            }
        }
    }

    /**
     * Turn an XPath with bare element names into a namespace-agnostic one using local-name().
     * This is intentionally simple and covers the expressions we use in transforms (//, ./, no functions/prefixes).
     */
    private static function nsAgnostic(string $expr): string
    {
        // Replace node tests not preceded by '@' and not followed by '(' (i.e., not functions).
        // Examples:
        //  .//article            → .//*[local-name()="article"]
        //  ./author              → ./*[local-name()="author"]
        //  //article/label       → //*[local-name()="article"]/*[local-name()="label"]
        //  ./a | ./b             → ./*[local-name()="a"] | ./*[local-name()="b"]
        $pattern = '/(?<!@)(?<=\/|^|\(|\s)([A-Za-z_][A-Za-z0-9\-\._]*)(?!\s*\()/u';
        $replace = '*[local-name()="$1"]';
        return preg_replace($pattern, $replace, $expr);
    }

    /**
     * Validate a DOMDocument against an XSD. Throws RuntimeException with a readable error list.
     */
    public static function validateOrThrow(DOMDocument $doc, string $xsdPath, string $label): void
    {
        if (!is_file($xsdPath)) {
            throw new RuntimeException("XSD not found: {$xsdPath}");
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $ok = $doc->schemaValidate($xsdPath);

        $errs = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($ok) {
            return;
        }

        // Build a readable error message (similar to libxml’s CLI messages)
        $lines = [];
        foreach ($errs as $e) {
            $code = $e->code;
            $line = $e->line;
            $col  = $e->column;
            $msg  = trim($e->message);
            // Normalize newlines inside message
            $msg = preg_replace("/\s+/", ' ', $msg);
            $lines[] = "Error (code {$code}) at line {$line}, col {$col}: {$msg}";
        }

        $joined = $lines ? implode("\n", $lines) : 'Unknown validation error.';
        throw new RuntimeException("XSD validation failed ({$label}) against {$xsdPath}:\n{$joined}");
    }

    /**
     * Utility: rename an element without DOMDocument::renameNode (for older libxml/PHP).
     * Returns the new element.
     */
    public static function renameElement(DOMDocument $doc, DOMElement $el, string $newName): DOMElement
    {
        $replacement = $doc->createElement($newName);

        // copy attributes
        if ($el->hasAttributes()) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                $replacement->setAttribute($attr->nodeName, $attr->nodeValue);
            }
        }
        // move children
        while ($el->firstChild) {
            $replacement->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($replacement, $el);
        return $replacement;
    }

    /**
     * Convenience: get first child element by tag name (no namespace).
     */
    public static function firstChild(DOMElement $ctx, string $name): ?DOMElement
    {
        for ($n = $ctx->firstChild; $n; $n = $n->nextSibling) {
            if ($n instanceof DOMElement && $n->tagName === $name) {
                return $n;
            }
        }
        return null;
    }

    /**
     * Locale mapper used by some hops (e.g., 3.3→3.4). Safe pass-through for unknowns.
     * Converts "en_US" → "en", "pt_BR" → "pt", etc.
     */
    public static function mapLocale(?string $locale): ?string
    {
        if ($locale === null || $locale === '') return $locale;
        // If pattern xx_XX, drop the region part
        if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
            return substr($locale, 0, 2);
        }
        return $locale;
    }

    /**
     * Ensure exactly one scalar child element $localName exists under $parent with text $text.
     * If one exists and is empty, populate it; otherwise create a new one.
     */
    public static function ensureSingleScalarChild(
        DOMDocument $doc,
        DOMXPath $xp,
        DOMElement $parent,
        string $localName,
        string $text,
        string $ns
    ): DOMElement {
        $existing = $xp->query('./*[local-name()="'.$localName.'"]', $parent)->item(0);
        if ($existing instanceof DOMElement) {
            if (trim($existing->textContent) === '') {
                $existing->nodeValue = $text;
            }
            return $existing;
        }
        $el = $doc->createElementNS($ns, $localName, $text);
        $parent->appendChild($el);
        return $el;
    }

    /**
     * Reorder child elements of $parent to match $order.
     * Preserves original order within each bucket and appends leftovers at the end.
     */
    public static function reorderChildren(DOMElement $parent, array $order): void
    {
        // Bucket current element children by local-name()
        $buckets = [];
        for ($n = $parent->firstChild; $n; $n = $n->nextSibling) {
            if ($n instanceof DOMElement) {
                $lname = $n->localName ?: $n->nodeName;
                $buckets[$lname][] = $n;
            }
        }
        // Remove element children
        for ($n = $parent->firstChild; $n;) {
            $next = $n->nextSibling;
            if ($n instanceof DOMElement) $parent->removeChild($n);
            $n = $next;
        }
        // Append in requested order
        foreach ($order as $name) {
            if (!empty($buckets[$name])) {
                foreach ($buckets[$name] as $el) {
                    $parent->appendChild($el);
                }
                unset($buckets[$name]);
            }
        }
        // Append leftovers (defensive)
        foreach ($buckets as $leftovers) {
            foreach ($leftovers as $el) {
                $parent->appendChild($el);
            }
        }
    }

    /**
     * Ensure the root has PKP default namespace, XSI binding, and schemaLocation.
     */
    public static function ensureRootNsAndSchema(DOMDocument $doc, string $schemaFile = 'native.xsd'): void
    {
        $root = $doc->documentElement;
        if (!$root instanceof DOMElement) return;

        // Default PKP namespace on root
        if (!$root->namespaceURI || $root->namespaceURI === '') {
            $root->setAttribute('xmlns', self::PKP_NS);
            // Reparse so DOM picks up the new default namespace for element nodes
            $tmp = new DOMDocument('1.0', 'UTF-8');
            $tmp->preserveWhiteSpace = $doc->preserveWhiteSpace;
            $tmp->formatOutput = $doc->formatOutput;
            $tmp->loadXML($doc->saveXML(), LIBXML_NONET);
            $doc->replaceChild($doc->importNode($tmp->documentElement, true), $root);
            $root = $doc->documentElement;
        }

        // Bind xsi prefix on root
        if ($root->lookupNamespaceURI('xsi') !== self::XSI_NS) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
        }

        // Set schemaLocation on root if missing
        $hasSchema = $root->hasAttributeNS(self::XSI_NS, 'schemaLocation');
        if (!$hasSchema) {
            $root->setAttributeNS(self::XSI_NS, 'xsi:schemaLocation', self::PKP_NS . ' ' . $schemaFile);
        }
    }

    /**
     * Stamp xsi binding + schemaLocation onto *every* PKP element (optional, verbose).
     * Use ONLY if you truly want per-element repetition like your export sample.
     */
    public static function stampSchemaOnAllElements(DOMDocument $doc, string $schemaFile = 'native.xsd'): void
    {
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*') as $el) {
            if (!$el instanceof DOMElement) continue;

            // Only touch elements that are (or should be) in PKP NS
            $inPkp = ($el->namespaceURI === self::PKP_NS) || ($el->namespaceURI === null || $el->namespaceURI === '');
            if (!$inPkp) continue;

            // Ensure element is in PKP NS; if it has no ns, rename it into PKP ns
            if ($el->namespaceURI !== self::PKP_NS) {
                $new = $doc->createElementNS(self::PKP_NS, $el->localName);
                // move children + attributes
                while ($el->firstChild) $new->appendChild($el->removeChild($el->firstChild));
                foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                    $new->setAttribute($attr->name, $attr->value);
                }
                $el->parentNode->replaceChild($new, $el);
                $el = $new;
            }

            // Bind xsi prefix if this element doesn't see it in scope
            if ($el->lookupNamespaceURI('xsi') !== self::XSI_NS) {
                $el->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
            }

            // Add schemaLocation on the element if it doesn't already have it
            if (!$el->hasAttributeNS(self::XSI_NS, 'schemaLocation')) {
                $el->setAttributeNS(self::XSI_NS, 'xsi:schemaLocation', self::PKP_NS . ' ' . $schemaFile);
            }
        }
    }
}
