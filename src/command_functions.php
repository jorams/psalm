<?php

use Composer\Autoload\ClassLoader;
use Psalm\Config;
use Psalm\Exception\ConfigException;

/**
 * @param  string $current_dir
 * @param  bool   $has_explicit_root
 * @param  string $vendor_dir
 *
 * @return ?\Composer\Autoload\ClassLoader
 */
function requireAutoloaders($current_dir, $has_explicit_root, $vendor_dir)
{
    $autoload_roots = [$current_dir];

    $psalm_dir = dirname(__DIR__);

    /** @psalm-suppress UndefinedConstant */
    $in_phar = Phar::running() || strpos(__NAMESPACE__, 'HumbugBox');

    if ($in_phar) {
        require_once(__DIR__ . '/../vendor/autoload.php');

        // hack required for JsonMapper
        require_once __DIR__ . '/../vendor/netresearch/jsonmapper/src/JsonMapper.php';
        require_once __DIR__ . '/../vendor/netresearch/jsonmapper/src/JsonMapper/Exception.php';
    }

    if (realpath($psalm_dir) !== realpath($current_dir) && !$in_phar) {
        $autoload_roots[] = $psalm_dir;
    }

    $autoload_files = [];

    foreach ($autoload_roots as $autoload_root) {
        $has_autoloader = false;

        $nested_autoload_file = dirname(dirname($autoload_root)) . DIRECTORY_SEPARATOR . 'autoload.php';

        // note: don't realpath $nested_autoload_file, or phar version will fail
        if (file_exists($nested_autoload_file)) {
            if (!in_array($nested_autoload_file, $autoload_files, false)) {
                $autoload_files[] = $nested_autoload_file;
            }
            $has_autoloader = true;
        }

        $vendor_autoload_file =
            $autoload_root . DIRECTORY_SEPARATOR . $vendor_dir . DIRECTORY_SEPARATOR . 'autoload.php';

        // note: don't realpath $vendor_autoload_file, or phar version will fail
        if (file_exists($vendor_autoload_file)) {
            if (!in_array($vendor_autoload_file, $autoload_files, false)) {
                $autoload_files[] = $vendor_autoload_file;
            }
            $has_autoloader = true;
        }

        if (!$has_autoloader && file_exists($autoload_root . '/composer.json')) {
            $error_message = 'Could not find any composer autoloaders in ' . $autoload_root;

            if (!$has_explicit_root) {
                $error_message .= PHP_EOL . 'Add a --root=[your/project/directory] flag '
                    . 'to specify a particular project to run Psalm on.';
            }

            fwrite(STDERR, $error_message . PHP_EOL);
            exit(1);
        }
    }

    $first_autoloader = null;

    foreach ($autoload_files as $file) {
        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var mixed
         */
        $autoloader = require_once $file;

        if (!$first_autoloader
            && $autoloader instanceof ClassLoader
        ) {
            $first_autoloader = $autoloader;
        }
    }

    if ($first_autoloader === null && !$in_phar) {
        if (!$autoload_files) {
            fwrite(STDERR, 'Failed to find a valid Composer autoloader' . "\n");
        } else {
            fwrite(STDERR, 'Failed to find a valid Composer autoloader in ' . implode(', ', $autoload_files) . "\n");
        }

        fwrite(
            STDERR,
            'Please make sure you’ve run `composer install` in the current directory before using Psalm.' . "\n"
        );
        exit(1);
    }

    define('PSALM_VERSION', \PackageVersions\Versions::getVersion('vimeo/psalm'));
    define('PHP_PARSER_VERSION', \PackageVersions\Versions::getVersion('nikic/php-parser'));

    return $first_autoloader;
}

/**
 * @param  string $current_dir
 *
 * @return string
 *
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedAssignment
 */
function getVendorDir($current_dir)
{
    $composer_json_path = $current_dir . DIRECTORY_SEPARATOR . 'composer.json';

    if (!file_exists($composer_json_path)) {
        return 'vendor';
    }

    if (!$composer_json = json_decode(file_get_contents($composer_json_path), true)) {
        fwrite(
            STDERR,
            'Invalid composer.json at ' . $composer_json_path . "\n"
        );
        exit(1);
    }

    if (isset($composer_json['config']['vendor-dir'])) {
        return (string) $composer_json['config']['vendor-dir'];
    }

    return 'vendor';
}

