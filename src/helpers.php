<?php

if (! function_exists('request')) {
  /**
   * @param string|array|null $key
   * @param mixed|null $default
   * @return mixed
   */
  function request(string|array $key = null, mixed $default = null): mixed
  {
    try {
      $request = app()->get('request');
    } catch (\Exception $e) {
      return $default;
    }

    if (is_null($key)) {
      return $request;
    }

    if (is_array($key)) {
      return $request->only($key);
    }

    return $request->get($key, $default);
  }
}

if (! function_exists('response')) {
  /**
   * @param string $content
   * @param int $status
   * @param array $headers
   * @return \Brid\Http\Response
   */
  function response(string $content = '', int $status = 200, array $headers = []): \Brid\Http\Response
  {
    return new \Brid\Http\Response($status, $headers, $content);
  }
}
