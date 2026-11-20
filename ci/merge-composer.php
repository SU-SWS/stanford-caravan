<?php

/**
 * @file
 * Merge composer.json files into the test project's composer.json.
 *
 * Usage: php merge-composer.php <project composer.json> <file> [<file>...]
 *
 * Merges "extra", "require", "require-dev", "config", "replace" and
 * "conflict" recursively: later files win for named keys, and list items are
 * added once. "repositories" from each file are appended.
 *
 * With --caravan=<path>, also installs stanford-caravan from that checkout,
 * so the project uses the same Codeception modules and configs as the
 * scripts running it.
 */

$args = array_slice($argv, 1);
$caravan = NULL;
foreach ($args as $i => $arg) {
  if (str_starts_with($arg, '--caravan=')) {
    $caravan = substr($arg, strlen('--caravan='));
    unset($args[$i]);
  }
}
$args = array_values($args);
if (count($args) < 2) {
  fwrite(STDERR, "Usage: php merge-composer.php [--caravan=<path>] <project composer.json> <file> [<file>...]\n");
  exit(1);
}

$target = array_shift($args);
$composer = read_json($target);

foreach ($args as $file) {
  $add = read_json($file);
  foreach (['extra', 'require', 'require-dev', 'config', 'replace', 'conflict'] as $key) {
    if (isset($add[$key])) {
      $composer[$key] = isset($composer[$key]) ? merge($composer[$key], $add[$key]) : $add[$key];
    }
  }
  // Repository keys can repeat between files, so append instead of merging.
  foreach ($add['repositories'] ?? [] as $repository) {
    $composer['repositories'][] = $repository;
  }
}

if ($caravan) {
  // First, so it wins over the copy on Packagist.
  array_unshift($composer['repositories'], [
    'type' => 'path',
    'url' => $caravan,
    'options' => ['symlink' => FALSE],
  ]);
  // Whatever version the extension asks for, use this checkout.
  unset($composer['require-dev']['su-sws/stanford-caravan']);
  $composer['require']['su-sws/stanford-caravan'] = '*';
}

file_put_contents($target, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

/**
 * Read and decode a JSON file.
 */
function read_json(string $file): array {
  return json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
}

/**
 * Merge two arrays recursively, the same way caravan always has.
 */
function merge(array $a, array $b): array {
  foreach ($b as $key => $value) {
    if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
      $a[$key] = merge($a[$key], $value);
    }
    elseif (is_int($key)) {
      if (!in_array($value, $a, TRUE)) {
        $a[] = $value;
      }
    }
    else {
      $a[$key] = $value;
    }
  }
  return $a;
}
