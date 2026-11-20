<?php

/**
 * @file
 * Turn a checkstyle report into GitHub annotations and a job summary.
 *
 * Usage: php report.php <tool name> <mode> <path prefix> <checkstyle.xml>
 *
 *   tool name    Heading for the summary, e.g. PHPCS.
 *   mode         "fail": errors are errors and the script exits 1 when there
 *                are any. "warn": everything is a warning and it exits 0.
 *   path prefix  Removed from file paths so they're relative to the
 *                repository root, which is what annotations need. A
 *                relative prefix also matches under the current directory.
 *
 * GitHub shows only the first few annotations of each step, so the summary
 * also lists issues, up to MAX_LISTED of them.
 */

const MAX_LISTED = 200;

[, $tool, $mode, $prefix, $report] = $argv + [NULL, NULL, NULL, '', NULL];
if (!in_array($mode, ['fail', 'warn'], TRUE) || !$report) {
  fwrite(STDERR, "Usage: php report.php <tool> <fail|warn> <path prefix> <checkstyle.xml>\n");
  exit(2);
}
if (!is_file($report)) {
  fwrite(STDERR, "$tool wrote no report at $report.\n");
  exit(1);
}

$xml = simplexml_load_file($report);
if ($xml === FALSE) {
  fwrite(STDERR, "$report isn't valid XML.\n");
  exit(1);
}

$prefixes = [rtrim($prefix, '/') . '/'];
if (!str_starts_with($prefix, '/')) {
  $prefixes[] = getcwd() . '/' . $prefixes[0];
}
$issues = [];
foreach ($xml->file as $file) {
  $path = (string) $file['name'];
  foreach ($prefixes as $candidate) {
    if (str_starts_with($path, $candidate)) {
      $path = substr($path, strlen($candidate));
      break;
    }
  }
  foreach ($file->error as $error) {
    $issues[] = [
      'file' => $path,
      'line' => (int) $error['line'],
      'column' => (int) $error['column'],
      'severity' => (string) $error['severity'] === 'error' ? 'error' : 'warning',
      'message' => trim((string) $error['message']),
      'source' => (string) $error['source'],
    ];
  }
}

$errors = count(array_filter($issues, fn($i) => $i['severity'] === 'error'));
$warnings = count($issues) - $errors;

foreach ($issues as $issue) {
  $level = $mode === 'fail' ? $issue['severity'] : 'warning';
  $props = "file={$issue['file']},line={$issue['line']}";
  if ($issue['column']) {
    $props .= ",col={$issue['column']}";
  }
  if ($issue['source']) {
    $props .= ',title=' . escape_property($issue['source']);
  }
  echo "::$level $props::" . escape_data($issue['message']) . PHP_EOL;
}

$verdict = !$issues ? 'no issues' : "$errors errors, $warnings warnings";
echo "$tool: $verdict." . PHP_EOL;

if ($summary_file = getenv('GITHUB_STEP_SUMMARY')) {
  $summary = "## $tool: $verdict\n\n";
  if ($issues && $mode === 'warn') {
    $summary .= "These don't fail the run (`warn` mode). Set the input to `fail` once they're fixed.\n\n";
  }
  if ($issues) {
    $summary .= "| File | Line | Severity | Rule | Message |\n|---|---|---|---|---|\n";
    foreach (array_slice($issues, 0, MAX_LISTED) as $issue) {
      $summary .= sprintf("| `%s` | %d | %s | %s | %s |\n",
        $issue['file'], $issue['line'], $issue['severity'],
        $issue['source'] ? "`{$issue['source']}`" : '', escape_cell($issue['message']));
    }
    if (count($issues) > MAX_LISTED) {
      $summary .= "\n" . (count($issues) - MAX_LISTED) . " more are in the code-quality artifact.\n";
    }
  }
  file_put_contents($summary_file, $summary . "\n", FILE_APPEND);
}

exit($mode === 'fail' && $errors ? 1 : 0);

/**
 * Escape a workflow command's message.
 */
function escape_data(string $value): string {
  return strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
}

/**
 * Escape a workflow command's property value.
 */
function escape_property(string $value): string {
  return strtr(escape_data($value), [':' => '%3A', ',' => '%2C']);
}

/**
 * Make a message safe for a Markdown table cell.
 */
function escape_cell(string $value): string {
  return strtr($value, ['|' => '\|', "\r" => ' ', "\n" => ' ']);
}
