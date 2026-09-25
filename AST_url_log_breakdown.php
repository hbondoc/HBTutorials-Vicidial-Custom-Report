<?php
/*
 * HBTutorial - VICIdial URL/Webform Breakdown Report
 * PHP 8.x
 *
 * Features:
 * - Manual remote/external MariaDB connection
 * - URL/Webform breakdown
 * - Campaign filter
 * - Lead status filter
 * - Phone search
 * - Lead ID search
 * - Campaign/Status/Webform result summaries
 * - CSV export
 *
 * Suggested path:
 * /var/www/html/custom/AST_url_log_breakdown.php
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');


/* =========================================================
   REMOTE VICIDIAL DATABASE SETTINGS
   =========================================================
   Change these values to your external DB server.
*/
$DB_HOST = '127.0.0.1';        // VICIdial DB server IP/hostname
$DB_PORT = 3306;                 // MariaDB/MySQL port
$DB_NAME = 'asterisk';           // VICIdial database
$DB_USER = 'cron';         // Read-only report user
$DB_PASS = '1234';

$DB_DEBUG = true;                // true while testing, false in production


/* =========================================================
   DATABASE CONNECTION
   ========================================================= */

mysqli_report(MYSQLI_REPORT_OFF);

$link = mysqli_init();

if (!$link) {
    die('Unable to initialize MySQL connection.');
}

mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

$connected = @mysqli_real_connect(
    $link,
    $DB_HOST,
    $DB_USER,
    $DB_PASS,
    $DB_NAME,
    $DB_PORT
);

if (!$connected) {
    if ($DB_DEBUG) {
        die(
            '<h3>Database connection failed</h3>' .
            '<pre>' .
            htmlspecialchars(
                mysqli_connect_error(),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</pre>'
        );
    }

    die(
        '<h3>Database connection failed.</h3>' .
        '<p>Check DB host, port, username, password, firewall and MariaDB grants.</p>'
    );
}

mysqli_set_charset($link, 'utf8mb4');


/* =========================================================
   HELPERS
   ========================================================= */

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function getUrlParams(string $url): array
{
    if ($url === '') {
        return [];
    }

    $url = html_entity_decode(
        $url,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $query = parse_url($url, PHP_URL_QUERY);

    if ($query === null || $query === false) {
        $pos = strpos($url, '?');

        if ($pos === false) {
            return [];
        }

        $query = substr($url, $pos + 1);
    }

    $params = [];
    parse_str($query, $params);

    return $params;
}

function firstParam(array $params, array $possibleNames): string
{
    foreach ($possibleNames as $name) {
        if (
            isset($params[$name]) &&
            !is_array($params[$name]) &&
            trim((string)$params[$name]) !== ''
        ) {
            return trim((string)$params[$name]);
        }
    }

    foreach ($params as $key => $value) {
        if (is_array($value)) {
            continue;
        }

        foreach ($possibleNames as $wanted) {
            if (strcasecmp((string)$key, $wanted) === 0) {
                return trim((string)$value);
            }
        }
    }

    return '';
}

function normalizePhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);

    if (
        strlen($phone) === 11 &&
        substr($phone, 0, 1) === '1'
    ) {
        $phone = substr($phone, 1);
    }

    return $phone;
}

function badgeClass(string $status): string
{
    $status = strtoupper($status);

    if (in_array($status, ['XFER', 'SALE', 'CLOSER', 'A'], true)) {
        return 'status-green';
    }

    if (in_array($status, ['DROP', 'PDROP', 'XDROP', 'NA', 'N', 'NI'], true)) {
        return 'status-red';
    }

    if (in_array($status, ['NEW', 'CALLBK', 'CBHOLD'], true)) {
        return 'status-blue';
    }

    return 'status-grey';
}


function leadIdFromResponse(string $response): string
{
    $response = trim($response);

    if ($response === '') {
        return '';
    }

    /*
     * Handles examples like:
     * Awebform2 4067073
     * Awebform2_4067073
     * Awebform2-4067073
     */
    if (preg_match('/\bAwebform2[\s_\-:]+(\d+)\b/i', $response, $m)) {
        return $m[1];
    }

    return '';
}

