<?php

namespace StanfordCaravan\Robo\Tasks;

use Robo\Tasks;
use Boedah\Robo\Task\Drush\loadTasks as drushTasks;

/**
 * CI/CD tools for running tests against themes, modules, and profiles.
 *
 * @package StanfordCaravan\Robo\Tasks
 */
class RoboFile extends Tasks {

  use drushTasks;

  /**
   * Path to this tool's library root.
   *
   * @var string
   */
  protected $toolDir;

  /**
   * RoboFile constructor.
   */
  public function __construct() {
    $this->toolDir = dirname(__FILE__, 4);
  }

  /**
   * Run phpunit tests on the given extension.
   *
   * @param string $html_path
   *   Path to the drupal project.
   * @param array $options
   *   Command options
   *
   * @option extension-dir Path to the Drupal extension.
   * @option with-coverage Flag to run PHPUnit with code coverage.
   *
   * @command phpunit
   */
  public function phpunit($html_path, $options = [
    'extension-dir' => NULL,
    'with-coverage' => FALSE,
    'coverage-required' => 90,
    'latest-drupal' => FALSE,
  ]) {
    $extension_dir = is_null($options['extension-dir']) ? "$html_path/.." : $options['extension-dir'];
    $this->lintPhp($extension_dir);

    if (empty(exec("find $extension_dir/ -name tests"))) {
      // Tests must be provided for src code.
      if (!empty(exec("find $extension_dir/ -name src"))) {
        throw new \Exception('No tests exist. Please add tests for the provided code');
      }

      $this->say('Nothing to test');
      return;
    }

    $this->setupDrupal($html_path, $extension_dir, $options['latest-drupal']);

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    $this->taskFilesystemStack()
      ->copy("{$this->toolDir}/config/phpunit.xml", "$html_path/web/core/phpunit.xml", TRUE)
      ->run();

    $test = $this->taskPhpUnit("../vendor/bin/phpunit")
      ->dir("$html_path/web")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->option('config', 'core', '=');

    if ($options['with-coverage']) {
      $test->option('filter', '/(Unit|Kernel)/', '=')
        ->option('coverage-html', "$html_path/artifacts/phpunit/coverage/html", '=')
        ->option('coverage-xml', "$html_path/artifacts/phpunit/coverage/xml", '=')
        ->option('coverage-clover', "$html_path/artifacts/phpunit/coverage/clover.xml");

      $this->fixupPhpunitConfig("$html_path/web/core/phpunit.xml", $extension_type, $extension_name);
    }
    $result = $test->option('log-junit', "$html_path/artifacts/phpunit/results.xml")
      ->run();
    $test_exit_code = $result->getExitCode();

    $this->_deleteDir("$html_path/web/sites/simpletest");

    $errors = [];
    if ($options['with-coverage']) {
      $errors[] = $this->checkCoverageReport("$html_path/artifacts/phpunit/coverage/xml/index.xml", $options['coverage-required']);
      $this->uploadCoverageCodeClimate("$html_path/artifacts/phpunit/coverage/clover.xml", "$html_path/web/{$extension_type}s/custom/$extension_name");
    }

    $deprecation_result = $this->taskExec("$html_path/vendor/bin/drupal-check")
      ->dir("$html_path/web")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->run();

    $errors[] = $this->checkFileNameLengths($extension_dir);
    if (array_filter($errors)) {
      throw new \Exception(implode(PHP_EOL, array_filter($errors)));
    }
    return $test_exit_code ?: $deprecation_result;
  }

