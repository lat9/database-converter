<?php
/**
 * convert_database.php
 *
 * A tool to convert a Zen-Cart database to either a utf8 or utf8mb4 character-set/collation; also
 * provides the means to correct zero-date fields and their defaults.
 *
 * Based on:
 *
 * 1) convert_db2utf8.php
 *
 * @package eCommerce-Service
 * @copyright Copyright 2004-2007, Andrew Berezin eCommerce-Service.com
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 *
 * Updated 2014-06-28 to change mysql_ functions to mysqli_ functions, for compatibility with PHP >= 5.4
 * Updated 2015-01-11 (lat9).  Check for, and convert, overall database collation, too!
 * Updated 2018-01-01 (mc12345678).  Support both quoted and unquoted databases when converting the overall database collation.
 *
 * 2) utf8mb4-conversion.php:
 *
 * A script to convert database collation/charset to utf8mb4
 *
 * @copyright Copyright 2003-2021 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: utf8mb4-conversion.php $
 *
 * @copyright Adapted from http://stackoverflow.com/questions/105572/ and https://mathiasbynens.be/notes/mysql-utf8mb4
 *
 * NOTE!!!! NOTE!!!! NOTE!!!!
 * You should upgrade your Zen Cart store (and database) to at least v1.5.6 before running this script. 
 * (This is because the schema updates in v1.5.6 fix index lengths that are required for utf8mb4.)
 *
 * Also, MySQL 5.7 or newer is recommended in order to benefit from the "more complete" utf8mb4_unicode_520_ci collation.
 */
define('ZCDB_VERSION', 'v2.0.1');
error_reporting(E_ALL);

// -----
// Load and instantiate the database-helper class; if any initial issue is found
// that class will 'die' with a message.
//
require 'ConvertDb.php';
$cdb = new ConvertDb();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Zen Cart&reg; Database Conversion Tool</title>
    <style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 85%;
    }
    #db-stats td:nth-of-type(1) {
        font-weight: bold;
        text-align: right;
    }
    #tables-table {
        width: 100%;
        margin-bottom: 1rem;
    }
    #tables-table, #tables-table th, #tables-table td {
        border: 1px solid black;
        border-collapse: collapse;
    }
    th, td {
        padding: 0.2rem;
    }
    .text-right {
        text-align: right;
    }
    .text-left {
        text-align: left;
    }
    .text-center {
        text-align: center;
    }
    .text-danger,
    sup {
        font-weight: bold;
        color: red;
    }
    .text-success {
        font-weight: bold;
        color: green;
    }
    .db-stat {
        margin-left: 2rem;
    }
    #form {
        vertical-align: top;
    }
    ul, ol {
        margin: 0;
        padding-left: 1rem;
    }
    #issues-list {
        margin-bottom: 1rem;
    }
    #zero-date-form, #fixcollations {
        margin-top: 1rem;
    }
    hr {
        border-top: 2px dotted black;
        border-bottom: none;
    }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Zen Cart&reg; Database Conversion Tool &mdash; <small><?php echo ZCDB_VERSION; ?></small></h1>
<?php
// -----
// Retrieve the current database's tables and associated fields.
//
$tables = $cdb->getTablesAndFields();

$current_db_collation = $cdb->getCurrentDbCollation();
$zc_database_version = $cdb->getZcDatabaseVersion();
$database_name = $cdb->getDbName();
$database_prefix = $cdb->getDbPrefix();

