<?php

/**
 * yaml-lint, a compact command line utility for checking YAML file syntax.
 *
 * Uses the parsing facility of the Symfony Yaml Component.
 *
 * For full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use J13k\YamlLint\UsageException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

define('APP_NAME', 'yaml-lint');
define('APP_VERSION', '1.1.2');

define('ANSI_BLD', 01);
define('ANSI_UDL', 04);
define('ANSI_RED', 31);
define('ANSI_GRN', 32);

define('YAML_PARSE_PARAM_NAME_EXCEPTION_ON_INVALID_TYPE', 'exceptionOnInvalidType');
define('YAML_PARSE_PARAM_NAME_FLAGS', 'flags');

// Init app name and args
$appStr = APP_NAME . ' ' . APP_VERSION;
$argQuiet = false;
$argPath = null;

try {

    // Composer bootstrap
    $pathToTry = null;
    foreach (array('/../../../', '/../vendor/') as $pathToTry) {
        if (is_readable(__DIR__ . $pathToTry . 'autoload.php')) {
            /** @noinspection PhpIncludeInspection */
            require __DIR__ . $pathToTry . 'autoload.php';
            break;
        }
    }
    if (!class_exists('\Composer\Autoload\ClassLoader')) {
        throw new \Exception(_msg('composer'));
    }

    // Extract YAML component metadata
    $componentsManifest = __DIR__ . $pathToTry . 'composer/installed.json';
    $components = json_decode(file_get_contents($componentsManifest), true);
    foreach ($components as $component) {
        if ($component['name'] == 'symfony/yaml') {
            $appStr .= ', symfony/yaml ' . $component['version'];
            break;
        }
    }

    // Process and check args
    $argv = $_SERVER['argv'];
    array_shift($argv);
    foreach ($argv as $arg) {
        switch ($arg) {
            case '-h':
            case '--help':
                throw new UsageException();
            case '-V':
            case '--version':
                fwrite(STDOUT, $appStr . "\n");
                exit(0);
            case '-q':
            case '--quiet':
                $argQuiet = true;
                break;
            default:
                $argPath = $arg;
        }
    }

    if (!$argPath) {
        throw new UsageException('no input specified', 1);
    }

    if ($argPath === '-') {
        $path = 'php://stdin';
    } else {
        // Check input file
        if (!file_exists($argPath)) {
            throw new ParseException('File does not exist');
        }
        if (!is_readable($argPath)) {
            throw new ParseException('File is not readable');
        }
        $path = $argPath;
    }
    $content = file_get_contents($path);
    if (strlen($content) < 1) {
        throw new ParseException('Input has no content');
    }

    // Do the thing (now accommodates changes to the Yaml::parse method introduced in v3)
    $yamlParseMethod = new ReflectionMethod('\Symfony\Component\Yaml\Yaml', 'parse');
    $yamlParseParams = $yamlParseMethod->getParameters();
    switch ($yamlParseParams[1]->getName()) {
        case YAML_PARSE_PARAM_NAME_EXCEPTION_ON_INVALID_TYPE;
            // Maintains original behaviour in ^2
            Yaml::parse($content, true);
            break;
        case YAML_PARSE_PARAM_NAME_FLAGS:
            // Implements same behaviour in ^3|^4
            Yaml::parse($content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            break;
        default:
            // Param name unknown, fall back to the defaults
            Yaml::parse($content);
            break;
    }

    // Output app string and file path if allowed
    if (!$argQuiet) {
        fwrite(STDOUT, trim($appStr . ': parsing ' . $argPath));
        fwrite(STDOUT, sprintf(" [ %s ]\n", _ansify('OK', ANSI_GRN)));
    }
    exit(0);

} catch (UsageException $e) {

    // Usage message
    fwrite($e->getCode() ? STDERR : STDOUT, $appStr);
    if ($e->getMessage()) {
        fwrite(
            STDERR,
            sprintf(": %s", _ansify($e->getMessage(), ANSI_RED))
        );
    }
    fwrite(STDOUT, sprintf("\n\n%s\n\n", _msg('usage')));
    exit($e->getCode());

} catch (ParseException $e) {

    // Syntax exception
    fwrite(STDERR, trim($appStr . ': parsing ' . $argPath));
    fwrite(STDERR, sprintf(" [ %s ]\n", _ansify('ERROR', ANSI_RED)));
    fwrite(STDERR, "\n" . $e->getMessage() . "\n\n");
    exit(1);

} catch (\Exception $e) {

    // The rest
    fwrite(STDERR, $appStr);
    fwrite(STDERR, sprintf(": %s\n", _ansify($e->getMessage(), ANSI_RED)));
    exit(1);

}

/**
 * Helper to wrap input string in ANSI colour code
 *
 * @param string $str
 * @param int    $colourCode
 *
 * @return string
 */
function _ansify($str, $colourCode)
{
    $colourCode = max(0, $colourCode);
    $colourCode = min(255, $colourCode);

    return sprintf("\e[%dm%s\e[0m", $colourCode, $str);
}

/**
 * Wrapper for heredoc messages
 *
 * @param string $str
 *
 * @return string
 */
function _msg($str)
{
    switch ($str) {
        case 'composer':
            return <<<EOD
Composer dependencies cannot be loaded; install Composer to remedy:
https://getcomposer.org/download/
EOD;
            break;
        case 'usage':
            return <<<EOD
usage: yaml-lint [options] [input source]

  input source    Path to file, or "-" to read from standard input

  -q, --quiet     Restrict output to syntax errors
  -h, --help      Display this help
  -V, --version   Display application version
EOD;
            break;
        default:
    }

    return '';
}