function webformDeliveryState(string $response): array
{
    $response = trim($response);

    if ($response === '') {
        return [
            'state' => 'NO RESPONSE',
            'class' => 'delivery-unknown',
            'note'  => 'No response body was logged'
        ];
    }

    /*
     * Your current logs show Awebform2 + numeric lead ID as the
     * expected positive response. This confirms that the remote
     * endpoint responded with the expected application-level value.
     */
    if (preg_match('/\bAwebform2[\s_\-:]+\d+\b/i', $response)) {
        return [
            'state' => 'SUCCESS',
            'class' => 'delivery-success',
            'note'  => 'Expected Awebform2 response received'
        ];
    }

    if (preg_match('/\b(error|failed|failure|denied|invalid|reject|timeout)\b/i', $response)) {
        return [
            'state' => 'FAILED',
            'class' => 'delivery-failed',
            'note'  => 'Failure text detected in response'
        ];
    }

    return [
        'state' => 'RESPONSE',
        'class' => 'delivery-response',
        'note'  => 'Remote endpoint responded, but response is not in the known success format'
    ];
}


/* =========================================================
   INPUTS
   ========================================================= */

$today = date('Y-m-d');

$startDate = $_GET['start_date'] ?? $today;
$endDate   = $_GET['end_date'] ?? $today;

$startTime = $_GET['start_time'] ?? '00:00:00';
$endTime   = $_GET['end_time'] ?? '23:59:59';

$campaignFilter = trim($_GET['campaign'] ?? '');
$statusFilter   = trim($_GET['status'] ?? '');
$phoneFilter    = normalizePhone($_GET['phone'] ?? '');
$leadFilter     = preg_replace('/\D/', '', $_GET['lead_id'] ?? '');
$urlType        = trim($_GET['url_type'] ?? 'webform');

$limit = (int)($_GET['limit'] ?? 1000);

if (!in_array($limit, [100, 250, 500, 1000, 2500, 5000], true)) {
    $limit = 1000;
}

$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$startDT = $startDate . ' ' . $startTime;
$endDT   = $endDate . ' ' . $endTime;


/* =========================================================
   DROPDOWN DATA
   ========================================================= */

$campaigns = [];

$sqlCampaigns = "
    SELECT campaign_id
    FROM vicidial_campaigns
    ORDER BY campaign_id
";

$resultCampaigns = mysqli_query($link, $sqlCampaigns);

if ($resultCampaigns) {
    while ($r = mysqli_fetch_assoc($resultCampaigns)) {
        $campaigns[] = $r['campaign_id'];
    }
}

$statuses = [];

$sqlStatuses = "
    SELECT status, status_name
    FROM vicidial_statuses
    ORDER BY status
";

$resultStatuses = mysqli_query($link, $sqlStatuses);

if ($resultStatuses) {
    while ($r = mysqli_fetch_assoc($resultStatuses)) {
        $statuses[$r['status']] = $r['status_name'];
    }
}


/* =========================================================
   MAIN QUERY

   IMPORTANT:
   Many VICIdial versions do NOT have lead_id or phone_number
   columns in vicidial_url_log. Therefore we query only the
   columns that exist in the standard URL log, then parse
   lead_id / phone / campaign from the stored URL in PHP.
   ========================================================= */

$sql = "
    SELECT
        uniqueid,
        url_date,
        url_type,
        response_sec,
        url,
        url_response
    FROM vicidial_url_log
    WHERE url_date >= ?
      AND url_date <= ?
";

$types = 'ss';
$params = [$startDT, $endDT];

if ($urlType !== '' && strtoupper($urlType) !== 'ALL') {
    $sql .= " AND url_type = ?";
    $types .= 's';
    $params[] = $urlType;
}

/*
 * Lead ID, phone, campaign and status filters cannot safely be
 * applied in SQL because they may exist only inside vul.url.
 * They are applied after URL parsing below.
 */

$sql .= "
    ORDER BY url_date DESC
    LIMIT ?
";

$types .= 'i';

