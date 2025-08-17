<?php
namespace OJS\PhpTransform\Transformers;

use DOMDocument;

final class NoChangesHop

{
    public function transform(DOMDocument $doc, bool $debug): DOMDocument
    {
        // No breaking changes; intentionally no-ops.
        return $doc;
    }
}
