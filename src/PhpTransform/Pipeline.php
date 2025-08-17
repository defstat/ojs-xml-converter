<?php
namespace OJS\PhpTransform;

use DOMDocument;
use OJS\PhpTransform\Transformers\Hop_2_4_8_to_3_0_0;
use OJS\PhpTransform\Transformers\Hop_3_0_0_to_3_0_1;
use OJS\PhpTransform\Transformers\Hop_3_0_1_to_3_0_2;
use OJS\PhpTransform\Transformers\Hop_3_0_2_to_3_1_0;
use OJS\PhpTransform\Transformers\Hop_3_1_0_to_3_1_1;
use OJS\PhpTransform\Transformers\Hop_3_1_1_to_3_2_0;
use OJS\PhpTransform\Transformers\Hop_3_2_0_to_3_3_0;
use OJS\PhpTransform\Transformers\Hop_3_3_0_to_3_4_0;
use OJS\PhpTransform\Transformers\Hop_3_4_0_to_3_5_0;
use OJS\PhpTransform\Transformers\NoChangesHop;
use OJS\SchemaValidator;
use RuntimeException;
use OJS\PhpTransform\Util\Xml;

final class Pipeline
{
    /** @var bool */
    private $validateStrict;

    private const EDGES = [
        '2.4.8' => [ '3.0.0' => Hop_2_4_8_to_3_0_0::class ],
        '3.0.0' => [ '3.0.1' => Hop_3_0_0_to_3_0_1::class ],
        '3.0.1' => [ '3.0.2' => Hop_3_0_1_to_3_0_2::class ],
        '3.0.2' => [ '3.1.0' => Hop_3_0_2_to_3_1_0::class ],
        '3.1.0' => [ '3.1.1' => Hop_3_1_0_to_3_1_1::class ],
        '3.1.1' => [ '3.2.0' => Hop_3_1_1_to_3_2_0::class ],
        '3.2.0' => [ '3.3.0' => Hop_3_2_0_to_3_3_0::class ],
        '3.3.0' => [ '3.4.0' => Hop_3_3_0_to_3_4_0::class ],
        '3.4.0' => [ '3.5.0' => Hop_3_4_0_to_3_5_0::class ],
    ];

    public function __construct(bool $validateStrict)
    {
        $this->validateStrict = $validateStrict;
    }

    public function run(DOMDocument $doc, string $fromVersion, string $toVersion, bool $debug = false): DOMDocument
    {
        Xml::dbg($debug, 0, '🔧', 'project root: ' . $this->projectRoot());

        $route = $this->findRoute($fromVersion, $toVersion);
        Xml::dbg($debug, 0, '▶', 'Route: ' . implode(' → ', $route));

        // Pre-validate input (if XSD exists)
        if ($this->validateStrict) {
            $validator = new SchemaValidator();
            $validator->validate(
                $doc,
                $fromVersion,
                /* log */ $debug,
                /* logger */ fn(int $indent, string $icon, string $msg)
                    => Xml::dbg($debug, $indent, $icon, $msg)
            );
        }

        // Hop-by-hop
        for ($i = 0; $i < count($route) - 1; $i++) {
            $from = $route[$i];
            $to   = $route[$i + 1];
            $class = $this->getTransformerClass($from, $to);

            Xml::dbg($debug, 0, '▶', "Hop {$from} → {$to} using {$class}");

            $transformer = new $class();
            $doc = $transformer->transform($doc, $from, $to, $debug);

            Xml::ensureRootNsAndSchema($doc, 'native.xsd');

            if ($this->validateStrict) {
                $validator = new SchemaValidator();
                $validator->validate(
                    $doc,
                    $to,
                    $debug,
                    fn(int $indent, string $icon, string $msg)
                        => Xml::dbg($debug, $indent, $icon, $msg)
                );
            }
        }

        if ($this->validateStrict) {
            $validator = new SchemaValidator();
            $validator->validate(
                $doc,
                $toVersion,
                $debug,
                fn(int $indent, string $icon, string $msg)
                    => Xml::dbg($debug, $indent, $icon, $msg)
            );
        }

        // Xml::ensureRootNsAndSchema($doc, 'native.xsd');

        Xml::dbg($debug, 0, '✅', "Pipeline complete: {$fromVersion} → {$toVersion}");
        return $doc;
    }

    private function getTransformerClass(string $from, string $to): string
    {
        if (!isset(self::EDGES[$from][$to])) {
            throw new RuntimeException("No transformer registered for hop {$from} → {$to}.");
        }
        return self::EDGES[$from][$to];
    }

    private function findRoute(string $from, string $to): array
    {
        if ($from === $to) return [$from];

        $queue = [[$from]];
        $visited = [$from => true];

        while ($queue) {
            $path = array_shift($queue);
            $node = end($path);
            foreach (self::EDGES[$node] ?? [] as $next => $_class) {
                if (isset($visited[$next])) continue;
                $visited[$next] = true;
                $new = $path; $new[] = $next;
                if ($next === $to) return $new;
                $queue[] = $new;
            }
        }

        $msg = "No route found from {$from} to {$to}. Available edges:\n";
        foreach (self::EDGES as $src => $dsts) {
            $msg .= "  {$src} → [" . implode(', ', array_keys($dsts)) . "]\n";
        }
        throw new RuntimeException(rtrim($msg));
    }

    private function isXsdEra(string $version): bool
    {
        return preg_match('/^3\./', $version) === 1;
    }

    /** Absolute project root (…/ojs-xml-converter). */
    private function projectRoot(): string
    {
        // __DIR__ = …/src/PhpTransform
        // up 1 => …/src
        // up 2 => …/ojs-xml-converter  ← we want this
        return \dirname(__DIR__, 2);
    }

    /** Candidate XSD paths (under the project root). */
    private function candidateXsdPaths(string $version): array
    {
        $root = $this->projectRoot();
        return [
            $root . "/xsd/{$version}/plugins/importexport/native/native.xsd",
            $root . "/src/xsd/{$version}/plugins/importexport/native/native.xsd",
        ];
    }

    /** Returns the first existing XSD path or null. */
    private function xsdPathForVersion(string $version): ?string
    {
        foreach ($this->candidateXsdPaths($version) as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }
}
