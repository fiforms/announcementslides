<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use enshrined\svgSanitize\Sanitizer;

/**
 * Makes user-supplied SVG safe to serve from the public disk and to inline in
 * the overlay editor: strips scripts, event handlers, foreignObject and
 * remote references (via enshrined/svg-sanitize), then additionally drops any
 * href that isn't a local fragment or an embedded raster image — overlays
 * are displayed through <img> / rasterized on devices, where external files
 * can't load anyway, so allowing them would only enable tracking/phishing.
 *
 * Returns null when the input isn't parseable SVG with usable dimensions.
 */
class SvgSanitizer
{
    public function sanitize(string $svg): ?string
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(true);

        try {
            $clean = $sanitizer->sanitize($svg);
        } catch (\Throwable) {
            // e.g. LogicException when the document has no <svg> root
            return null;
        }
        if ($clean === false || trim($clean) === '') {
            return null;
        }

        $doc = new DOMDocument();
        if (!@$doc->loadXML($clean, LIBXML_NONET)) {
            return null;
        }

        $root = $doc->documentElement;
        if (!$root || $root->localName !== 'svg') {
            return null;
        }
        if (!$root->hasAttribute('viewBox') && !($root->hasAttribute('width') && $root->hasAttribute('height'))) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $hrefs = iterator_to_array($xpath->query('//@*[local-name()="href"]'), false);
        foreach ($hrefs as $attr) {
            $value = trim($attr->value);
            if ($value !== '' && !str_starts_with($value, '#') && !preg_match('#^data:image/(png|jpe?g|gif)[;,]#i', $value)) {
                $attr->ownerElement->removeAttributeNode($attr);
            }
        }

        // The library pretty-prints its output, inserting indentation between
        // elements — which renders as stray spaces between <tspan> lines of
        // xml:space="preserve" text. It already drops whitespace-only text
        // on load, so removing it again here loses nothing.
        $blank = iterator_to_array($xpath->query('//text()[normalize-space(.) = ""]'), false);
        foreach ($blank as $node) {
            $node->parentNode->removeChild($node);
        }
        $doc->formatOutput = false;

        return $doc->saveXML($root);
    }
}