  /**
   * @param $clover_coverage
   */
  protected function uploadCoverageCodeClimate($clover_coverage, $extension_dir) {
    if (!file_exists($clover_coverage)) {
      $this->say('No coverage to upload to code climate.');
      return;
    }

    if (!isset($_ENV['CC_TEST_REPORTER_ID'])) {
      $this->say('To enable codeclimate coverage uploads, please set the "CC_TEST_REPORTER_ID" environment variable to enable this feature.');
      $this->say('This can be found on the codeclimate repository settings page.');
      return;
    }

    $get_report_tool = $this->taskExec("curl -L https://codeclimate.com/downloads/test-reporter/test-reporter-latest-linux-amd64 > ./cc-test-reporter")
      ->dir($extension_dir);
    $executable_tool = $this->taskExec(' chmod +x ./cc-test-reporter')
      ->dir($extension_dir);

    $copy_clover = $this->taskFilesystemStack()
      ->copy($clover_coverage, "$extension_dir/clover.xml");

    $upload_coverage = $this->taskExec("./cc-test-reporter after-build -t clover")
      ->dir($extension_dir);

    $tasks = $this->collectionBuilder();
    $tasks->addTaskList([
      $get_report_tool,
      $executable_tool,
      $copy_clover,
      $upload_coverage,
    ]);
    $tasks->run();
  }

  /**
   * Temporarily fix the dependency of a missing branch.
   *
   * @param string $composer
   *   Path to composer file.
   */
  protected function tempFixMink($composer) {
    $composer_contents = json_decode(file_get_contents($composer), TRUE);
    $composer_contents['require-dev']['behat/mink-selenium2-driver'] = 'dev-master as 1.3.x-dev';
    file_put_contents($composer, json_encode($composer_contents));
  }

  /**
   * Lint php files.
   *
   * @param string $dir
   *   Path to lint.
   *
   * @throws \Exception
   */
  protected function lintPhp($dir) {
    $php_lint_extenstions = [
      'php',
      'module',
      'theme',
      'install',
    ];

    array_walk($php_lint_extenstions, function (&$extension) {
      $extension = "-name '*.$extension'";
    });
    $php_lint_extenstions = implode(' -o ', $php_lint_extenstions);

    exec("find $dir -type f \( $php_lint_extenstions \) -exec php -l {} \; | grep -v 'No syntax errors detected'", $output);
    if (!empty(array_filter($output))) {
      throw new \Exception(implode(PHP_EOL, array_filter($output)));
    }
  }

  protected function checkFileNameLengths($dir) {
    $errors = [];
    exec("find $dir -type f -name 'field.storage.*'", $files);
    foreach ($files as $file) {
      $filename = basename($file);
      list(, , $entity_type, $field_name,) = explode('.', $filename);
      if (strlen("{$entity_type}_revision__$field_name") >= 48) {
        $count = 48 - strlen("{$entity_type}_revision__");
        $errors[] = "$filename field name is too long. Keep the field name under $count characters on '$entity_type' entities.";
      }
    }
    return implode(PHP_EOL, $errors);
  }

  /**
   * Check if the code coverage is sufficient.
   *
   * @param string $report
   *   Path to coverage report.
   */
  public function checkCoverageReport($report, $required_coverage) {
    if (!file_exists($report)) {
      return;
    }
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML(file_get_contents($report));
    $xpath = new \DOMXPath($dom);
    $total_coverage = $xpath->query("//directory[@name='/']/totals/lines/@percent")
      ->item(0)->nodeValue;
    if ((float) $total_coverage < (float) $required_coverage) {
      return "Code coverage is not sufficient at $total_coverage%. $required_coverage% is required.";
    }
    $this->yell("Code coverage at $total_coverage%.");
  }

  /**
   * Modify the PHPUnit to whitelist only the extension being tested.
   *
   * @param string $config_path
   *   Path to the PHPUnit config.
   * @param string $extension_type
   *   Drupal extension type.
   * @param string $extension_name
   *   Drupal extension name being tested.
   */
  protected function fixupPhpunitConfig($config_path, $extension_type, $extension_name) {
    $dom = new \DOMDocument();
    $dom->loadXML(file_get_contents($config_path));
    $directories = $dom->getElementsByTagName('directory');
    for ($i = 0; $i < $directories->length; $i++) {
      $directory = $directories->item($i)->nodeValue;
      $directory = str_replace('modules/custom/*', "{$extension_type}s/custom/$extension_name", $directory);
      $directories->item($i)->nodeValue = $directory;
    }
    file_put_contents($config_path, $dom->saveXML());
  }

