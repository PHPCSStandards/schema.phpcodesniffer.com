<?php
/**
 * Website schema.phpcodesniffer.com.
 *
 * @copyright 2025 PHPCSStandards and contributors
 * @license   https://github.com/PHPCSStandards/schema.phpcodesniffer.com/blob/stable/LICENSE BSD Licence
 * @link      https://github.com/PHPCSStandards/schema.phpcodesniffer.com
 */

namespace PHP_CodeSniffer\Schema\Build;

use JsonException;
use RuntimeException;

/**
 * Build the `_site` directory and its contents for deployment to a static GH Pages website.
 */
final class UpdateXsdFiles
{

    /**
     * Version in which the XSD file for PHPCS rulesets was first released.
     *
     * @var string
     */
    private const FIRST_XSD_IN = '3.2.0';

    /**
     * Location which may, or may not, contain a JSON file with release data.
     *
     * @var string
     */
    private const RELEASES_JSON_FILE = __DIR__ . '/../../releases.json';

    /**
     * URL to retrieve PHP_CodeSniffer releases from the GitHub API (in case the json file does not exist).
     *
     * @var string
     */
    private const API_RELEASES_URL = 'https://api.github.com/repos/PHPCSStandards/PHP_CodeSniffer/releases?per_page=50';

    /**
     * URL to retrieve the latest PHP_CodeSniffer release from the GitHub API (in case the json file does not exist).
     *
     * @var string
     */
    private const API_LATEST_TAG_URL = 'https://api.github.com/repos/PHPCSStandards/PHP_CodeSniffer/releases/latest';

    /**
     * URL pattern to download the XSD file(s).
     *
     * @var string
     */
    private const XSD_URL = 'https://raw.githubusercontent.com/PHPCSStandards/PHP_CodeSniffer/refs/tags/%s/phpcs.xsd';

    /**
     * Array of releases.
     *
     * Array format:
     *   Key: (string) tag name.
     *   Value: (array)
     *     (bool) 'isDraft'
     *     (bool) 'isLatest'
     *     (bool) 'isPrerelease'
     *
     * @var array<string, array<string, bool>>
     */
    private array $releases = [];

    /**
     * Update the XSD files in the _site directory.
     *
     * @return int
     */
    public function run(): int
    {
        $exitcode = 0;

        try {
            $this->getReleases();
            \krsort($this->releases, \SORT_NATURAL);
            $this->processReleases();
        } catch (RuntimeException | JsonException $e) {
            echo 'ERROR: ', $e->getMessage(), \PHP_EOL;
            $exitcode = 1;
        }

        return $exitcode;
    }

    /**
     * Retrieve information about the PHP_CodeSniffer releases from a releases.json file.
     *
     * @return void
     */
    private function getReleases(): void
    {
        $releasesFile = \realpath(self::RELEASES_JSON_FILE);
        if (\is_string($releasesFile) === false) {
            $this->getReleasesFallback();
            return;
        }

        $releasesJson = \file_get_contents($releasesFile);
        if (\is_string($releasesJson) === false) {
            throw new RuntimeException('Failed to retrieve the releases from releases.json file');
        }

        $releases = \json_decode($releasesJson, flags: \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY);
        if (\is_array($releases) === false) {
            throw new RuntimeException('Couldn\'t decode JSON from releases.json file');
        }

        foreach ($releases as $releaseInfo) {
            $tagName = $releaseInfo['tagName'];
            unset($releaseInfo['tagName']);
            $this->releases[$tagName] = $releaseInfo;
        }
    }