/**
 * @return string[]
 */
function getArguments() : array
{
    global $argv;

    if (!$argv) {
        return [];
    }

    $filtered_input_paths = [];

    for ($i = 0; $i < count($argv); ++$i) {
        /** @var string */
        $input_path = $argv[$i];

        if (realpath($input_path) !== false) {
            continue;
        }

        if ($input_path[0] === '-' && strlen($input_path) === 2) {
            if ($input_path[1] === 'c' || $input_path[1] === 'f') {
                ++$i;
            }
            continue;
        }

        if ($input_path[0] === '-' && $input_path[2] === '=') {
            continue;
        }

        $filtered_input_paths[] = $input_path;
    }

    return $filtered_input_paths;
}

/**
 * @param  string|array|null|false $f_paths
 *
 * @return string[]|null
 */
function getPathsToCheck($f_paths)
{
    global $argv;

    $paths_to_check = [];

    if ($f_paths) {
        $input_paths = is_array($f_paths) ? $f_paths : [$f_paths];
    } else {
        $input_paths = $argv ? $argv : null;
    }

    if ($input_paths) {
        $filtered_input_paths = [];

        for ($i = 0; $i < count($input_paths); ++$i) {
            /** @var string */
            $input_path = $input_paths[$i];

            if (realpath($input_path) === realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'psalm')
                || realpath($input_path) === realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'psalter')
                || realpath($input_path) === realpath(Phar::running(false))
            ) {
                continue;
            }

            if ($input_path[0] === '-' && strlen($input_path) === 2) {
                if ($input_path[1] === 'c' || $input_path[1] === 'f') {
                    ++$i;
                }
                continue;
            }

            if ($input_path[0] === '-' && $input_path[2] === '=') {
                continue;
            }

            if (substr($input_path, 0, 2) === '--' && strlen($input_path) > 2) {
                continue;
            }

            $filtered_input_paths[] = $input_path;
        }

        if ($filtered_input_paths === ['-']) {
            $meta = stream_get_meta_data(STDIN);
            stream_set_blocking(STDIN, false);
            if ($stdin = fgets(STDIN)) {
                $filtered_input_paths = preg_split('/\s+/', trim($stdin));
            }
            /** @var bool */
            $blocked = $meta['blocked'];
            stream_set_blocking(STDIN, $blocked);
        }

        foreach ($filtered_input_paths as $path_to_check) {
            if ($path_to_check[0] === '-') {
                fwrite(STDERR, 'Invalid usage, expecting psalm [options] [file...]' . PHP_EOL);
                exit(1);
            }

            if (!file_exists($path_to_check)) {
                fwrite(STDERR, 'Cannot locate ' . $path_to_check . PHP_EOL);
                exit(1);
            }

            $path_to_check = realpath($path_to_check);

            if (!$path_to_check) {
                fwrite(STDERR, 'Error getting realpath for file' . PHP_EOL);
                exit(1);
            }

            $paths_to_check[] = $path_to_check;
        }

        if (!$paths_to_check) {
            $paths_to_check = null;
        }
    }

    return $paths_to_check;
}

