<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Functional;

/**
 * Includes disposable test-site error responses in PHPUnit failure reports.
 */
trait FunctionalResponseDiagnosticsTrait {

  protected function assertResponseStatus(int $expected): void {
    $session = $this->getSession();
    $status = $session->getStatusCode();
    // The built-in status assertion reports only the numeric status. A verbose
    // Drupal error page contains the exception/stack needed to diagnose a 500,
    // even when the development PHP server log contains only access records.
    $message = sprintf('Expected HTTP %d, received HTTP %d at %s.', $expected, $status, $session->getCurrentUrl());
    if ($status !== $expected) {
      $message .= "\nResponse body:\n" . $session->getPage()->getText();
    }
    $this->assertSame($expected, $status, $message);
  }

}
