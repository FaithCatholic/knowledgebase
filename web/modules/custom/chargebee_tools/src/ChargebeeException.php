<?php

namespace Drupal\chargebee_tools;

/**
 * A failed Chargebee API call. The code is the HTTP status, or 0 if none.
 */
class ChargebeeException extends \RuntimeException {

  /**
   * Whether Chargebee rejected the call for exceeding its rate limit.
   *
   * A rate-limited call was never applied, so it is safe to send again.
   */
  public function isRateLimited(): bool {
    return $this->getCode() === 429;
  }

}
