<?php
declare(strict_types=1);

namespace OJS;

use DOMDocument;
use RuntimeException;

final class SchemaValidator
{
    /**
     * Validate an XML (path or DOM) for a given version.
     *
     * @param DOMDocument|string $input  DOMDocument instance or path to XML file
     * @param string             $version e.g. "3.0.0" or "2.4.8"
     * @param bool               $log     enable logging
     * @param callable|null      $logger  optional logger: fn(int $indent, string $icon, string $msg): void
     */
    public function validate(DOMDocument|string $input, string $version, bool $log = false, ?callable $logger = null): void
    {
        $prevErr = \libxml_use_internal_errors(true);

        // Logger (uses Xml::dbg if available; else STDERR)
        $logFn = function (int $indent, string $icon, string $msg) use ($log, $logger): void {
            if (!$log) return;
            if ($logger) { $logger($indent, $icon, $msg); return; }
            if (class_exists('\OJS\PhpTransform\Util\Xml') && method_exists('\OJS\PhpTransform\Util\Xml', 'dbg')) {
                \OJS\PhpTransform\Util\Xml::dbg(true, $indent, $icon, $msg);
            } else {
                fwrite(STDERR, str_repeat('  ', max(0, $indent)) . ($icon ?: '–') . ' ' . $msg . PHP_EOL);
            }
        };

        // Prepare DOM
        $doc = ($input instanceof DOMDocument) ? $input : $this->loadDomFromPath($input);

        // Resolve version directory automatically
        $versionDir = $this->resolveVersionDir($version);
        if (!$versionDir) {
            $logFn(0, '–', "Skipping validation for {$version} (could not locate version directory).");
            \libxml_use_internal_errors($prevErr);
            return;
        }

        // Preferred XSD (plugins/importexport/native/native.xsd)
        $xsdNative = $versionDir . DIRECTORY_SEPARATOR . 'plugins'
            . DIRECTORY_SEPARATOR . 'importexport'
            . DIRECTORY_SEPARATOR . 'native'
            . DIRECTORY_SEPARATOR . 'native.xsd';

        // Fallback XSD (lib/pkp/xml/importexport.xsd)
        $xsdFallback = $versionDir . DIRECTORY_SEPARATOR . 'lib'
            . DIRECTORY_SEPARATOR . 'pkp'
            . DIRECTORY_SEPARATOR . 'xml'
            . DIRECTORY_SEPARATOR . 'importexport.xsd';

        // DTD fallback: version root (xsd/<ver>/native.dtd or <root>/<ver>/native.dtd)
        $dtdRoot = $versionDir . DIRECTORY_SEPARATOR . 'native.dtd';

        $schema = null;
        $schemaType = null;

        if (is_file($xsdNative)) {
            $schema = $xsdNative;
            $schemaType = 'xsd';

            // Ensure required include exists for the native schema
            $pkpNative = $versionDir . DIRECTORY_SEPARATOR . 'lib'
                . DIRECTORY_SEPARATOR . 'pkp'
                . DIRECTORY_SEPARATOR . 'plugins'
                . DIRECTORY_SEPARATOR . 'importexport'
                . DIRECTORY_SEPARATOR . 'native'
                . DIRECTORY_SEPARATOR . 'pkp-native.xsd';
            if (!is_file($pkpNative)) {
                $logFn(0, '❌', "Missing required include for $version: $pkpNative");
                \libxml_use_internal_errors($prevErr);
                throw new RuntimeException("Missing required include for $version: $pkpNative");
            }
        } elseif (is_file($xsdFallback)) {
            $schema = $xsdFallback;
            $schemaType = 'xsd';
        } elseif (is_file($dtdRoot)) {
            $schema = $dtdRoot;
            $schemaType = 'dtd';
        }

        // No schema found: keep non-fatal behavior (skip with log)
        if ($schema === null) {
            $logFn(0, '–', "Skipping validation for {$version} (no XSD found; no DTD at {$dtdRoot}).");
            \libxml_use_internal_errors($prevErr);
            return;
        }

        if ($schemaType === 'xsd') {
            // Windows/relative include fix: chdir() to the schema dir so xs:include resolves reliably
            $schemaDir  = \dirname($schema);
            $schemaFile = \basename($schema);

            $logFn(0, '–', "Validating input against {$version} native.xsd …");
            $logFn(1, '🔧', "xsd: {$schema}");

            $cwd = getcwd();
            chdir($schemaDir);

            \libxml_clear_errors();
            $ok = $doc->schemaValidate($schemaFile);

            chdir($cwd);

            if (!$ok) {
                $msg = $this->formatLibxmlErrors();
                $logFn(0, '❌', "Schema (XSD) validation failed for {$version}:\n{$msg}");
                \libxml_use_internal_errors($prevErr);
                throw new RuntimeException("Schema (XSD) validation failed for {$version}:\n{$msg}");
            }
            $logFn(0, '✅', "Input is valid for {$version}");
            \libxml_use_internal_errors($prevErr);
            return;
        }

        // DTD path (e.g., 2.4.8)
        $logFn(0, '–', "Validating input against {$version} native.dtd …");
        $logFn(1, '🔧', "dtd: {$schema}");

        $root = $doc->documentElement;
        if (!$root) {
            $logFn(0, '❌', "No root element found; cannot validate.");
            \libxml_use_internal_errors($prevErr);
            throw new RuntimeException("No root element found; cannot validate.");
        }

        $rootName = $root->tagName; // "articles" or "issues"
        $publicId = '-//PKP//OJS Articles and Issues XML//EN';
        $dtdUri   = $this->toFileUri($schema);

        $xmlWithDoctype =
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<!DOCTYPE ' . $rootName . ' PUBLIC "' . $publicId . '" "' . $dtdUri . '">' . "\n" .
            $doc->saveXML($root);

        $tmp = new DOMDocument('1.0', 'UTF-8');
        $tmp->preserveWhiteSpace = false;
        $tmp->formatOutput = false;
        $tmp->resolveExternals = true;    // allow reading local file:// DTD
        $tmp->substituteEntities = false;
        $tmp->validateOnParse = true;

        \libxml_clear_errors();
        $ok = $tmp->loadXML($xmlWithDoctype, LIBXML_DTDLOAD | LIBXML_DTDVALID | LIBXML_NONET);
        $ok = $ok && $tmp->validate();

        if (!$ok) {
            $msg = $this->formatLibxmlErrors();
            $logFn(0, '❌', "Schema (DTD) validation failed for {$version}:\n{$msg}");
            \libxml_use_internal_errors($prevErr);
            throw new RuntimeException("Schema (DTD) validation failed for {$version}:\n{$msg}");
        }
        $logFn(0, '✅', "Input is valid for {$version}");
        \libxml_use_internal_errors($prevErr);
    }

