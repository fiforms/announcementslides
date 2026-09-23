<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Embeds the overlay editor's JSON source inside the compiled overlay SVG
 * (a <metadata id="as-overlay-source"> element), so the one file the
 * displays already consume is also re-editable — no sidecar file to keep in
 * sync.
 *
 * The source carries a sha256 of the SVG body (everything except that
 * metadata element, canonicalized with C14N). If the body is later changed
 * outside the editor (Inkscape, hand edits), the hash no longer matches and
 * extract() reports no source — the editor then treats the file as an
 * external SVG and offers it as a fixed base layer instead of silently
 * losing those edits. An unchanged file that's downloaded and re-uploaded
 * stays editable.
 */
class OverlaySource
{
    public const METADATA_ID = 'as-overlay-source';

    public function canonicalBody(DOMDocument $doc): string
    {
        $copy = new DOMDocument();
        $copy->appendChild($copy->importNode($doc->documentElement, true));
        foreach ($this->metadataNodes($copy) as $node) {
            $node->parentNode->removeChild($node);
        }
        // Whitespace-only text nodes are formatting (the sanitizer re-indents
        // its output), not content — drop them so a re-sanitized copy of an
        // editor file still hashes the same.
        $blank = iterator_to_array((new DOMXPath($copy))->query('//text()[normalize-space(.) = ""]'), false);
        foreach ($blank as $node) {
            $node->parentNode->removeChild($node);
        }

        return $copy->documentElement->C14N();
    }

    /**
     * @param  string  $svg  already-sanitized SVG markup
     * @param  array  $source  editor model ({v, canvas, elements})
     */
    public function embed(string $svg, array $source): string
    {
        $doc = $this->load($svg);
        if (!$doc) {
            throw new \InvalidArgumentException('Overlay SVG is not well-formed XML.');
        }
        foreach ($this->metadataNodes($doc) as $node) {
            $node->parentNode->removeChild($node);
        }

        $source['hash'] = hash('sha256', $this->canonicalBody($doc));

        $root = $doc->documentElement;
        $metadata = $doc->createElementNS($root->namespaceURI ?: 'http://www.w3.org/2000/svg', 'metadata');
        $metadata->setAttribute('id', self::METADATA_ID);
        // JSON_HEX_TAG escapes '>' so the payload can never contain "]]>".
        $json = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $metadata->appendChild($doc->createCDATASection($json));
        $root->insertBefore($metadata, $root->firstChild);

        return $doc->saveXML($root);
    }

    /**
     * @return array{source: ?array, svg: string}  svg has the metadata removed
     */
    public function extract(string $svg): array
    {
        $doc = $this->load($svg);
        if (!$doc) {
            return ['source' => null, 'svg' => $svg];
        }

        $source = null;
        $node = $this->metadataNodes($doc)[0] ?? null;
        if ($node) {
            $decoded = json_decode($node->textContent, true);
            if (is_array($decoded) && isset($decoded['hash'], $decoded['elements']) && is_array($decoded['elements'])
                && hash_equals((string) $decoded['hash'], hash('sha256', $this->canonicalBody($doc)))) {
                $source = $decoded;
            }
        }

        foreach ($this->metadataNodes($doc) as $n) {
            $n->parentNode->removeChild($n);
        }

        return ['source' => $source, 'svg' => $doc->saveXML($doc->documentElement)];
    }

    private function load(string $svg): ?DOMDocument
    {
        $doc = new DOMDocument();

        return @$doc->loadXML($svg, LIBXML_NONET) && $doc->documentElement ? $doc : null;
    }

    /** @return DOMElement[] */
    private function metadataNodes(DOMDocument $doc): array
    {
        $xpath = new DOMXPath($doc);

        return iterator_to_array(
            $xpath->query('//*[local-name()="metadata" and @id="' . self::METADATA_ID . '"]'),
            false
        );
    }
}
