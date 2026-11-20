<?php

/**
 * @file
 * Add a Codeception HTML report to the GitHub Actions job summary.
 *
 * Usage: php summary.php <site dir> <report.html> <suite>
 *
 * Does nothing outside GitHub Actions.
 */

[, $site, $report, $suite] = $argv + [NULL, NULL, NULL, ''];
$summary = getenv('GITHUB_STEP_SUMMARY');
if (!$summary || !$site || !is_file($report)) {
  exit(0);
}

require "$site/vendor/autoload.php";

libxml_use_internal_errors(TRUE);
$dom = new DOMDocument();
$dom->loadHTML(file_get_contents($report));
$body = $dom->getElementsByTagName('body')->item(0);
if (!$body) {
  exit(0);
}

$markdown = (new League\HTMLToMarkdown\HtmlConverter())->convert($dom->saveHTML($body));
file_put_contents($summary, "## Codeception: $suite\n\n$markdown\n\n", FILE_APPEND);
