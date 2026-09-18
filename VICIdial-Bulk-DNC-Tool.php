<?php
/************************************************************
 * HBTutorial VICIdial Bulk DNC + Inbound Filter Tool
 * NO PASSWORD VERSION
 *
 * Features:
 *  - Add to System / Global DNC: vicidial_dnc
 *  - Add to one Campaign DNC: vicidial_campaign_dnc
 *  - Add to ALL active Campaign DNCs
 *  - Add to one inbound Filter Phone Group
 *  - Add to ALL inbound Filter Phone Groups
 *
 * Works with:
 *  - ViciBox / openSUSE
 *  - AlmaLinux / Rocky / CentOS
 ************************************************************/

/***********************
 * CONFIG
 ***********************/
$db_host = "127.0.0.1";
$db_user = "cron";
$db_pass = "1234";
$db_name = "asterisk";

$default_filter_phone_group_id = "StopCalling";
$max_bulk_numbers = 5000;

/***********************
 * DB CONNECTION
 ***********************/
// Prevent PHP 8.1+ mysqli strict mode from turning an individual failed insert
// into an uncaught exception/HTTP 500. Failed inserts are counted in the result.
mysqli_report(MYSQLI_REPORT_OFF);

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_error) {
    die("Database connection failed: " . htmlspecialchars($mysqli->connect_error));
}

$message = "";
$error = "";

/***********************
 * FUNCTIONS
 ***********************/
function clean_phone($phone) {
    $phone = preg_replace('/\D+/', '', $phone);

    // Remove leading 1 from 11-digit US number
    if (strlen($phone) == 11 && substr($phone, 0, 1) == "1") {
        $phone = substr($phone, 1);
    }

    return $phone;
}

function safe_text($text) {
    return htmlspecialchars($text ?? "", ENT_QUOTES, "UTF-8");
}

function parse_phone_numbers($raw_phone_numbers) {
    // One number per line is recommended. Commas and semicolons also work.
    // Spaces, parentheses, dashes and a leading + are allowed inside a number.
    $items = preg_split('/[\r\n,;]+/', $raw_phone_numbers);
    $valid = [];
    $invalid = [];

    foreach ($items as $item) {
        $original = trim($item);

        if ($original === "") {
            continue;
        }

        $phone = clean_phone($original);

        if (strlen($phone) < 7 || strlen($phone) > 15) {
            $invalid[] = $original;
            continue;
        }

        // Associative keys remove duplicate numbers from the submitted batch.
        $valid[$phone] = $phone;
    }

    return [array_values($valid), $invalid];
}

function record_exists($mysqli, $table, $where_sql, $types, $params) {
    $sql = "SELECT COUNT(*) AS total FROM $table WHERE $where_sql";
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        return false;
    }

    if (!$stmt->bind_param($types, ...$params)) {
        $stmt->close();
        return false;
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }

    // bind_result() works without the optional mysqlnd PHP driver.
    $total = 0;
    if (!$stmt->bind_result($total) || !$stmt->fetch()) {
        $stmt->close();
        return false;
    }

    $stmt->close();

    return intval($total) > 0;
}

/***********************
 * GET CAMPAIGNS
 ***********************/
$campaigns = [];
$result = $mysqli->query("
    SELECT campaign_id, campaign_name, active
    FROM vicidial_campaigns
    ORDER BY campaign_id
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $campaigns[] = $row;
    }
}

/***********************
 * GET FILTER PHONE GROUPS
 ***********************/
$filter_groups = [];
$result = $mysqli->query("
    SELECT filter_phone_group_id, filter_phone_group_name
    FROM vicidial_filter_phone_groups
    ORDER BY filter_phone_group_id
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $filter_groups[] = $row;
    }
}