/*
 * Fetch extra rows because some may be removed later by the
 * campaign/status/lead/phone filters.
 */
$sqlFetchLimit = min(max($limit * 5, $limit), 25000);
$params[] = $sqlFetchLimit;

$stmt = mysqli_prepare($link, $sql);

if (!$stmt) {
    die(
        'SQL prepare error: ' .
        h(mysqli_error($link))
    );
}

mysqli_stmt_bind_param(
    $stmt,
    $types,
    ...$params
);

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);


/* =========================================================
   FIRST PASS: PARSE URL AND COLLECT LEAD IDS
   ========================================================= */

$parsedRows = [];
$leadIds = [];

while ($row = mysqli_fetch_assoc($result)) {

    $urlParams = getUrlParams($row['url'] ?? '');

    $leadId = firstParam(
        $urlParams,
        [
            'lead_id',
            'leadid',
            'lead'
        ]
    );

    if ($leadId === '') {
        $leadId = leadIdFromResponse(
            (string)($row['url_response'] ?? '')
        );
    }

    $urlCampaign = firstParam(
        $urlParams,
        [
            'campaign',
            'campaign_id',
            'campaignid',
            'camp'
        ]
    );

    $urlPhone = firstParam(
        $urlParams,
        [
            'phone_number',
            'phone',
            'caller_number',
            'callerid',
            'caller_id',
            'customer_phone',
            'PhoneNumber',
            'telephone',
            'tel'
        ]
    );

    $parsedRows[] = [
        'row'          => $row,
        'url_params'   => $urlParams,
        'lead_id'      => $leadId,
        'url_campaign' => $urlCampaign,
        'url_phone'    => $urlPhone
    ];

    if ($leadId !== '' && ctype_digit($leadId)) {
        $leadIds[(int)$leadId] = (int)$leadId;
    }
}


/* =========================================================
   LOAD LEAD DETAILS IN ONE QUERY
   ========================================================= */

$leadData = [];

if (count($leadIds) > 0) {

    $idList = implode(
        ',',
        array_map(
            'intval',
            array_values($leadIds)
        )
    );

    $leadSql = "
        SELECT
            vl.lead_id,
            vl.list_id,
            vl.phone_number,
            vl.status,
            vl.first_name,
            vl.last_name,
            vl.state,
            vl.postal_code,
            vli.campaign_id,
            vli.list_name
        FROM vicidial_list vl
        LEFT JOIN vicidial_lists vli
            ON vli.list_id = vl.list_id
        WHERE vl.lead_id IN ($idList)
    ";

    $leadResult = mysqli_query(
        $link,
        $leadSql
    );

    if ($leadResult) {
        while ($lr = mysqli_fetch_assoc($leadResult)) {
            $leadData[(string)$lr['lead_id']] = $lr;
        }
    }
}

/* =========================================================
   PROCESS RECORDS
   ========================================================= */

$records = [];