function getPsalmHelpText(): string
{
    return <<<HELP
Usage:
    psalm [options] [file...]

Options:
    -h, --help
        Display this help message

    -v, --version
        Display the Psalm version

    -i, --init [source_dir=src] [level=3]
        Create a psalm config file in the current directory that points to [source_dir]
        at the required level, from 1, most strict, to 8, most permissive.

    --debug
        Debug information

    --debug-by-line
        Debug information on a line-by-line level

    -c, --config=psalm.xml
        Path to a psalm.xml configuration file. Run psalm --init to create one.

    -m, --monochrome
        Enable monochrome output

    -r, --root
        If running Psalm globally you'll need to specify a project root. Defaults to cwd

    --show-info[=BOOLEAN]
        Show non-exception parser findings

    --show-snippet[=true]
        Show code snippets with errors. Options are 'true' or 'false'

    --diff
        Runs Psalm in diff mode, only checking files that have changed (and their dependents)

    --diff-methods
        Only checks methods that have changed (and their dependents)

    --output-format=console
        Changes the output format. Possible values: compact, console, emacs, json, pylint, xml, checkstyle, sonarqube

    --find-dead-code[=auto]
    --find-unused-code[=auto]
        Look for unused code. Options are 'auto' or 'always'. If no value is specified, default is 'auto'

    --find-unused-psalm-suppress
        Finds all @psalm-suppress annotations that aren’t used

    --find-references-to=[class|method|property]
        Searches the codebase for references to the given fully-qualified class or method,
        where method is in the format class::methodName

    --threads=INT
        If greater than one, Psalm will run analysis on multiple threads, speeding things up.

    --report=PATH
        The path where to output report file. The output format is based on the file extension.
        (Currently supported format: ".json", ".xml", ".txt", ".emacs")

    --report-show-info[=BOOLEAN]
        Whether the report should include non-errors in its output (defaults to true)

    --clear-cache
        Clears all cache files that Psalm uses for this specific project

    --clear-global-cache
        Clears all cache files that Psalm uses for all projects

    --no-cache
        Runs Psalm without using cache

    --no-reflection-cache
        Runs Psalm without using cached representations of unchanged classes and files.
        Useful if you want the afterClassLikeVisit plugin hook to run every time you visit a file.

    --plugin=PATH
        Executes a plugin, an alternative to using the Psalm config

    --stats
        Shows a breakdown of Psalm's ability to infer types in the codebase

    --use-ini-defaults
        Use PHP-provided ini defaults for memory and error display

    --disable-extension=[extension]
        Used to disable certain extensions while Psalm is running.

    --set-baseline=PATH
        Save all current error level issues to a file, to mark them as info in subsequent runs

        Add --include-php-versions to also include a list of PHP extension versions

    --ignore-baseline
        Ignore the error baseline

    --update-baseline
        Update the baseline by removing fixed issues. This will not add new issues to the baseline

        Add --include-php-versions to also include a list of PHP extension versions

    --generate-json-map=PATH
        Generate a map of node references and types in JSON format, saved to the given path.

    --no-progress
        Disable the progress indicator

    --alter
        Run Psalter

    --language-server
        Run Psalm Language Server

HELP;
}

function initialiseConfig(
    ?string $path_to_config,
    string $current_dir,
    string $output_format,
    ?ClassLoader $first_autoloader
): Config {
    try {
        if ($path_to_config) {
            $config = Config::loadFromXMLFile($path_to_config, $current_dir);
        } else {
            $config = Config::getConfigForPath($current_dir, $current_dir, $output_format);
        }
    } catch (Psalm\Exception\ConfigException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $config->setComposerClassLoader($first_autoloader);

    return $config;
}

function update_config_file(Config $config, string $config_file_path, string $baseline_path) : void
{
    if ($config->error_baseline === $baseline_path) {
        return;
    }

    $configFile = Config::locateConfigFile($config_file_path);

    if (!$configFile) {
        fwrite(STDERR, "Don't forget to set errorBaseline=\"{$baseline_path}\" to your config.");

        return;
    }

    $configFileContents = file_get_contents($configFile);

    if ($config->error_baseline) {
        $amendedConfigFileContents = preg_replace(
            '/errorBaseline=".*?"/',
            "errorBaseline=\"{$baseline_path}\"",
            $configFileContents
        );
    } else {
        $endPsalmOpenTag = strpos($configFileContents, '>', (int)strpos($configFileContents, '<psalm'));

        if (!$endPsalmOpenTag) {
            fwrite(STDERR, " Don't forget to set errorBaseline=\"{$baseline_path}\" in your config.");
            return;
        }

        if ($configFileContents[$endPsalmOpenTag - 1] === "\n") {
            $amendedConfigFileContents = substr_replace(
                $configFileContents,
                "    errorBaseline=\"{$baseline_path}\"\n>",
                $endPsalmOpenTag,
                1
            );
        } else {
            $amendedConfigFileContents = substr_replace(
                $configFileContents,
                " errorBaseline=\"{$baseline_path}\">",
                $endPsalmOpenTag,
                1
            );
        }
    }

    file_put_contents($configFile, $amendedConfigFileContents);
}

function get_path_to_config(array $options): ?string
{
    $path_to_config = isset($options['c']) && is_string($options['c']) ? realpath($options['c']) : null;

    if ($path_to_config === false) {
        fwrite(STDERR, 'Could not resolve path to config ' . (string)$options['c'] . PHP_EOL);
        exit(1);
    }
    return $path_to_config;
}