/***********************
 * FORM SUBMIT
 ***********************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_dnc'])) {
    $raw_phone_numbers = trim($_POST['phone_numbers'] ?? "");
    $campaign_id = trim($_POST['campaign_id'] ?? "");
    $selected_filter_phone_group_id = trim($_POST['filter_phone_group_id'] ?? "");
    $reason = trim($_POST['reason'] ?? "Do Not Call / Stop Calling");

    $add_system_dnc = isset($_POST['add_system_dnc']);
    $add_campaign_dnc = isset($_POST['add_campaign_dnc']);
    $add_inbound_filter = isset($_POST['add_inbound_filter']);

    [$phone_numbers, $invalid_numbers] = parse_phone_numbers($raw_phone_numbers);

    if (count($phone_numbers) === 0) {
        $error = "No valid phone numbers were found. Enter one number per line.";
    } elseif (count($phone_numbers) > $max_bulk_numbers) {
        $error = "Too many unique numbers. The maximum per submission is " . $max_bulk_numbers . ".";
    } elseif (!$add_system_dnc && !$add_campaign_dnc && !$add_inbound_filter) {
        $error = "Please select at least one option.";
    } else {
        $totals = [
            'submitted' => count($phone_numbers) + count($invalid_numbers),
            'valid_unique' => count($phone_numbers),
            'invalid' => count($invalid_numbers),
            'system_added' => 0,
            'system_exists' => 0,
            'system_failed' => 0,
            'campaign_added' => 0,
            'campaign_exists' => 0,
            'campaign_failed' => 0,
            'filter_added' => 0,
            'filter_exists' => 0,
            'filter_failed' => 0,
        ];

        foreach ($phone_numbers as $phone_number) {
            /***********************
             * 1. SYSTEM / GLOBAL DNC
             ***********************/
            if ($add_system_dnc) {
                if (!record_exists(
                    $mysqli,
                    "vicidial_dnc",
                    "phone_number = ?",
                    "s",
                    [$phone_number]
                )) {
                    $stmt = $mysqli->prepare("
                        INSERT INTO vicidial_dnc
                        (phone_number)
                        VALUES (?)
                    ");

                    if ($stmt) {
                        $stmt->bind_param("s", $phone_number);
                        $inserted = $stmt->execute();
                        $stmt->close();

                        if ($inserted) {
                            $totals['system_added']++;
                        } else {
                            $totals['system_failed']++;
                        }
                    } else {
                        $totals['system_failed']++;
                    }
                } else {
                    $totals['system_exists']++;
                }
            }

            /***********************
             * 2. CAMPAIGN DNC
             ***********************/
            if ($add_campaign_dnc && $campaign_id !== "") {
                if ($campaign_id === "ALL") {
                    foreach ($campaigns as $camp) {
                        if ($camp['active'] !== "Y") {
                            continue;
                        }

                        $camp_id = $camp['campaign_id'];

                        if (!record_exists(
                            $mysqli,
                            "vicidial_campaign_dnc",
                            "phone_number = ? AND campaign_id = ?",
                            "ss",
                            [$phone_number, $camp_id]
                        )) {
                            $stmt = $mysqli->prepare("
                                INSERT INTO vicidial_campaign_dnc
                                (phone_number, campaign_id)
                                VALUES (?, ?)
                            ");

                            if ($stmt) {
                                $stmt->bind_param("ss", $phone_number, $camp_id);
                                $inserted = $stmt->execute();
                                $stmt->close();

                                if ($inserted) {
                                    $totals['campaign_added']++;
                                } else {
                                    $totals['campaign_failed']++;
                                }
                            } else {
                                $totals['campaign_failed']++;
                            }
                        } else {
                            $totals['campaign_exists']++;
                        }
                    }
                } else {
                    if (!record_exists(
                        $mysqli,
                        "vicidial_campaign_dnc",
                        "phone_number = ? AND campaign_id = ?",
                        "ss",
                        [$phone_number, $campaign_id]
                    )) {
                        $stmt = $mysqli->prepare("
                            INSERT INTO vicidial_campaign_dnc
                            (phone_number, campaign_id)
                            VALUES (?, ?)
                        ");

                        if ($stmt) {
                            $stmt->bind_param("ss", $phone_number, $campaign_id);
                            $inserted = $stmt->execute();
                            $stmt->close();

                            if ($inserted) {
                                $totals['campaign_added']++;
                            } else {
                                $totals['campaign_failed']++;
                            }
                        } else {
                            $totals['campaign_failed']++;
                        }
                    } else {
                        $totals['campaign_exists']++;
                    }
                }
            }

            /***********************
             * 3. INBOUND FILTER PHONE GROUP
             ***********************/
            if ($add_inbound_filter && $selected_filter_phone_group_id !== "") {
                if ($selected_filter_phone_group_id === "ALL") {
                    foreach ($filter_groups as $group) {
                        $group_id = $group['filter_phone_group_id'];

                        if (!record_exists(
                            $mysqli,
                            "vicidial_filter_phone_numbers",
                            "phone_number = ? AND filter_phone_group_id = ?",
                            "ss",
                            [$phone_number, $group_id]
                        )) {
                            $stmt = $mysqli->prepare("
                                INSERT INTO vicidial_filter_phone_numbers
                                (filter_phone_group_id, phone_number, phone_description)
                                VALUES (?, ?, ?)
                            ");

                            if ($stmt) {
                                $description = $reason;
                                $stmt->bind_param("sss", $group_id, $phone_number, $description);
                                if ($stmt->execute()) {
                                    $totals['filter_added']++;
                                } else {
                                    $totals['filter_failed']++;
                                }
                                $stmt->close();
                            } else {
                                $totals['filter_failed']++;
                            }
                        } else {
                            $totals['filter_exists']++;
                        }
                    }
                } else {
                    if (!record_exists(
                        $mysqli,
                        "vicidial_filter_phone_numbers",
                        "phone_number = ? AND filter_phone_group_id = ?",
                        "ss",
                        [$phone_number, $selected_filter_phone_group_id]
                    )) {
                        $stmt = $mysqli->prepare("
                            INSERT INTO vicidial_filter_phone_numbers
                            (filter_phone_group_id, phone_number, phone_description)
                            VALUES (?, ?, ?)
                        ");

                        if ($stmt) {
                            $description = $reason;
                            $stmt->bind_param("sss", $selected_filter_phone_group_id, $phone_number, $description);
                            if ($stmt->execute()) {
                                $totals['filter_added']++;
                            } else {
                                $totals['filter_failed']++;
                            }
                            $stmt->close();
                        } else {
                            $totals['filter_failed']++;
                        }
                    } else {
                        $totals['filter_exists']++;
                    }
                }
            }
        }

        $actions = [
            "Submitted entries: " . $totals['submitted'],
            "Valid unique numbers processed: " . $totals['valid_unique'],
            "Invalid entries skipped: " . $totals['invalid'],
        ];

        if ($add_system_dnc) {
            $actions[] = "System DNC — added: {$totals['system_added']}, already existed: {$totals['system_exists']}, failed: {$totals['system_failed']}";
        }

        if ($add_campaign_dnc) {
            if ($campaign_id === "") {
                $actions[] = "Campaign DNC skipped because no campaign was selected.";
            } else {
                $actions[] = "Campaign DNC — added: {$totals['campaign_added']}, already existed: {$totals['campaign_exists']}, failed: {$totals['campaign_failed']}";
            }
        }

        if ($add_inbound_filter) {
            if ($selected_filter_phone_group_id === "") {
                $actions[] = "Inbound filter skipped because no filter group was selected.";
            } else {
                $actions[] = "Inbound filters — added: {$totals['filter_added']}, already existed: {$totals['filter_exists']}, failed: {$totals['filter_failed']}";
            }
        }

        if (count($invalid_numbers) > 0) {
            $invalid_preview = array_slice($invalid_numbers, 0, 20);
            $actions[] = "Invalid entries: " . safe_text(implode(", ", $invalid_preview)) .
                (count($invalid_numbers) > 20 ? " …" : "");
        }

        $message = implode("<br>", $actions);
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>VICIdial DNC Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 850px;
            margin: auto;
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        }

        h2 {
            margin-top: 0;
            color: #222;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        input[type="text"],
        select,
        textarea,
        button {
            width: 100%;
            padding: 12px;
            margin-top: 6px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        button {
            background: #1f6feb;
            color: white;
            border: none;
            font-weight: bold;
            cursor: pointer;
            margin-top: 20px;
            font-size: 16px;
        }

        button:hover {
            background: #1557bd;
        }

        .success {
            background: #e7f7e7;
            color: #0b6b0b;
            border: 1px solid #a6dca6;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .error {
            background: #fde8e8;
            color: #a40000;
            border: 1px solid #f0b0b0;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .note {
            background: #fff8db;
            padding: 12px;
            border-left: 4px solid #e0b400;
            margin-top: 20px;
            font-size: 14px;
            line-height: 1.6;
        }

        .small {
            font-size: 13px;
            color: #555;
        }

        .header {
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }

        .check-box {
            background: #f8fafc;
            border: 1px solid #d0d7de;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
        }

        .check-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 8px 0;
        }

        .check-row input {
            width: auto;
            margin: 0;
        }

        .check-row label {
            margin: 0;
            font-weight: normal;
        }

        .section-title {
            margin-top: 22px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 17px;
            color: #333;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container">

    <div class="header">
        <h2>VICIdial DNC + Inbound Block Tool</h2>
        <p>
            Add multiple numbers to System DNC, Campaign DNC, all active campaigns,
            selected inbound filter group, or all inbound filter groups.
        </p>
    </div>

    <?php if ($message): ?>
        <div class="success"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?php echo safe_text($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="add_dnc" value="1">

        <label>Phone Numbers</label>
        <textarea name="phone_numbers" rows="10" placeholder="Enter one number per line&#10;4453100585&#10;12125550123&#10;(305) 555-0123" required><?php echo safe_text($_POST['phone_numbers'] ?? ""); ?></textarea>
        <div class="small">
            Maximum <?php echo intval($max_bulk_numbers); ?> unique numbers per submission.
            Use one number per line; commas and semicolons are also supported.
        </div>

        <div class="section-title">DNC Options</div>

        <div class="check-box">
            <div class="check-row">
                <input type="checkbox" name="add_system_dnc" id="add_system_dnc" checked>
                <label for="add_system_dnc">
                    Add to System / Global DNC
                    <b>vicidial_dnc</b>
                </label>
            </div>

            <div class="check-row">
                <input type="checkbox" name="add_campaign_dnc" id="add_campaign_dnc" checked>
                <label for="add_campaign_dnc">
                    Add to Campaign DNC
                    <b>vicidial_campaign_dnc</b>
                </label>
            </div>

            <div class="check-row">
                <input type="checkbox" name="add_inbound_filter" id="add_inbound_filter" checked>
                <label for="add_inbound_filter">
                    Add to Inbound Filter Phone Group
                    <b>vicidial_filter_phone_numbers</b>
                </label>
            </div>
        </div>

        <label>Campaign DNC Selection</label>
        <select name="campaign_id">
            <option value="">-- Skip campaign DNC / no campaign selected --</option>
            <option value="ALL">ALL ACTIVE CAMPAIGNS</option>

            <?php foreach ($campaigns as $camp): ?>
                <option value="<?php echo safe_text($camp['campaign_id']); ?>">
                    <?php
                        echo safe_text(
                            $camp['campaign_id'] .
                            " - " .
                            $camp['campaign_name'] .
                            " - Active: " .
                            $camp['active']
                        );
                    ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Inbound Filter Phone Group Selection</label>
        <select name="filter_phone_group_id">
            <option value="">-- Skip inbound filter group --</option>
            <option value="ALL">ALL FILTER PHONE GROUPS</option>

            <?php foreach ($filter_groups as $group): ?>
                <option value="<?php echo safe_text($group['filter_phone_group_id']); ?>"
                    <?php
                        if ($group['filter_phone_group_id'] == $default_filter_phone_group_id) {
                            echo "selected";
                        }
                    ?>
                >
                    <?php
                        echo safe_text(
                            $group['filter_phone_group_id'] .
                            " - " .
                            $group['filter_phone_group_name']
                        );
                    ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Reason / Description</label>
        <textarea name="reason" rows="3">Do Not Call / Stop Calling</textarea>

        <button type="submit">Add Numbers to Selected DNC Options</button>
    </form>

    <div class="note">
        <b>System / Global DNC:</b> vicidial_dnc<br>
        <b>Campaign DNC:</b> vicidial_campaign_dnc<br>
        <b>Campaign ALL option:</b> Adds only to campaigns where active = Y<br>
        <b>Inbound Filter Numbers:</b> vicidial_filter_phone_numbers<br>
        <b>Inbound Filter Groups:</b> vicidial_filter_phone_groups<br>
        <b>Default selected filter group:</b> <?php echo safe_text($default_filter_phone_group_id); ?>
    </div>

    <p class="small">
        Important: this version has no login password. Anyone who can access this URL can add numbers to DNC.
        Restrict this page by firewall, VPN, Apache basic authentication, or IP allowlist.
    </p>

</div>

</body>
</html>
