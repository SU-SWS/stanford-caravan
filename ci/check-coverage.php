<?php

/**
 * @file
 * Fail when PHPUnit's line coverage is below a minimum.
 *
 * Usage: php check-coverage.php <coverage/xml/index.xml> <required percent>
 */

[, $report, $required] = $argv + [NULL, NULL, 90];

if (!$report || !file_exists($report)) {
  echo "No coverage report available.\n";
  exit(0);
}

$dom = new DOMDocument();
$dom->load($report);
$xpath = new DOMXPath($dom);
// The report has a namespace, so match on local names.
$percent = $xpath->evaluate("string(//*[local-name()='directory'][@name='/']/*[local-name()='totals']/*[local-name()='lines']/@percent)");
$percent = (float) rtrim($percent, '%');

if ($percent < (float) $required) {
  echo "::error::Code coverage is $percent%. $required% is required.\n";
  exit(1);
}
echo "Code coverage is $percent%.\n";
