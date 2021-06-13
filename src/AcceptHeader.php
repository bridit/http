<?php

namespace Brid\Http;

// Help opcache.preload discover always-needed symbols
class_exists(AcceptHeaderItem::class);

/**
 * Represents an Accept-* header.
 *
 * An accept header is compound with a list of items,
 * sorted by descending quality.
 *
 * @author Jean-François Simon <contact@jfsimon.fr>
 * @see Symfony HttpFoundation
 */
class AcceptHeader
{
  /**
   * @var AcceptHeaderItem[]
   */
  private array $items = [];

  /**
   * @var bool
   */
  private bool $sorted = true;

  /**
   * @param AcceptHeaderItem[] $items
   */
  public function __construct(array $items)
  {
    foreach ($items as $item) {
      $this->add($item);
    }
  }

  /**
   * Builds an AcceptHeader instance from a string.
   *
   * @param string|null $headerValue
   * @return AcceptHeader
   */
  public static function fromString(?string $headerValue): AcceptHeader
  {
    $index = 0;

    $parts = HeaderUtils::split($headerValue ?? '', ',;=');

    return new self(array_map(function ($subParts) use (&$index) {
      $part = array_shift($subParts);
      $attributes = HeaderUtils::combine($subParts);

      $item = new AcceptHeaderItem($part[0], $attributes);
      $item->setIndex($index++);

      return $item;
    }, $parts));
  }

  /**
   * Returns header value's string representation.
   *
   * @return string
   */
  public function __toString()
  {
    return implode(',', $this->items);
  }

  /**
   * Tests if header has given value.
   *
   * @return bool
   */
  public function has(string $value)
  {
    return isset($this->items[$value]);
  }

  /**
   * Returns given value's item, if exists.
   *
   * @return AcceptHeaderItem|null
   */
  public function get(string $value)
  {
    return $this->items[$value] ?? $this->items[explode('/', $value)[0].'/*'] ?? $this->items['*/*'] ?? $this->items['*'] ?? null;
  }

  /**
   * Adds an item.
   *
   * @return $this
   */
  public function add(AcceptHeaderItem $item): static
  {
    $this->items[$item->getValue()] = $item;
    $this->sorted = false;

    return $this;
  }

  /**
   * Returns all items.
   *
   * @return AcceptHeaderItem[]
   */
  public function all(): array
  {
    $this->sort();

    return $this->items;
  }

  /**
   * Filters items on their value using given regex.
   *
   * @return self
   */
  public function filter(string $pattern): AcceptHeader
  {
    return new self(array_filter($this->items, function (AcceptHeaderItem $item) use ($pattern) {
      return preg_match($pattern, $item->getValue());
    }));
  }

  /**
   * Returns first item.
   *
   * @return AcceptHeaderItem|null
   */
  public function first()
  {
    $this->sort();

    return !empty($this->items) ? reset($this->items) : null;
  }

  /**
   * Sorts items by descending quality.
   */
  private function sort(): void
  {
    if (!$this->sorted) {
      uasort($this->items, function (AcceptHeaderItem $a, AcceptHeaderItem $b) {
        $qA = $a->getQuality();
        $qB = $b->getQuality();

        if ($qA === $qB) {
          return $a->getIndex() > $b->getIndex() ? 1 : -1;
        }

        return $qA > $qB ? -1 : 1;
      });

      $this->sorted = true;
    }
  }
}
