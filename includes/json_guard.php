<?php
/**
 * JSON-endpoint bootstrap — SINGLE source of truth for error handling on APIs
 * that emit JSON. Call this as the very first thing in such an endpoint, before
 * any other require (a require that emits a warning would otherwise corrupt the
 * JSON body).
 *
 *   require_once __DIR__ . '/includes/json_guard.php'; kits_json_guard();
 *
 * What it does:
 *  - ob_start(): buffers all output so a stray whitespace/notice can't break the
 *    JSON the endpoint echoes at the end.
 *  - display_errors OFF: PHP warnings/notices never reach the browser (they would
 *    turn valid JSON into un-parseable text). error_reporting stays E_ALL so
 *    everything is still written to the PHP error_log — hidden, not silenced.
 *
 * To debug an endpoint locally, temporarily flip display_errors to '1' HERE (one
 * place), never per-file.
 */
if (!function_exists('kits_json_guard')) {
    function kits_json_guard(): void
    {
        ob_start();
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL); // still logged to error_log, just not sent to the browser
    }
}