  /**
   * Run behat commands defined in the module.
   *
   * @param string $html_path
   *   Path to the html directory.
   * @param array $options
   *   Command options.
   *
   * @throws \Robo\Exception\TaskException
   *
   * @command behat
   */
  public function behat($html_path, $options = [
    'extension-dir' => NULL,
    'profile' => 'standard',
    'latest-drupal' => FALSE,
  ]) {
    $this->taskExec('dockerize -wait tcp://localhost:3306 -timeout 1m')->run();
    $this->taskExec('apachectl stop; apachectl start')->run();

    $extension_dir = is_null($options['extension-dir']) ? "$html_path/.." : $options['extension-dir'];

    if (empty(exec("find $extension_dir/ -name *.feature"))) {
      $this->say('No behat features exist to test');
      return;
    }

    $this->setupDrupal($html_path, $extension_dir, $options['latest-drupal']);

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    $profile = $extension_type == 'profile' ? $extension_name : $options['profile'];

    $this->taskWriteToFile("$html_path/web/sites/default/settings.php")
      ->textFromFile("{$this->toolDir}/config/circleci.settings.php")
      ->run();

    $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->siteInstall($profile)
      ->run();

    $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush("pm:enable $extension_name")
      ->run();

    $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush('pmu simplesamlphp_auth')
      ->run();

    $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush('cr')
      ->run();

    return $this->taskBehat('vendor/bin/behat')
      ->dir($html_path)
      ->config("{$this->toolDir}/config/behat.yml")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->option('profile', 'local')
      ->noInteraction()
      ->run();
  }

