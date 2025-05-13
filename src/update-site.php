#!/usr/bin/env php
<?php
/**
 * Website schema.phpcodesniffer.com.
 *
 * Build the `_site` directory and its contents for deployment to a static GH Pages website.
 *
 * @copyright 2025 PHPCSStandards and contributors
 * @license   https://github.com/PHPCSStandards/schema.phpcodesniffer.com/blob/stable/LICENSE BSD Licence
 * @link      https://github.com/PHPCSStandards/schema.phpcodesniffer.com
 */

namespace PHP_CodeSniffer\Schema;

use PHP_CodeSniffer\Schema\Build\UpdateXsdFiles;

require_once __DIR__ . '/Build/UpdateXsdFiles.php';

$updater       = new UpdateXsdFiles();
$updateSuccess = $updater->run();

exit($updateSuccess);