    /* ------------------------ helpers ------------------------ */

    private function loadDomFromPath(string $xmlPath): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        if (!$doc->load($xmlPath, LIBXML_NONET)) {
            throw new RuntimeException("Failed to load XML: {$xmlPath}");
        }
        return $doc;
    }

    private function toFileUri(string $path): string
    {
        $real = \realpath($path) ?: $path;
        $real = str_replace('\\', '/', $real);
        if (!str_starts_with($real, '/')) $real = '/' . $real; // Windows drive
        return 'file://' . $real;
    }

    private function formatLibxmlErrors(): string
    {
        $out = [];
        foreach (\libxml_get_errors() as $e) {
            $line = $e->line ? " at line {$e->line}" : "";
            $out[] = trim($e->message) . $line;
        }
        \libxml_clear_errors();
        return implode("\n", $out);
    }

    /**
     * Try to find the version directory automatically.
     * Looks for:
     *   <root>/xsd/<version>/
     *   <root>/<version>/
     * starting from a set of candidate roots (CWD, __DIR__, parents, or OJS_SCHEMA_BASE).
     */
    private function resolveVersionDir(string $version): ?string
    {
        $ver = preg_replace('/[^0-9.]/', '', $version) ?: $version;

        $roots = array_unique(array_filter([
            getenv('OJS_SCHEMA_BASE') ?: null, // optional override
            getcwd(),
            __DIR__,
            \dirname(__DIR__),
            \dirname(__DIR__, 2),
            \dirname(__DIR__, 3),
            \dirname(__DIR__, 4),
        ]));

        foreach ($roots as $root) {
            // Prefer xsd/<ver>
            $p1 = $root . DIRECTORY_SEPARATOR . 'xsd' . DIRECTORY_SEPARATOR . $ver;
            if (is_dir($p1)) return $p1;

            // Fallback: <ver> at root
            $p2 = $root . DIRECTORY_SEPARATOR . $ver;
            if (is_dir($p2)) return $p2;
        }
        return null;
    }
}