  /**
   * Set up the directory with bare bones Drupal and get all dependencies.
   *
   * @param string $html_path
   *   Path where Drupal project gets created.
   * @param string $extension_dir
   *   Path to the extension being tested.
   */
  protected function setupDrupal($html_path, $extension_dir, $lastest_drupal = FALSE) {
    // Clear the directory & built all the dependencies.
    $this->_deleteDir($html_path);
    $this->_mkdir($html_path);

    $this->taskComposerCreateProject()
      ->arg('drupal-composer/drupal-project:8.x-dev')
      ->arg($html_path)
      ->option('no-interaction')
      ->option('no-install')
      ->run();

    $this->taskComposerRequire()
      ->dir($html_path)
      ->arg('wikimedia/composer-merge-plugin')
      ->option('no-update')
      ->run();
    $this->tempFixMink("$html_path/composer.json");
    $this->taskComposerUpdate()
      ->dir($html_path)
      ->run();

    // Symlink docroot directory to web directory.
    $this->taskExec('ln')
      ->dir($html_path)
      ->arg("$html_path/web")
      ->arg('docroot')
      ->option('symbolic')
      ->run();

    $this->tempFixMink("$html_path/composer.json");
    $this->_deleteDir("$html_path/artifacts");
    // Delete core directory to avoid update issues since we delete files from
    // the standard profile later. This also ensure we always get a clean core.
    $this->_deleteDir("$html_path/web/core");

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    // Create the custom directory if it doesn't already exist.
    if (!file_exists("$html_path/web/{$extension_type}s/custom")) {
      $this->_mkdir("$html_path/web/{$extension_type}s/custom");
    }
    // Ensure the extensions's directory is clean first.
    $this->_deleteDir("$html_path/web/{$extension_type}s/custom/$extension_name");

    // Copy the extension into its appropriate path.
    $this->taskRsync()
      ->fromPath("$extension_dir/")
      ->toPath("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->recursive()
      ->option('exclude', 'html')
      ->run();

    if ($lastest_drupal) {
      $this->getLatestDrupalVersion($html_path);
    }

    $this->taskFilesystemStack()
      ->copy($this->toolDir . '/config/fix-missing-class.patch', "$html_path/fix-missing-class.patch")
      ->run();

    $this->say('Adding composer merge files.');
    $this->addComposerMergeFile("$html_path/composer.json", "{$this->toolDir}/config/composer.json", FALSE, TRUE);
    $this->addComposerMergeFile("$html_path/composer.json", "$html_path/web/{$extension_type}s/custom/$extension_name/composer.json", TRUE);

    $media_files = glob("$html_path/web/core/profiles/standard/config/optional/*media*");
    $delete_task = $this->taskFilesystemStack();
    foreach ($media_files as $file) {
      $delete_task->remove($file);
    }
    $delete_task->run();
  }

  /**
   * Updates composer.json to use the latest version of drupal core available.
   *
   * @param string $dir
   *   Directory of the composer.json
   */
  protected function getLatestDrupalVersion($dir) {
    $response = $this->taskExecStack()
      ->dir($dir)
      ->exec('composer show -a drupal/core')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    preg_match('/versions.*\n/', $response, $matches);
    $versions = trim(str_replace('versions :', '', $matches[0]));
    $versions = explode(',', $versions);

    $install_version = NULL;
    foreach ($versions as $key => $version) {
      if (strpos($version, '-dev') !== FALSE) {
        continue;
      }
      // If the latest stable version is something like 8.7.4, then there is a
      // version for 8.7-dev and then 8.8-dev. We want the second from the
      // latest stable version.
      $install_version = $versions[$key - 2];
      break;
    }

    $this->say(sprintf('Getting %s version of Drupal Core', trim($install_version)));
    $this->taskComposerRequire()
      ->dir($dir)
      ->arg('drupal/core:' . trim($install_version))
      ->option('no-update')
      ->run();
  }

  /**
   * Add a file to composer merge.
   *
   * @param string $composer_path
   *   Original composer.json file.
   * @param string $file_to_merge
   *   Composer.json path to be merged.
   * @param bool $update
   *   Run composer updates after merging.
   * @param bool $clear_merges
   *   Clear the merge plugin before adding the file.
   */
  protected function addComposerMergeFile($composer_path, $file_to_merge, $update = FALSE, $clear_merges = FALSE) {
    $composer = json_decode(file_get_contents($composer_path), TRUE);
    if ($clear_merges) {
      $composer['extra']['merge-plugin']['require'] = [];
    }

    $composer['extra']['merge-plugin']['require'][] = $file_to_merge;
    $composer['extra']['merge-plugin']['require'] = array_unique($composer['extra']['merge-plugin']['require']);
    $composer['extra']['merge-plugin']['merge-extra'] = TRUE;
    $composer['extra']['merge-plugin']['merge-extra-deep'] = TRUE;
    $composer['extra']['merge-plugin']['merge-scripts'] = TRUE;
    $composer['extra']['merge-plugin']['replace'] = FALSE;
    $composer['extra']['merge-plugin']['ignore-duplicates'] = TRUE;
    file_put_contents($composer_path, json_encode($composer, JSON_PRETTY_PRINT));

    if ($update) {
      // Delete contrib directories to prevent composer questions about patches.
      $this->taskDeleteDir(dirname($composer_path) . '/web/modules/contrib')
        ->run();
      $this->taskDeleteDir(dirname($composer_path) . '/web/core')->run();
      $this->taskComposerUpdate()
        ->dir(dirname($composer_path))
        ->run();
      // Run twice to make sure its all there.
      $this->taskComposerUpdate()
        ->dir(dirname($composer_path))
        ->run();
    }
  }

  /**
   * Check if the directory is empty.
   *
   * @param $dir
   *   Path to the directory.
   *
   * @return bool
   *   If the given directory is empty.
   */
  protected function isDirEmpty($dir) {
    return is_readable($dir) && (count(scandir($dir)) == 2);
  }

  /**
   * Get the machine name of the Drupal extension.
   *
   * @param string $dir
   *   Path to the extension.
   *
   * @return string
   *   Machine name.
   */
  protected function getExtensionName($dir) {
    $files = glob("$dir/*.info.yml");
    $info_file = basename($files[0]);
    return str_replace('.info.yml', '', $info_file);
  }

  /**
   * Get the Drupal extension type: module, theme, or profile.
   *
   * @param string $dir
   *   Path to the extension.
   *
   * @return string
   *   Drupal extension type.
   */
  protected function getExtensionType($dir) {
    $extension_name = $this->getExtensionName($dir);
    $info_contents = file_get_contents("$dir/$extension_name.info.yml");
    $matches = preg_grep('/^type:.*?$/x', explode("\n", $info_contents));
    return trim(str_replace('type: ', '', reset($matches)));
  }

}
