<?php

namespace Tests\Feature\Design\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Assert;

/**
 * Reads rendered HTML through the DOM, so the design tests check structure
 * and accessibility (roles, labels, ARIA) instead of exact markup, and keep
 * passing when phase 0b restyles the components.
 */
final readonly class Html
{
    private function __construct(private DOMXPath $xpath) {}

    public static function parse(string $html): self
    {
        $document = new DOMDocument;
        // The XML declaration makes libxml read the markup as UTF-8; the flags
        // silence its HTML4 complaints about <dialog>, <main>, <time>...
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new self(new DOMXPath($document));
    }

    /**
     * @return list<DOMElement>
     */
    public function all(string $xpath): array
    {
        $nodes = $this->xpath->query($xpath);
        $elements = [];

        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * The only element that matches; fails when there are none or several.
     */
    public function one(string $xpath): DOMElement
    {
        $elements = $this->all($xpath);

        Assert::assertCount(1, $elements, "Expected exactly one element for [{$xpath}].");

        return $elements[0];
    }

    public function count(string $xpath): int
    {
        return count($this->all($xpath));
    }

    /**
     * Text of the element with whitespace collapsed.
     */
    public function text(string $xpath): string
    {
        return self::clean($this->one($xpath)->textContent);
    }

    /**
     * The element whose id is $id.
     */
    public function byId(string $id): DOMElement
    {
        return $this->one("//*[@id='{$id}']");
    }

    /**
     * The control a <label> points to with its for attribute.
     */
    public function labelledControl(string $labelText): DOMElement
    {
        $label = $this->one("//label[normalize-space(.)='{$labelText}']");

        return $this->byId($label->getAttribute('for'));
    }

    /**
     * Text of every element listed in the aria-describedby of $element.
     *
     * @return list<string>
     */
    public function descriptionsOf(DOMElement $element): array
    {
        $ids = preg_split('/\s+/', trim($element->getAttribute('aria-describedby')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(fn (string $id): string => self::clean($this->byId($id)->textContent), $ids);
    }

    /**
     * Collapses ASCII whitespace only: the no-break space of money amounts
     * stays as it is.
     */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/[ \t\r\n]+/', ' ', $text), " \t\r\n");
    }
}
