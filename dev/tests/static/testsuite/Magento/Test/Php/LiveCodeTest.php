<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Test\Php;

use Magento\Framework\App\Utility\Files;
use Magento\TestFramework\CodingStandard\Tool\CodeMessDetector;
use Magento\TestFramework\CodingStandard\Tool\CodeSniffer;
use Magento\TestFramework\CodingStandard\Tool\CodeSniffer\Wrapper;
use Magento\TestFramework\CodingStandard\Tool\CopyPasteDetector;
use Magento\TestFramework\CodingStandard\Tool\PhpCompatibility;
use Magento\TestFramework\CodingStandard\Tool\PhpStan;
use PHPMD\TextUI\Command;

/**
 * Set of tests for static code analysis, e.g. code style, code complexity, copy paste detecting, etc.
 */
class LiveCodeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string
     */
    protected static $reportDir = '';

    /**
     * @var string
     */
    protected static $pathToSource = '';

    /**
     * Setup basics for all tests
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$pathToSource = BP;
        self::$reportDir = self::$pathToSource . '/dev/tests/static/report';
        if (!is_dir(self::$reportDir)) {
            mkdir(self::$reportDir);
        }
    }

    /**
     * Returns base folder for suite scope
     *
     * @return string
     */
    private static function getBaseFilesFolder()
    {
        return __DIR__;
    }

    /**
     * Returns base directory for whitelisted files
     *
     * @return string
     */
    private static function getChangedFilesBaseDir()
    {
        return __DIR__ . '/..';
    }

    /**
     * Returns whitelist based on blacklist and git changed files
     *
     * @param array $fileTypes
     * @param string $changedFilesBaseDir
     * @param string $baseFilesFolder
     * @param string $whitelistFile
     * @return array
     */
    public static function getWhitelist(
        $fileTypes = ['php'],
        $changedFilesBaseDir = '',
        $baseFilesFolder = '',
        $whitelistFile = '/_files/whitelist/common.txt'
    ) {
        $changedFiles = self::getChangedFilesList($changedFilesBaseDir);
        if (empty($changedFiles)) {
            return [];
        }

        $globPatternsFolder = ('' !== $baseFilesFolder) ? $baseFilesFolder : self::getBaseFilesFolder();
        try {
            $directoriesToCheck = Files::init()->readLists($globPatternsFolder . $whitelistFile);
        } catch (\Exception $e) {
            // no directories matched white list
            return [];
        }
        $targetFiles = self::filterFiles($changedFiles, $fileTypes, $directoriesToCheck);
        return $targetFiles;
    }

    /**
     * This method loads list of changed files.
     *
     * List may be generated by:
     *  - dev/tests/static/get_github_changes.php utility (allow to generate diffs between branches),
     *  - CLI command "git diff --name-only > dev/tests/static/testsuite/Magento/Test/_files/changed_files_local.txt",
     *
     * If no generated changed files list found "git diff" will be used to find not committed changed
     * (tests should be invoked from target gir repo).
     *
     * Note: "static" modifier used for compatibility with legacy implementation of self::getWhitelist method
     *
     * @param string $changedFilesBaseDir Base dir with previously generated list files
     * @return string[] List of changed files
     */
    private static function getChangedFilesList($changedFilesBaseDir)
    {
        return self::getFilesFromListFile(
            $changedFilesBaseDir,
            'changed_files*',
            function () {
                // if no list files, probably, this is the dev environment
                // phpcs:disable Generic.PHP.NoSilencedErrors,Magento2.Security.InsecureFunction
                @exec('git diff --name-only', $changedFiles);
                @exec('git diff --cached --name-only', $addedFiles);
                // phpcs:enable
                $changedFiles = array_unique(array_merge($changedFiles, $addedFiles));
                return $changedFiles;
            }
        );
    }

    /**
     * This method loads list of added files.
     *
     * @param string $changedFilesBaseDir
     * @return string[]
     */
    private static function getAddedFilesList($changedFilesBaseDir)
    {
        return self::getFilesFromListFile(
            $changedFilesBaseDir,
            'changed_files*.added.*',
            function () {
                // if no list files, probably, this is the dev environment
                // phpcs:ignore Generic.PHP.NoSilencedErrors,Magento2.Security.InsecureFunction
                @exec('git diff --cached --name-only --diff-filter=A', $addedFiles);
                return $addedFiles;
            }
        );
    }

    /**
     * Read files from generated lists.
     *
     * @param string $listsBaseDir
     * @param string $listFilePattern
     * @param callable $noListCallback
     * @return string[]
     */
    private static function getFilesFromListFile($listsBaseDir, $listFilePattern, $noListCallback)
    {
        $filesDefinedInList = [];

        $globFilesListPattern = ($listsBaseDir ?: self::getChangedFilesBaseDir())
            . '/_files/' . $listFilePattern;
        $listFiles = glob($globFilesListPattern);
        if (!empty($listFiles)) {
            foreach ($listFiles as $listFile) {
                // phpcs:ignore Magento2.Performance.ForeachArrayMerge.ForeachArrayMerge
                $filesDefinedInList = array_merge(
                    $filesDefinedInList,
                    file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                );
            }
        } else {
            $filesDefinedInList = call_user_func($noListCallback);
        }

        array_walk(
            $filesDefinedInList,
            function (&$file) {
                $file = BP . '/' . $file;
            }
        );

        $filesDefinedInList = array_values(array_unique($filesDefinedInList));

        return $filesDefinedInList;
    }

    /**
     * Filter list of files.
     *
     * File removed from list:
     *  - if it not exists,
     *  - if allowed types are specified and file has another type (extension),
     *  - if allowed directories specified and file not located in one of them.
     *
     * Note: "static" modifier used for compatibility with legacy implementation of self::getWhitelist method
     *
     * @param string[] $files List of file paths to filter
     * @param string[] $allowedFileTypes List of allowed file extensions (pass empty array to allow all)
     * @param string[] $allowedDirectories List of allowed directories (pass empty array to allow all)
     * @return string[] Filtered file paths
     */
    private static function filterFiles(array $files, array $allowedFileTypes, array $allowedDirectories)
    {
        if (empty($allowedFileTypes)) {
            $fileHasAllowedType = function () {
                return true;
            };
        } else {
            $fileHasAllowedType = function ($file) use ($allowedFileTypes) {
                return in_array(pathinfo($file, PATHINFO_EXTENSION), $allowedFileTypes);
            };
        }

        if (empty($allowedDirectories)) {
            $fileIsInAllowedDirectory = function () {
                return true;
            };
        } else {
            $allowedDirectories = array_map('realpath', $allowedDirectories);
            usort(
                $allowedDirectories,
                function ($dir1, $dir2) {
                    return strlen($dir1) - strlen($dir2);
                }
            );
            $fileIsInAllowedDirectory = function ($file) use ($allowedDirectories) {
                foreach ($allowedDirectories as $directory) {
                    if (strpos($file, $directory) === 0) {
                        return true;
                    }
                }
                return false;
            };
        }

        $filtered = array_filter(
            $files,
            function ($file) use ($fileHasAllowedType, $fileIsInAllowedDirectory) {
                $file = realpath($file);
                if (false === $file) {
                    return false;
                }
                return $fileHasAllowedType($file) && $fileIsInAllowedDirectory($file);
            }
        );

        return $filtered;
    }

    /**
     * Retrieves full list of codebase paths without any files/folders filtered out
     *
     * @return array
     */
    private function getFullWhitelist()
    {
        try {
            return Files::init()->readLists(__DIR__ . '/_files/whitelist/common.txt');
        } catch (\Exception $e) {
            // nothing is whitelisted
            return [];
        }
    }

    /**
     * Retrieves the lowest and highest PHP version specified in <kbd>composer.json</var> of project.
     *
     * @return array
     */
    private function getTargetPhpVersions(): array
    {
        $composerJson = json_decode(file_get_contents(BP . '/composer.json'), true);
        $versionsRange = [];

        if (isset($composerJson['require']['php'])) {
            $versions = explode('||', $composerJson['require']['php']);

            //normalize version constraints
            foreach ($versions as $key => $version) {
                $version = ltrim($version, '^~');
                $version = str_replace('*', '999', $version);

                $versions[$key] = $version;
            }

            //sort versions
            usort($versions, 'version_compare');

            $versionsRange[] = array_shift($versions);
            if (!empty($versions)) {
                $versionsRange[] = array_pop($versions);
            }
            foreach ($versionsRange as $key => $version) {
                $versionParts  = explode('.', $versionsRange[$key]);
                $versionsRange[$key] = sprintf('%s.%s', $versionParts[0], $versionParts[1] ?? '0');
            }
        }

        return $versionsRange;
    }

    /**
     * Returns whether a full scan was requested.
     *
     * This can be set in the `phpunit.xml` used to run these test cases, by setting the constant
     * `TESTCODESTYLE_IS_FULL_SCAN` to `1`, e.g.:
     * ```xml
     * <php>
     *     <!-- TESTCODESTYLE_IS_FULL_SCAN - specify if full scan should be performed for test code style test -->
     *     <const name="TESTCODESTYLE_IS_FULL_SCAN" value="0"/>
     * </php>
     * ```
     *
     * @return bool
     */
    private function isFullScan(): bool
    {
        return defined('TESTCODESTYLE_IS_FULL_SCAN') && TESTCODESTYLE_IS_FULL_SCAN === '1';
    }

    /**
     * Test code quality using phpcs
     */
    public function testCodeStyle()
    {
        $reportFile = self::$reportDir . '/phpcs_report.txt';
        if (!file_exists($reportFile)) {
            touch($reportFile);
        }
        $codeSniffer = new CodeSniffer('Magento', $reportFile, new Wrapper());
        $result = $codeSniffer->run(
            $this->isFullScan() ? $this->getFullWhitelist() : self::getWhitelist(['php', 'phtml'])
        );
        $report = file_get_contents($reportFile);
        $this->assertEquals(
            0,
            $result,
            "PHP Code Sniffer detected {$result} violation(s): " . PHP_EOL . $report
        );
    }

    /**
     * Test code quality using phpmd
     */
    public function testCodeMess()
    {
        $reportFile = self::$reportDir . '/phpmd_report.txt';
        $codeMessDetector = new CodeMessDetector(realpath(__DIR__ . '/_files/phpmd/ruleset.xml'), $reportFile);

        if (!$codeMessDetector->canRun()) {
            $this->markTestSkipped('PHP Mess Detector is not available.');
        }

        $result = $codeMessDetector->run(self::getWhitelist(['php']));

        $output = "";
        if (file_exists($reportFile)) {
            $output = file_get_contents($reportFile);
        }

        $this->assertEquals(
            Command::EXIT_SUCCESS,
            $result,
            "PHP Code Mess has found error(s):" . PHP_EOL . $output
        );

        // delete empty reports
        if (file_exists($reportFile)) {
            unlink($reportFile);
        }
    }

    /**
     * Test code quality using phpcpd
     */
    public function testCopyPaste()
    {
        $reportFile = self::$reportDir . '/phpcpd_report.xml';
        $copyPasteDetector = new CopyPasteDetector($reportFile);

        if (!$copyPasteDetector->canRun()) {
            $this->markTestSkipped('PHP Copy/Paste Detector is not available.');
        }

        $blackList = [];
        foreach (glob(__DIR__ . '/_files/phpcpd/blacklist/*.txt') as $list) {
            // phpcs:ignore Magento2.Performance.ForeachArrayMerge.ForeachArrayMerge
            $blackList = array_merge($blackList, file($list, FILE_IGNORE_NEW_LINES));
        }

        $copyPasteDetector->setBlackList($blackList);

        $result = $copyPasteDetector->run([BP]);

        $output = "";
        if (file_exists($reportFile)) {
            $output = file_get_contents($reportFile);
        }

        $this->assertTrue(
            $result,
            "PHP Copy/Paste Detector has found error(s):" . PHP_EOL . $output
        );
    }

    /**
     * Tests whitelisted files for strict type declarations.
     */
    public function testStrictTypes()
    {
        $changedFiles = self::getAddedFilesList('');

        try {
            $blackList = Files::init()->readLists(
                self::getBaseFilesFolder() . '/_files/blacklist/strict_type.txt'
            );
        } catch (\Exception $e) {
            // nothing matched black list
            $blackList = [];
        }

        $toBeTestedFiles = array_diff(
            self::filterFiles($changedFiles, ['php'], []),
            $blackList
        );

        $filesMissingStrictTyping = [];
        foreach ($toBeTestedFiles as $fileName) {
            $file = file_get_contents($fileName);
            if (strstr($file, 'strict_types=1') === false) {
                $filesMissingStrictTyping[] = $fileName;
            }
        }

        $this->assertEquals(
            0,
            count($filesMissingStrictTyping),
            "Following files are missing strict type declaration:"
            . PHP_EOL
            . implode(PHP_EOL, $filesMissingStrictTyping)
        );
    }

    /**
     * Test for compatibility to lowest PHP version declared in <kbd>composer.json</kbd>.
     */
    public function testPhpCompatibility()
    {
        $targetVersions = $this->getTargetPhpVersions();
        $this->assertNotEmpty($targetVersions, 'No supported versions information in composer.json');
        $reportFile    = self::$reportDir . '/phpcompatibility_report.txt';
        $rulesetDir    = __DIR__ . '/_files/PHPCompatibilityMagento';

        if (!file_exists($reportFile)) {
            touch($reportFile);
        }

        $codeSniffer = new PhpCompatibility($rulesetDir, $reportFile, new Wrapper());
        if (count($targetVersions) > 1) {
            $codeSniffer->setTestVersion($targetVersions[0] . '-' . $targetVersions[1]);
        } else {
            $codeSniffer->setTestVersion($targetVersions[0]);
        }

        $result = $codeSniffer->run(
            $this->isFullScan() ? $this->getFullWhitelist() : self::getWhitelist(['php', 'phtml'])
        );
        $report = file_get_contents($reportFile);

        $this->assertEquals(
            0,
            $result,
            'PHP Compatibility detected violation(s):' . PHP_EOL . $report
        );
    }

    /**
     * Test code quality using PHPStan
     *
     * @throws \Exception
     */
    public function testPhpStan()
    {
        $reportFile = self::$reportDir . '/phpstan_report.txt';
        $confFile = __DIR__ . '/_files/phpstan/phpstan.neon';

        if (!file_exists($reportFile)) {
            touch($reportFile);
        }

        $fileList = self::getWhitelist(['php']);
        $blackList = Files::init()->readLists(__DIR__ . '/_files/phpstan/blacklist/*.txt');
        if ($blackList) {
            $blackListPattern = sprintf('#(%s)#i', implode('|', $blackList));
            $fileList = array_filter(
                $fileList,
                function ($path) use ($blackListPattern) {
                    return !preg_match($blackListPattern, $path);
                }
            );
        }

        $phpStan = new PhpStan($confFile, $reportFile);
        $exitCode = $phpStan->run($fileList);
        $report = file_get_contents($reportFile);

        $errorMessage = empty($report) ?
            'PHPStan command run failed.' : 'PHPStan detected violation(s):' . PHP_EOL . $report;
        $this->assertEquals(0, $exitCode, $errorMessage);
    }
}