foreach ($parsedRows as $parsed) {

    $row       = $parsed['row'];
    $urlParams = $parsed['url_params'];
    $leadId    = $parsed['lead_id'];
    $urlCampaign = $parsed['url_campaign'];
    $urlPhone  = $parsed['url_phone'];

    $response = trim(
        (string)($row['url_response'] ?? '')
    );

    /*
     * Some webform URLs do not contain lead_id, while VICIdial's
     * URL response contains it, for example Awebform2_4067073.
     */
    if ($leadId === '') {
        $leadId = leadIdFromResponse($response);
    }

    $lead = [];

    if (
        $leadId !== '' &&
        isset($leadData[$leadId])
    ) {
        $lead = $leadData[$leadId];
    }

    /*
     * Campaign shown in the report:
     * 1. Campaign passed in URL
     * 2. Current campaign associated with lead's list
     */
    $campaign = $urlCampaign !== ''
        ? $urlCampaign
        : ($lead['campaign_id'] ?? '');

    $phone = '';

    if ($urlPhone !== '') {
        $phone = normalizePhone($urlPhone);
    }

    if (
        $phone === '' &&
        !empty($lead['phone_number'])
    ) {
        $phone = normalizePhone(
            (string)$lead['phone_number']
        );
    }

    $urlFirst = firstParam(
        $urlParams,
        ['first_name', 'firstname', 'first']
    );

    $urlLast = firstParam(
        $urlParams,
        ['last_name', 'lastname', 'last']
    );

    $urlState = firstParam(
        $urlParams,
        ['state', 'data_state']
    );

    $urlZip = firstParam(
        $urlParams,
        ['postal_code', 'zipcode', 'zip', 'postal']
    );

    $firstName = $urlFirst !== ''
        ? $urlFirst
        : ($lead['first_name'] ?? '');

    $lastName = $urlLast !== ''
        ? $urlLast
        : ($lead['last_name'] ?? '');

    $state = $urlState !== ''
        ? $urlState
        : ($lead['state'] ?? '');

    $zip = $urlZip !== ''
        ? $urlZip
        : ($lead['postal_code'] ?? '');

    $status = $lead['status'] ?? '';
    $listId = $lead['list_id'] ?? '';

    /*
     * Apply filters AFTER URL parsing / lead lookup.
     */
    if (
        $campaignFilter !== '' &&
        strcasecmp($campaign, $campaignFilter) !== 0
    ) {
        continue;
    }

    if (
        $statusFilter !== '' &&
        strcasecmp($status, $statusFilter) !== 0
    ) {
        continue;
    }

    if (
        $leadFilter !== '' &&
        $leadId !== $leadFilter
    ) {
        continue;
    }

    if (
        $phoneFilter !== '' &&
        strpos($phone, $phoneFilter) === false
    ) {
        continue;
    }

    $delivery = webformDeliveryState($response);

    $resultCode = '';

    if ($response !== '') {
        $parts = preg_split('/\s+/', $response);
        $resultCode = $parts[0] ?? '';
    }

    $vendorId = firstParam(
        $urlParams,
        [
            'vendor_id',
            'vendor_lead_code',
            'vendorleadcode'
        ]
    );

    $source = firstParam(
        $urlParams,
        [
            'source',
            'data_source',
            'source_id'
        ]
    );

    $user = firstParam(
        $urlParams,
        [
            'user',
            'agent',
            'agent_user'
        ]
    );

    $phoneLogin = firstParam(
        $urlParams,
        [
            'phone_login',
            'phonelogin'
        ]
    );

    $records[] = [
        'uniqueid'      => $row['uniqueid'] ?? '',
        'url_date'      => $row['url_date'] ?? '',
        'url_type'      => $row['url_type'] ?? '',
        'response_sec'  => $row['response_sec'] ?? '',
        'lead_id'       => $leadId,
        'list_id'       => $listId,
        'campaign'      => $campaign,
        'phone'         => $phone,
        'status'        => $status,
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'state'         => $state,
        'postal_code'   => $zip,
        'result_code'   => $resultCode,
        'delivery_state'=> $delivery['state'],
        'delivery_class'=> $delivery['class'],
        'delivery_note' => $delivery['note'],
        'url_response'  => $response,
        'vendor_id'     => $vendorId,
        'source'        => $source,
        'user'          => $user,
        'phone_login'   => $phoneLogin,
        'url'           => $row['url'] ?? ''
    ];

    if (count($records) >= $limit) {
        break;
    }
}

/* =========================================================
   CSV EXPORT
   ========================================================= */

if ($export) {

    $filename =
        'webform_url_log_' .
        date('Ymd_His') .
        '.csv';

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    $fp = fopen(
        'php://output',
        'w'
    );

    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    fputcsv(
        $fp,
        [
            'URL Date',
            'Campaign',
            'Lead ID',
            'List ID',
            'Phone Number',
            'Status',
            'First Name',
            'Last Name',
            'State',
            'Postal Code',
            'Delivery',
            'Result',
            'Full Webform Response',
            'Response Seconds',
            'URL Type',
            'Vendor ID',
            'Source',
            'User',
            'Phone Login',
            'Unique ID',
            'Raw URL'
        ]
    );

    foreach ($records as $r) {
        fputcsv(
            $fp,
            [
                $r['url_date'],
                $r['campaign'],
                $r['lead_id'],
                $r['list_id'],
                $r['phone'],
                $r['status'],
                $r['first_name'],
                $r['last_name'],
                $r['state'],
                $r['postal_code'],
                $r['delivery_state'],
                $r['result_code'],
                $r['url_response'],
                $r['response_sec'],
                $r['url_type'],
                $r['vendor_id'],
                $r['source'],
                $r['user'],
                $r['phone_login'],
                $r['uniqueid'],
                $r['url']
            ]
        );
    }

    fclose($fp);
    exit;
}