// -----
// If a conversion 'action' is indicated ...
//
if (isset($_POST['action'])) {
    $messages = [];
    $conversion_in_process = isset($_POST['conversion_step']);
    switch ($_POST['action']) {
        // -----
        // Request is to correct date/datetime 0000-00-00[ 00:00:00] defaults.
        //
        case 'fixdatedefaults':
            $change_count = 0;
            foreach ($tables as $table_name => $table_info) {
                $result = $cdb->correctTableFieldsZeroDateDefaults($table_name);
                if ($result === false) {
                    $messages[] = $cdb->getErrorMsg();
                } else {
                    $change_count += $result;
                    $additional_messages = $cdb->getMessages();
                    foreach ($additional_messages as $next_message) {
                        $messages[] = $next_message;
                    }
                }
            }
            if ($change_count === 0) {
                $completion_message = 'No zero-date field default updates were required.';
            } else {
                $completion_message = "$change_count zero-date field defaults were updated.";
            }
            break;

        // -----
        // Request is to correct table/field collations that don't match the database's overall collation.
        //
        case 'fixcollations':
            if (empty($_POST['tablenames'])) {
                $completion_message = 'No tables identified for table/fields collation fix-ups.';
                break;
            }
            $tables_to_fix = explode(',', $_POST['tablenames']);
            ob_implicit_flush();
            $cdb->startTimerOne();
            foreach ($tables_to_fix as $next_table) {
                $cdb->startTimerTwo();
                echo "<p>Fixing table and field collations for <code>$next_table</code> ...</p>";
                $fields_updated = $cdb->updateTableCollations($next_table, $current_db_collation);
                $timer_value = $cdb->getTimerTwo();
                if ($fields_updated === false) {
                    $messages[] = $cdb->getErrorMsg();
                    break;
                } elseif ($fields_updated === 0) {
                    echo "<code>$next_table</code> required no updates to its collations.<br>";
                } else {
                    if ($fields_updated < 1000) {
                        echo "$fields_updated fields in <code>$next_table</code> were updated to <code>$current_db_collation</code>; execution time: $timer_value.<br>";
                    } else {
                        $fields_updated -= 1000;
                        if ($fields_updated === 0) {
                            echo "The collation for <code>$next_table</code> was updated to <code>$current_db_collation</code>; execution time: $timer_value.<br>";
                        } else {
                            echo "The collation for <code>$next_table</code> and $fields_updated of its fields were updated to <code>$current_db_collation</code>; execution time: $timer_value.<br>";
                        }
                    }
                }
            }
            $completion_message = 'Collation fixups were completed; execution time: ' . $cdb->getTimerOne();
            break;

        // -----
        // Request is to check for zero-values in date/datetime fields.
        //
        case 'checkzerodates':
            $result = true;
            $zero_date_count = 0;
            $zero_date_tables = [];
            foreach ($tables as $table_name => $table_info) {
                $result = $cdb->checkTableFieldsForZeroDates($table_name);
                if ($result === false) {
                    $zero_date_count = 0;
                    $messages[] = $cdb->getErrorMsg();
                    break;
                } elseif ($result !== 0) {
                    $zero_date_count += $result;
                    $zero_date_tables[] = $table_name;
                    $messages[] = "$result date/datetime/timestamp fields contain zero-date values in <code>$table_name</code>.";
                }
            }
            if ($result === false) {
                $completion_message = 'An error occurred looking for zero-date fields.';
            } elseif ($zero_date_count === 0) {
                $completion_message = 'No zero-value date/datetime fields were found in the database.';
            } else {
                $completion_message = "$zero_date_count zero-value date/datetime fields were found in the database.";
            }
            break;

        // -----
        // Request is to correct any date/datetime fields with zero-date values.
        //
        case 'correctzerodatevalues':
            if (!isset($_POST['zerodatetables'])) {
                $completion_message = 'Unable to correct the zero-values in date/datetime fields in the database.';
                break;
            }
            $zero_dates_corrected = 0;
            $zero_date_tables = explode(',', $_POST['zerodatetables']);
            $zero_date_correction_error = false;
            foreach ($zero_date_tables as $next_table) {
                $result = $cdb->correctZeroDateValuesInTable($next_table);
                if ($result === false) {
                    $messages[] = $cdb->getErrorMsg();
                    $zero_date_correction_error = true;
                    break;
                } elseif ($result !== 0) {
                    $zero_dates_corrected += $result;
                    $messages[] = "$result date/datetime fields with zero-date values were corrected in <code>$next_table</code>.";
                }
            }
            if ($zero_dates_corrected === 0) {
                $completion_message = 'No zero-value date/datetime fields required correction in the database.';
            } else {
                $completion_message = "$zero_dates_corrected zero-value date/datetime fields were corrected in the database.";
            }
            break;

        // -----
        // Request selects a database character-set, first step in converting a database from one
        // 'charset' to another.
        //
        case 'choosecharset':
            if (!isset($_POST['new_charset'])) {
                $completion_message = 'No character-set selected for database conversion.';
                $_POST['action'] = '';
                break;
            }
            $collation_choices = $cdb->getCollationsForCharset($_POST['new_charset']);
            if ($collation_choices === false) {
                $completion_message = $cdb->getErrorMsg();
            } else {
                $completion_message = "Please choose the <code>{$_POST['new_charset']}</code> collation to be applied to the database.";
            }
            break;

        // -----
        // Request is to start the database's conversion to a new character-set/collation.  Previous processing
        // has ensured that there are no zero-date fields.
        //
        case 'convertdatabase':
            ob_implicit_flush();
            $new_collation = $_POST['collation'];
            $conversion_issues_encountered = false;
            echo "<p>Using a table-prefix of <code>$database_prefix</code>, updating database <code>$database_name</code> to <code>$new_collation</code>.</p>";
            echo "<p>This could take a while, depending on the size of your database ...</p>";
            $cdb->startTimerOne();
            foreach ($tables as $next_table => $table_info) {
                $cdb->startTimerTwo();
                echo "<p>Updating table and field collations for <code>$next_table</code> ...</p>";
                $fields_updated = $cdb->updateTableCollations($next_table, $new_collation);
                $execution_time = $cdb->getTimerTwo();
                if ($fields_updated === false) {
                    $messages[] = $cdb->getErrorMsg();
                    $conversion_issues_encountered = true;
                    break;
                } elseif ($fields_updated === 0) {
                    echo "<code>$next_table</code> required no updates to its collations; execution time: $execution_time.<br>";
                } else {
                    if ($fields_updated < 1000) {
                        echo "$fields_updated fields in <code>$next_table</code> were updated to <code>$new_collation</code>; execution time: $execution_time.<br>";
                    } else {
                        $fields_updated -= 1000;
                        if ($fields_updated === 0) {
                            echo "The collation for <code>$next_table</code> was updated to <code>$new_collation</code>; execution time: $execution_time.<br>";
                        } else {
                            echo "The collation for <code>$next_table</code> and $fields_updated of its fields were updated to <code>$new_collation</code>; execution time: $execution_time.<br>";
                        }
                    }
                }
            }
            if ($conversion_issues_encountered === false) {
                $cdb->updateDatabaseCollation($new_collation);
            }
            $completion_message = 'Database conversion completed; execution time: ' . $cdb->getTimerOne() . '.';
            break;

        default:
            $completion_message = "Invalid action ({$_POST['action']}) received; please review your database tables and try again.";
            break;
    }
?>
    <hr>
    <table id="db-stats">
        <tr>
            <td>MySQL Version:</td>
            <td><?php echo $cdb->getDbMySqlVersion(); ?></td>
        </tr>
        <tr>
            <td>Zen-Cart Database Version:</td>
            <td><?php echo $zc_database_version; ?></td>
        </tr>
        <tr>
            <td>Database Name:</td>
            <td><?php echo $database_name; ?></td>
        </tr>
        <tr>
            <td>Database Prefix:</td>
            <td>'<?php echo $database_prefix; ?>'</td>
        </tr>
    </table>
<?php
    if ($messages !== []) {
        echo '<ul><li>' . implode('</li><li>', $messages) . '</li></ul>';
    }
?>
    <p><strong><?php echo $completion_message; ?></strong></p>
<?php
    // -----
    // If zero-value dates were found in the database, give the user an opportunity to
    // correct them.
    //
    if ($_POST['action'] === 'checkzerodates' && $zero_date_count !== 0) {
        if ($conversion_in_process === true) {
?>
    <p>These values require correction before your database can be converted to <code><?php echo $_POST['collation']; ?></code>.  Click the button below to continue.</p>
<?php
        }
?>
    <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
        <button type="submit" name="action" value="correctzerodatevalues">Correct Zero-Date Values</button>
        <input type="hidden" name="zerodatetables" value="<?php echo implode(',', $zero_date_tables); ?>">
<?php
        if ($conversion_in_process === true) {
?>
        <input type="hidden" name="new_charset" value="<?php echo $_POST['new_charset']; ?>">
        <input type="hidden" name="collation" value="<?php echo $_POST['collation']; ?>">
        <input type="hidden" name="conversion_step" value="2">
<?php
        }
?>
    </form>
<?php
    }

    // -----
    // If this is the result of the to-be-converted character-set choice, display the available collations
    // to be used.
    //
    if ($_POST['action'] === 'choosecharset' && $collation_choices !== false) {
?>
    <p>When you're ready to start the database conversion process, click the button below.  As a first step of the processing, the database will be checked to see that there are no <em>zero-date values</em> present in <code>date</code> or <code>datetime</code> table fields since the presence of those will cause any other table-related modifications to fail!</p>
    <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
        <select name="collation">
<?php
        $selected = ' selected';
        foreach ($collation_choices as $next_collation) {
?>
                                <option value="<?php echo $next_collation; ?>"<?php echo $selected; ?>><?php echo $next_collation; ?></option>
<?php
            $selected = '';
        }
?>
        </select>
        <button type="submit" name="action" value="checkzerodates">Check For Zero-Date Values</button>
        <input type="hidden" name="new_charset" value="<?php echo $_POST['new_charset']; ?>">
        <input type="hidden" name="conversion_step" value="1">
    </form>
<?php
    }

    // -----
    // If we're in the process of a database conversion and either the check for zero-date fields found no
    // issues or the correction of those fields completed successfully, give the go-ahead to start the actual
    // conversion process.
    //
    if ($conversion_in_process === true && (($_POST['action'] === 'checkzerodates' && $zero_date_count === 0) || ($_POST['action'] === 'correctzerodatevalues' && $zero_date_correction_error === false))) {
?>
    <p>The database is free of zero-date values, so the actual conversion process can now begin!  Your database will be updated to use <code><?php echo $_POST['collation']; ?></code> as its collation.  Click the button below to start the conversion.</p>
    <p>Be patient, this could take a while depending on the size of your database.</p>
    <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
        <button type="submit" name="action" value="convertdatabase">Start Conversion</button>
        <input type="hidden" name="new_charset" value="<?php echo $_POST['new_charset']; ?>">
        <input type="hidden" name="collation" value="<?php echo $_POST['collation']; ?>">
        <input type="hidden" name="conversion_step" value="3">
    </form>
<?php
    }
?>
    <p>Click <a href="<?php echo $_SERVER['PHP_SELF']; ?>">here</a> to review the database tables.</p>
<?php
} else {
?>
    <p>Before performing any database actions other than those checking, be sure to make a <b class="text-danger">complete backup</b> of your database.</p>
    <table id="db-stats">
        <tr>
            <td>MySQL Version:</td>
            <td><?php echo $cdb->getDbMySqlVersion(); ?></td>
        </tr>
        <tr>
            <td>Zen-Cart Database Version:</td>
            <td><?php echo $zc_database_version; ?></td>
        </tr>
        <tr>
            <td>Database Name:</td>
            <td><?php echo $database_name; ?></td>
        </tr>
        <tr>
            <td>Database Prefix:</td>
            <td>'<?php echo $database_prefix; ?>'</td>
        </tr>
        <tr>
            <td>Database Character Set:</td>
<?php
    $current_charset = strtolower($cdb->getCurrentDbCharset());
    $db_charset = strtolower(DB_CHARSET);
    if ($current_charset === $db_charset) {
        $class = 'text-success';
        $db_charset_mismatch = false;
    } else {
        $class = 'text-danger';
        $db_charset_mismatch = true;
    }
?>
            <td class="<?php echo $class; ?>"><?php echo $current_charset; ?></td>
        </tr>
        <tr>
            <td>DB_CHARSET:</td>
            <td class="<?php echo $class; ?>"><?php echo DB_CHARSET; ?></td>
        </tr>
        <tr>
            <td>Database Collation:</td>
            <td><?php echo $current_db_collation; ?></td>
        </tr>
        <tr>
            <td>Number of Tables:</td>
            <td><?php echo count(array_keys($tables)); ?></td>
        </tr>
    </table>

    <table id="table-wrapper">
        <caption><sup>1</sup> Identifies a table- or field-related collation issue that requires a database conversion for correction.</caption>
        <tr>
            <td><table id="tables-table">
                <tr>
                    <th class="text-left">Name</th>
                    <th class="text-left">Collation</th>
                    <th class="text-right">Rows</th>
                    <th>Table Issues</th>
                </tr>
<?php
    // -----
    // Determine the character-sets for which any table/field mismatches can be
    // corrected without a full database conversion and create the REGEX pattern
    // used to determine if a table/field collation mismatch is correctable.
    //
    $correctable_charsets = [
        'latin1',
    ];
    if ($current_charset === 'utf8') {
        $correctable_charsets[] = 'utf8';
    }
    if ($current_charset === 'utf8mb4') {
        $correctable_charsets[] = 'utf8mb4';
    }
    if (count($correctable_charsets) === 1) {
        $regex_charset_pattern = $correctable_charsets[0];
    } else {
        $regex_charset_pattern = '(' . implode('|', $correctable_charsets) . ')';
    }
    $regex_charset_pattern = '/^' . $regex_charset_pattern . '_/';

    // -----
    // Loop through each of the database's tables and their associated fields to
    // determine which operations can be presented.
    //
    $correctable_table_collation_mismatches = 0;
    $correctable_field_collation_mismatches = 0;
    $convertable_table_collation_mismatches = 0;
    $convertable_field_collation_mismatches = 0;
    $tables_with_correctable_collation_issues = [];
    $field_date_default_zero = 0;
    $unknown_field_type_count = 0;
    foreach ($tables as $table_name => $table_info) {
?>
                <tr>
                    <td class="text-left">
                        <?php echo $table_name; ?>
<?php
        if ($table_info['info']['Comment'] !== '') {
?>
                        <br><i><?php echo $table_info['info']['Comment']; ?></i>
<?php
        }
?>
                    </td>
<?php
        $issues = [];
        $table_collation = $table_info['info']['Collation'];
        if ($table_collation === $current_db_collation) {
            $class = '';
        } else {
            $table_message = 'Table Collation Mismatch';
            if (preg_match($regex_charset_pattern, $table_collation) !== 1) {
                $table_message .= '<sup>1</sup>';
                $convertable_table_collation_mismatches++;
            } else {
                $correctable_table_collation_mismatches++;
                $tables_with_correctable_collation_issues[] = $table_name;
            }
            $class = ' text-danger';
            $issues[] = $table_message;
        }

        // -----
        // Loop through the fields in the table, checking for their associated collations
        // and, for date/datetime fields, possibly 0-date defaults.
        //
        foreach ($table_info['fields'] as $field_name => $field_info) {
            if ($field_info['Collation'] !== null && $field_info['Collation'] !== $current_db_collation) {
                $field_message = "<code>$field_name</code> Collation Mismatch: <code>{$field_info['Collation']}</code>";
                if (preg_match($regex_charset_pattern, $field_info['Collation']) !== 1) {
                    $field_message .= '<sup>1</sup>';
                    $convertable_field_collation_mismatches++;
                } else {
                    $correctable_field_collation_mismatches++;
                    $tables_with_correctable_collation_issues[] = $table_name;
                }
                $issues[] = $field_message;
            }
            if (($field_info['Type'] === 'datetime' || $field_info['Type'] === 'timestamp') && $field_info['Default'] === "'0000-00-00 00:00:00'") {
                $field_date_default_zero++;
                $issues[] = "<code>$field_name</code> Default 0";
            }
            if ($field_info['Type'] === 'date' && $field_info['Default'] === "'0000-00-00'") {
                $field_date_default_zero++;
                $issues[] = "<code>$field_name</code> Default 0";
            }
            if ($field_info['known_field_type'] === false) {
                $unknown_field_type_count++;
                $issues[] = "<code>$field_name</code>, field-type unknown: <code>{$field_info['Type']}</code>";
            }
        }

        // -----
        // Create the issues' list (if any) to be displayed for the current table.
        //
        if ($issues === []) {
            $issues = 'None';
        } else {
            $issues = '<ul><li>' . implode('</li><li>', $issues) . '</li></ul>';
        }
?>
                    <td class="text-left<?php echo $class; ?>"><?php echo $table_collation; ?></td>
                    <td class="text-right"><?php echo $table_info['info']['Rows']; ?></td>
                    <td class="text-left"><?php echo $issues; ?></td>
                </tr>
<?php
    }
?>
            </table></td>
            <td id="form"><table>
                <tr>
                    <th>Next Action</th>
                </tr>
                <tr>
                    <td>
                        <ul>
<?php
    // -----
    // List out any issues found in the database.
    //
    $database_issues_count = 0;
    if ($db_charset_mismatch === true) {
?>
                            <li>The database's character-set doesn't match <code>DB_CHARSET</code>.</li>
<?php
    }
    if ($correctable_table_collation_mismatches !== 0) {
        $database_issues_count++;
?>
                            <li><?php echo $correctable_table_collation_mismatches; ?> tables don't have the same collation as the base database's. These issues can be corrected without a full database conversion.</li>
<?php
    }
    if ($correctable_field_collation_mismatches !== 0) {
        $database_issues_count++;
?>
                            <li><?php echo $correctable_field_collation_mismatches; ?> fields don't have the same collation as the base database's. These issues can be corrected without a full database conversion.</li>
<?php
    }
    if ($convertable_table_collation_mismatches !== 0) {
        $database_issues_count++;
?>
                            <li><?php echo $convertable_table_collation_mismatches; ?> tables don't have the same collation as the base database's<sup>1</sup>.</li>
<?php
    }
    if ($convertable_field_collation_mismatches !== 0) {
        $database_issues_count++;
?>
                            <li><?php echo $convertable_field_collation_mismatches; ?> fields don't have the same collation as the base database's<sup>1</sup>.</li>
<?php
    }
    if ($field_date_default_zero !== 0) {
        $database_issues_count++;
?>
                            <li><?php echo $field_date_default_zero; ?> <code>date/datetime/timestamp</code> fields have a zero-date default.  This must be corrected before any character-set and/or collation modifications are made to the database.</li>
<?php
    }
    if ($unknown_field_type_count !== 0) {
        $database_issues_count++;
?>
                            <li><?php echo $unknown_field_type_count; ?> fields have an unknown character-based <code>field-type</code>. This prevents any character-set and/or collation modifications to be made to the database.</li>
<?php
    }
     if ($database_issues_count === 0) {
?>
                            <li>Congratulations, no database issues were found.</li>
<?php
    }
?>
                        </ul>
<?php
    // -----
    // So long as there aren't any date/datetime fields with a 'zero-date' default and no
    // unknown field types, character-set and collation changes to the database can be performed
    // without those causing issues.
    //
    if ($field_date_default_zero === 0 && $unknown_field_type_count === 0) {
        // -----
        // Determine whether 'utf8mb4' should be offered as a potential update.  If the database
        // hasn't been run through the zc156 (or later) zc_install process, the various character-field
        // lengths will not have been adjusted to accomodate that charset value.
        //
        $offer_utf8mb4 = false;
        if (preg_match('~^(\d+\.\d+\.\d+)~', $zc_database_version, $matches) === 1) {
            $zc_database_version = $matches[1];
            $offer_utf8mb4 = version_compare($zc_database_version, '1.5.6', '>=');
        }

        // -----
        // See if the database is a candidate for an updated collation/charset.
        //
        $charset_options = [];
        if ($current_charset === 'latin1') {
            $charset_options[] = 'utf8';
        }
        if ($offer_utf8mb4 === true) {
            $charset_options[] = 'utf8mb4';
        }
        if ($charset_options === []) {
            if ($current_charset === 'utf8mb4') {
?>
                        <p>Your database is already using the <code>utf8mb4</code> character-set.</p>
<?php
            } else {
?>
                        <p>You need to upgrade your site to Zen-Cart v1.5.6 or later to update your database to use the <code>utf8mb4</code> character-set.</p>
<?php
            }
        } else {
?>
                        <p>To convert the database's character-set and/or associated collation, start by selecting a character-set from the list below:</p>
                        <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" id="choose-charset">
                            <select name="new_charset">
<?php
            $selected = ' selected';
            foreach ($charset_options as $next_charset) {
?>
                                <option value="<?php echo $next_charset; ?>"<?php echo $selected; ?>><?php echo $next_charset; ?></option>
<?php
                $selected = '';
            }
?>
                            </select>
                            <button type="submit" name="action" value="choosecharset">Choose</button>
                        </form>
<?php
        }
        if ($tables_with_correctable_collation_issues !== []) {
            $tables_with_correctable_collation_issues = array_unique($tables_with_correctable_collation_issues);
?>
                        <p><?php echo count($tables_with_correctable_collation_issues); ?> tables (or their fields) don't have the same collation as the base database's.  These issues can be corrected without a full conversion of the database's character-set.</p>
                        <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" id="fixcollations">
                            <button type="submit" name="action" value="fixcollations">Correct Table/Field Collations</button>
                            <input type="hidden" name="tablenames" value="<?php echo implode(',', $tables_with_correctable_collation_issues); ?>">
                        </form>
<?php
        }
    }
?>
                        <hr>
                        <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" id="zero-date-form">
                            <button type="submit" name="action" value="checkzerodates">Check for Zero-Value Dates</button>
<?php
    if ($field_date_default_zero !== 0) {
?>
                            <button type="submit" name="action" value="fixdatedefaults">Correct Zero-Date Defaults</button>
<?php
    }
?>
                        </form>
                    </td>
                </tr>
            </table></td>
        </tr>
    </table>
<?php
    if (isset($_GET['debug'])) {
        echo '<pre>';
        var_dump($_POST);
        var_dump($tables);
        echo '</pre>';
    }
?>
    </div>
<?php
}
?>
</body>
</html>