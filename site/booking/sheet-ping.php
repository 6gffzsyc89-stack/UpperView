<?php // shared-core shim: merged module lives in common/ (adapter: uv engine)
declare(strict_types=1);
if (!defined('CORE_META_GET')) define('CORE_META_GET', 'uv_meta_get');
require_once dirname(__DIR__) . '/common/sheet-ping.php';
function uv_sheet_ping(): void { core_sheet_ping(); }