/* =========================================================
   SUMMARY
   ========================================================= */

$campaignTotals = [];
$statusTotals   = [];
$resultTotals   = [];

foreach ($records as $r) {

    $camp = $r['campaign'] ?: 'UNKNOWN';
    $stat = $r['status'] ?: 'UNKNOWN';
    $res  = $r['result_code'] ?: 'NO RESPONSE';

    if (!isset($campaignTotals[$camp])) {
        $campaignTotals[$camp] = 0;
    }

    if (!isset($statusTotals[$stat])) {
        $statusTotals[$stat] = 0;
    }

    if (!isset($resultTotals[$res])) {
        $resultTotals[$res] = 0;
    }

    $campaignTotals[$camp]++;
    $statusTotals[$stat]++;
    $resultTotals[$res]++;
}

arsort($campaignTotals);
arsort($statusTotals);
arsort($resultTotals);

?>
<!doctype html>
<html lang="en">
<head>

<meta charset="utf-8">

<title>VICIdial Webform URL Log Breakdown</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<style>

body {
    margin: 0;
    background: #f3f5f8;
    font-family: Arial, Helvetica, sans-serif;
    color: #202428;
}

.header {
    background: #19385f;
    color: #fff;
    padding: 18px 25px;
}

.header h1 {
    margin: 0;
    font-size: 22px;
}

.header small {
    display: block;
    margin-top: 4px;
    opacity: .8;
}

.container {
    padding: 20px;
}

.panel {
    background: #fff;
    border: 1px solid #d7dce1;
    border-radius: 7px;
    padding: 18px;
    margin-bottom: 20px;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: end;
}

.field label {
    display: block;
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 4px;
}

.field input,
.field select {
    height: 35px;
    box-sizing: border-box;
    border: 1px solid #bbc3cc;
    border-radius: 4px;
    padding: 5px 8px;
    background: #fff;
}

button,
.btn {
    display: inline-block;
    border: 0;
    background: #1e5d9e;
    color: #fff;
    padding: 10px 15px;
    border-radius: 4px;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
}

.btn-green {
    background: #27784b;
}

.summary-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.summary-box {
    flex: 1;
    min-width: 260px;
    background: #fff;
    border: 1px solid #d7dce1;
    border-radius: 7px;
    overflow: hidden;
}

.summary-title {
    background: #eef2f5;
    font-weight: bold;
    padding: 10px;
}

.summary-box table {
    width: 100%;
    border-collapse: collapse;
}

.summary-box td {
    padding: 7px 10px;
    border-top: 1px solid #eceff2;
    font-size: 13px;
}

.summary-box td:last-child {
    text-align: right;
    font-weight: bold;
}

