<?php

namespace Brid\Http\Concerns;

use Brid\Http\AcceptHeader;

trait InteractsWithHeaders
{

  /**
   * Gets the Etags.
   *
   * @return array The entity tags
   */
  public function getETags(): array
  {
    return preg_split('/\s*,\s*/', $this->header('if_none_match'), -1, \PREG_SPLIT_NO_EMPTY);
  }

  /**
   * @return bool
   */
  public function isNoCache(): bool
  {

    return 'no-cache' === $this->header('cache-control') || 'no-cache' == $this->header('pragma');
  }

  /**
   * Gets the preferred format for the response by inspecting, in the following order:
   *   * the request format set using setRequestFormat;
   *   * the values of the Accept HTTP header.
   *
   * Note that if you use this method, you should send the "Vary: Accept" header
   * in the response to prevent any issues with intermediary HTTP caches.
   * @todo Implement getRequestFormat and getAcceptableContentTypes
   */
  public function getPreferredFormat(?string $default = 'html'): ?string
  {
//    if (null !== $this->preferredFormat || null !== $this->preferredFormat = $this->getRequestFormat(null)) {
//      return $this->preferredFormat;
//    }

//    foreach ($this->getAcceptableContentTypes() as $mimeType) {
//      if ($this->preferredFormat = $this->getFormat($mimeType)) {
//        return $this->preferredFormat;
//      }
//    }

    return $default;
  }

  /**
   * Returns the preferred language.
   *
   * @param string[] $locales An array of ordered available locales
   *
   * @return string|null The preferred locale
   */
  public function getPreferredLanguage(array $locales = null): ?string
  {
    $preferredLanguages = $this->getLanguages();

    if (empty($locales)) {
      return $preferredLanguages[0] ?? null;
    }

    if (!$preferredLanguages) {
      return $locales[0];
    }

    $extendedPreferredLanguages = [];
    foreach ($preferredLanguages as $language) {
      $extendedPreferredLanguages[] = $language;
      if (false !== $position = strpos($language, '_')) {
        $superLanguage = substr($language, 0, $position);
        if (!\in_array($superLanguage, $preferredLanguages)) {
          $extendedPreferredLanguages[] = $superLanguage;
        }
      }
    }

    $preferredLanguages = array_values(array_intersect($extendedPreferredLanguages, $locales));

    return $preferredLanguages[0] ?? $locales[0];
  }

  /**
   * Gets a list of languages acceptable by the client browser.
   *
   * @return array Languages ordered in the user browser preferences
   */
  public function getLanguages(): array
  {
    if (null !== $this->languages) {
      return $this->languages;
    }

    $languages = AcceptHeader::fromString($this->header('accept-language'))->all();
    $this->languages = [];
    foreach ($languages as $lang => $acceptHeaderItem) {
      if (str_contains($lang, '-')) {
        $codes = explode('-', $lang);
        if ('i' === $codes[0]) {
          // Language not listed in ISO 639 that are not variants
          // of any listed language, which can be registered with the
          // i-prefix, such as i-cherokee
          if (\count($codes) > 1) {
            $lang = $codes[1];
          }
        } else {
          for ($i = 0, $max = \count($codes); $i < $max; ++$i) {
            if (0 === $i) {
              $lang = strtolower($codes[0]);
            } else {
              $lang .= '_'.strtoupper($codes[$i]);
            }
          }
        }
      }

      $this->languages[] = $lang;
    }

    return $this->languages;
  }

  /**
   * Gets a list of charsets acceptable by the client browser.
   *
   * @return array List of charsets in preferable order
   */
  public function getCharsets(): array
  {
    if (null !== $this->charsets) {
      return $this->charsets;
    }

    return $this->charsets = array_keys(AcceptHeader::fromString($this->header('accept-charset'))->all());
  }

  /**
   * Gets a list of encodings acceptable by the client browser.
   *
   * @return array List of encodings in preferable order
   */
  public function getEncodings(): array
  {
    if (null !== $this->encodings) {
      return $this->encodings;
    }

    return $this->encodings = array_keys(AcceptHeader::fromString($this->header('accept-encoding'))->all());
  }

  /**
   * Gets a list of content types acceptable by the client browser.
   *
   * @return array List of content types in preferable order
   */
  public function getAcceptableContentTypes(): array
  {
    if (null !== $this->acceptableContentTypes) {
      return $this->acceptableContentTypes;
    }

    return $this->acceptableContentTypes = array_keys(AcceptHeader::fromString($this->header('accept'))->all());
  }

  /**
   * Returns true if the request is an XMLHttpRequest.
   *
   * It works if your JavaScript library sets an X-Requested-With HTTP header.
   * It is known to work with common JavaScript frameworks:
   *
   * @see https://wikipedia.org/wiki/List_of_Ajax_frameworks#JavaScript
   *
   * @return bool true if the request is an XMLHttpRequest, false otherwise
   */
  public function isXmlHttpRequest(): bool
  {
    return 'XMLHttpRequest' === $this->header('x-requested-with');
  }

  /**
   * Determine if the request is the result of an AJAX call.
   *
   * @return bool
   */
  public function ajax(): bool
  {
    return $this->isXmlHttpRequest();
  }

  /**
   * Determine if the request is the result of a PJAX call.
   *
   * @return bool
   */
  public function pjax(): bool
  {
    return $this->header('x-pjax') == true;
  }

  /**
   * Get the bearer token from the request headers.
   *
   * @return string|null
   */
  public function bearerToken()
  {
    $header = $this->header('Authorization', '');

    $position = strrpos($header, 'Bearer');

    if ($position === false) {
      return null;
    }

    $header = substr($header, $position + 7);

    return str_contains($header, ',') ? strstr(',', $header, true) : $header;
  }

}