    /**
     * Retrieve information about the PHP_CodeSniffer releases from the GitHub API
     * (in case the releases.json file does not exist).
     *
     * @return void
     */
    private function getReleasesFallback(): void
    {
        $curl = \curl_init();
        \curl_setopt($curl, \CURLOPT_URL, self::API_RELEASES_URL);
        \curl_setopt(
            $curl,
            \CURLOPT_HTTPHEADER,
            [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: PHPCSStandards',
                //"Authorization: token $github_access_token",
            ]
        );
        \curl_setopt($curl, \CURLOPT_RETURNTRANSFER, true);
        $releasesJson = \curl_exec($curl);

        if (\is_string($releasesJson) === false) {
            throw new RuntimeException('GitHub API request to retrieve the releases failed');
        }

        \curl_setopt($curl, \CURLOPT_URL, self::API_LATEST_TAG_URL);
        $latestReleaseJson = \curl_exec($curl);

        if (\is_string($latestReleaseJson) === false) {
            throw new RuntimeException('GitHub API request to retrieve the latest release failed');
        }

        $latestRelease = \json_decode($latestReleaseJson, flags: \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY);
        $latestRelease = $latestRelease['tag_name'];

        $releases = \json_decode($releasesJson, flags: \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY);
        if (\is_array($releases) === false) {
            throw new RuntimeException('Couldn\'t decode JSON response from GitHub API');
        }

        foreach ($releases as $releaseInfo) {
            $tagName                  = $releaseInfo['tag_name'];
            $this->releases[$tagName] = [
                'isDraft'      => $releaseInfo['draft'],
                'isLatest'     => ($tagName === $latestRelease),
                'isPrerelease' => $releaseInfo['prerelease'],
            ];
        }
    }

    /**
     * Loop through the list of PHP_CodeSniffer releases and retrieve the XSD files for the last
     * patch release in each minor an save it to a directory per minor.
     *
     * Also save the XSD file for the latest stable release to the "site" root directory.
     *
     * @return void
     */
    private function processReleases(): void
    {
        $lastSeenMinor = '';
        foreach ($this->releases as $tagName => $release) {
            if ($release['isDraft'] === true) {
                continue;
            }

            if (\version_compare($tagName, self::FIRST_XSD_IN, '<') === true) {
                continue;
            }

            if ($lastSeenMinor !== '' && \str_starts_with($tagName, $lastSeenMinor) === true) {
                // We've already handled a later patch version in this minor.
                continue;
            }

            // New minor. Remember which one.
            if (\preg_match('`^([0-9]+\.[0-9]+\.)`', $tagName, $matches) !== 1) {
                throw new RuntimeException('Couldn\'t retrieve minor version number from tag name');
            }
            $lastSeenMinor = $matches[1];

            $contents = $this->getXsdFile($tagName);

            $targetLocation = \dirname(__DIR__, 2) . '/_site/' . \rtrim($lastSeenMinor, '.') . '/phpcs.xsd';
            $this->writeFile($targetLocation, $contents);

            if ($release['isLatest'] === true) {
                $targetLocation = \dirname(__DIR__, 2) . '/_site/phpcs.xsd';
                $this->writeFile($targetLocation, $contents);
            }
        }
    }

    /**
     * Retrieve the XSD file for a specific PHP_CodeSniffer tag.
     *
     * @param string $tagName The tag for which to retrieve the XSD file.
     *
     * @return string
     *
     * @throws \RuntimeException When the contents of the file could not be retrieved.
     */
    private function getXsdFile(string $tagName): string
    {
        $url      = \sprintf(self::XSD_URL, $tagName);
        $contents = \file_get_contents($url);
        if (\is_string($contents) === false) {
            throw new RuntimeException(\sprintf('Failed to read XSD file: %s', $url));
        }

        return $contents;
    }

    /**
     * Write a string to a file.
     *
     * @param string $target   Path to the target file.
     * @param string $contents File contents to write.
     *
     * @return void
     *
     * @throws \RuntimeException When the target directory could not be created.
     * @throws \RuntimeException When the file could not be written to the target directory.
     */
    private function writeFile(string $target, string $contents): void
    {
        // Check if the target directory exists and if not, create it.
        $targetDir = \dirname($target);

        if (@\is_dir($targetDir) === false) {
            if (@\mkdir($targetDir, 0777, true) === false) {
                throw new RuntimeException(\sprintf('Failed to create the %s directory.', $targetDir));
            }
        }

        // Make sure the file always ends on a new line.
        $contents = \rtrim($contents) . "\n";
        if (\file_put_contents($target, $contents) === false) {
            throw new RuntimeException(\sprintf('Failed to write to target location: %s', $target));
        }
    }
}