.table-wrapper {
    overflow-x: auto;
    background: #fff;
    border: 1px solid #d7dce1;
    border-radius: 7px;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.report-table th {
    position: sticky;
    top: 0;
    background: #274f78;
    color: white;
    padding: 9px 7px;
    white-space: nowrap;
    text-align: left;
}

.report-table td {
    border-bottom: 1px solid #e6e8eb;
    padding: 7px;
    vertical-align: top;
    white-space: nowrap;
}

.report-table tr:nth-child(even) {
    background: #f9fafb;
}

.report-table tr:hover {
    background: #fffbdc;
}

.status {
    display: inline-block;
    padding: 3px 6px;
    border-radius: 4px;
    color: #fff;
    min-width: 40px;
    text-align: center;
    font-weight: bold;
}

.status-green {
    background: #2b7d4c;
}

.status-red {
    background: #bd3b3b;
}

.status-blue {
    background: #3478b9;
}

.status-grey {
    background: #65717c;
}


.delivery {
    display: inline-block;
    padding: 3px 7px;
    border-radius: 4px;
    color: #fff;
    font-weight: bold;
    min-width: 62px;
    text-align: center;
}

.delivery-success {
    background: #27864f;
}

.delivery-failed {
    background: #c0392b;
}

.delivery-response {
    background: #2d6fa8;
}

.delivery-unknown {
    background: #777;
}

.response {
    max-width: 260px;
    white-space: normal !important;
    word-break: break-word;
}

.raw-url {
    max-width: 300px;
    white-space: normal !important;
    word-break: break-all;
    font-family: monospace;
    font-size: 11px;
}

details summary {
    cursor: pointer;
    color: #14589a;
    font-weight: bold;
}

.total {
    margin-bottom: 10px;
    font-size: 13px;
}

.db-info {
    margin-top: 8px;
    font-size: 12px;
    opacity: .8;
}

</style>

</head>

<body>

<div class="header">

    <h1>
        VICIdial Webform URL Log Breakdown
    </h1>

    <small>
        Campaign • Phone Number • Lead Status • Webform Result
    </small>

    <div class="db-info">
        DB Server:
        <?=h($DB_HOST)?>
        :
        <?=h($DB_PORT)?>
        /
        <?=h($DB_NAME)?>
    </div>

</div>

<div class="container">

<div class="panel">

<form method="get">

<div class="filters">

    <div class="field">
        <label>Start Date</label>
        <input
            type="date"
            name="start_date"
            value="<?=h($startDate)?>"
        >
    </div>

    <div class="field">
        <label>Start Time</label>
        <input
            type="time"
            step="1"
            name="start_time"
            value="<?=h($startTime)?>"
        >
    </div>

    <div class="field">
        <label>End Date</label>
        <input
            type="date"
            name="end_date"
            value="<?=h($endDate)?>"
        >
    </div>

    <div class="field">
        <label>End Time</label>
        <input
            type="time"
            step="1"
            name="end_time"
            value="<?=h($endTime)?>"
        >
    </div>

    <div class="field">

        <label>Campaign</label>

        <select name="campaign">

            <option value="">
                ALL
            </option>

            <?php foreach ($campaigns as $campaign): ?>

                <option
                    value="<?=h($campaign)?>"
                    <?=$campaignFilter === $campaign
                        ? 'selected'
                        : ''?>
                >
                    <?=h($campaign)?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div class="field">

        <label>Status</label>

        <select name="status">

            <option value="">
                ALL
            </option>

            <?php foreach ($statuses as $code => $name): ?>

                <option
                    value="<?=h($code)?>"
                    <?=$statusFilter === $code
                        ? 'selected'
                        : ''?>
                >
                    <?=h($code)?>
                    -
                    <?=h($name)?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div class="field">

        <label>Phone</label>

        <input
            type="text"
            name="phone"
            value="<?=h($phoneFilter)?>"
            placeholder="8649341939"
        >

    </div>

    <div class="field">

        <label>Lead ID</label>

        <input
            type="text"
            name="lead_id"
            value="<?=h($leadFilter)?>"
            placeholder="4000306"
        >

    </div>

    <div class="field">

        <label>URL Type</label>

        <select name="url_type">

            <option
                value="webform"
                <?=$urlType === 'webform'
                    ? 'selected'
                    : ''?>
            >
                webform
            </option>

            <option
                value="ALL"
                <?=strtoupper($urlType) === 'ALL'
                    ? 'selected'
                    : ''?>
            >
                ALL
            </option>

        </select>

    </div>

    <div class="field">

        <label>Maximum Rows</label>

        <select name="limit">

            <?php foreach ([100,250,500,1000,2500,5000] as $l): ?>

                <option
                    value="<?=$l?>"
                    <?=$limit === $l
                        ? 'selected'
                        : ''?>
                >
                    <?=$l?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div class="field">

        <button type="submit">
            Run Report
        </button>

    </div>

    <div class="field">

        <?php
        $csvQuery = $_GET;
        $csvQuery['export'] = 'csv';
        ?>

        <a
            class="btn btn-green"
            href="?<?=h(http_build_query($csvQuery))?>"
        >
            Export CSV
        </a>

    </div>

</div>

</form>

</div>


<div class="total">

<strong>
    Total records:
</strong>

<?=number_format(count($records))?>

</div>


<div class="summary-row">

<div class="summary-box">

<div class="summary-title">
Campaign Breakdown
</div>

<table>

<?php foreach ($campaignTotals as $name => $count): ?>

<tr>
    <td><?=h($name)?></td>
    <td><?=number_format($count)?></td>
</tr>

<?php endforeach; ?>

</table>

</div>


<div class="summary-box">

<div class="summary-title">
Lead Status Breakdown
</div>

<table>

<?php foreach ($statusTotals as $name => $count): ?>

<tr>
    <td><?=h($name)?></td>
    <td><?=number_format($count)?></td>
</tr>

<?php endforeach; ?>

</table>

</div>


<div class="summary-box">

<div class="summary-title">
Webform Result Breakdown
</div>

<table>

<?php foreach ($resultTotals as $name => $count): ?>

<tr>
    <td><?=h($name)?></td>
    <td><?=number_format($count)?></td>
</tr>

<?php endforeach; ?>

</table>

</div>

</div>

<br>


<div class="table-wrapper">

<table class="report-table">

<thead>

<tr>
    <th>#</th>
    <th>Date</th>
    <th>Campaign</th>
    <th>Lead ID</th>
    <th>List</th>
    <th>Phone Number</th>
    <th>Status</th>
    <th>Name</th>
    <th>State</th>
    <th>ZIP</th>
    <th>Delivery</th>
    <th>Webform Result</th>
    <th>Response</th>
    <th>Resp Sec</th>
    <th>Source</th>
    <th>Vendor</th>
    <th>User</th>
    <th>URL</th>
</tr>

</thead>

<tbody>

<?php

$i = 0;

foreach ($records as $r):

$i++;

?>

<tr>

<td>
<?=$i?>
</td>

<td>
<?=h($r['url_date'])?>
</td>

<td>
<strong>
<?=h($r['campaign'])?>
</strong>
</td>

<td>
<?=h($r['lead_id'])?>
</td>

<td>
<?=h($r['list_id'])?>
</td>

<td>
<strong>
<?=h($r['phone'])?>
</strong>
</td>

<td>

<span class="status <?=h(badgeClass($r['status']))?>">

<?=h(
    $r['status'] !== ''
        ? $r['status']
        : '-'
)?>

</span>

</td>

<td>

<?=h(
    trim(
        $r['first_name'] .
        ' ' .
        $r['last_name']
    )
)?>

</td>

<td>
<?=h($r['state'])?>
</td>

<td>
<?=h($r['postal_code'])?>
</td>

<td>
<span
    class="delivery <?=h($r['delivery_class'])?>"
    title="<?=h($r['delivery_note'])?>"
>
<?=h($r['delivery_state'])?>
</span>
</td>

<td>

<strong>
<?=h($r['result_code'])?>
</strong>

</td>

<td class="response">
<?=h($r['url_response'])?>
</td>

<td>
<?=h($r['response_sec'])?>
</td>

<td>
<?=h($r['source'])?>
</td>

<td>
<?=h($r['vendor_id'])?>
</td>

<td>

<?=h($r['user'])?>

<?php if ($r['phone_login'] !== ''): ?>

<br>

<small>
Phone:
<?=h($r['phone_login'])?>
</small>

<?php endif; ?>

</td>

<td class="raw-url">

<details>

<summary>
View URL
</summary>

<?=h($r['url'])?>

</details>

</td>

</tr>

<?php endforeach; ?>


<?php if (!$records): ?>

<tr>

<td
    colspan="18"
    style="text-align:center;padding:30px;"
>
    No URL log records found.
</td>

</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</body>
</html>
