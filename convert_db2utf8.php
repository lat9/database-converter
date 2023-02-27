<?php
/**
 * convert_db2utf8
 *
 * @package eCommerce-Service
 * @copyright Copyright 2004-2007, Andrew Berezin eCommerce-Service.com
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: convert_db2utf8.php, v 2.0.1 16.10.2007 14:47 Andrew Berezin $
 *
 * Updated 2014-06-28 to change mysql_ functions to mysqli_ functions, for compatibility with PHP >= 5.4
 * Updated 2015-01-11 (lat9).  Check for, and convert, overall database collation, too!
 * Updated 2018-01-01 (mc12345678).  Support both quoted and unquoted databases when converting the overall database collation.
 *
 */
error_reporting(E_ALL);
$desiredCollation = 'utf8_general_ci'; // could optionally use utf8_unicode_ci

/**
 * Get the database credentials
 */
if (file_exists('includes/local/configure.php')) {
    /**
     * load any local(user created) configure file.
     */
    require 'includes/local/configure.php';
}
if (file_exists('includes/configure.php')) {
    /**
     * load the main configure file.
     */
    require 'includes/configure.php';
}
if (file_exists('configure.php')) {
    require 'configure.php';
}

if (!defined('DB_SERVER_USERNAME')) {
    die("ERROR: configure.php file not found, or doesn't contain database user credentials.");
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>UTF-8 Database Converter</title>
    <style>
    table {
        width: 100%;
        margin-bottom: 1rem;
    }
    table, table th, table td {
        border: 1px solid black;
        border-collapse: collapse;
    }
    th, td {
        padding: 0.1625rem;
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
    .color-danger {
        font-weight: bold;
        color: red;
    }
    .color-success {
        font-weight: bold;
        color: green;
    }
    .db-stat {
        margin-left: 2rem;
    }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>UTF-8 Database Converter</h1>
<?php
if (isset($_POST['db_prefix'])) {
    $db_prefix = $_POST['db_prefix'];
} else {
    $db_prefix = DB_PREFIX;
}
if (isset($_POST['submit']) ) {
    $tables = UTF8_DB_Converter(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, $db_prefix, false);
?>
    <p><strong>The database has been converted to UTF-8</strong>. <a href="<?php echo HTTP_SERVER; ?>" target="_blank">View site &raquo;</a></p>
<?php
} else {
?>
    <p>Before proceeding with the final step please make a complete backup of your database.<br>
    <fieldset>
        <legend><?php echo ''; ?></legend>
        <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" id="utf8-db-converter">
            <label for="db-prefix"><?php echo 'DB Prefix'; ?></label>
            <input type="text" name="db_prefix" value="<?php echo $db_prefix; ?>" id="db-prefix">
            <br>
<?php
    $tables = UTF8_DB_Converter(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, $db_prefix, true);
?>
            <input type="submit" name="review" value="Review Tables">
<?php
    if ($tables > 0) {
?>
            <input type="submit" name="submit" value="Start converting &raquo;">
<?php
    }
?>
        </form>
    </fieldset>
<?php
}
?>
    </div>
</body>
</html>
<?php
function UTF8_DB_Converter($dbServer, $dbUser, $dbPassword, $dbDatabase, $dbPrefix = '', $showOnly = true)
{
    global $dbStat, $link_id, $desiredCollation;
    $time_start = microtime_float();
    set_time_limit(0);
    $dbStat = [];

    echo 'Connecting to DB server ' . $dbServer . '<br>' . "\n";
    if (($link_id = mysqli_connect($dbServer, $dbUser, $dbPassword)) === false) {
        echo 'Error connecting to MySQL: ' . mysqli_connect_errno() . ': ' . mysql_connect_error() . '<br>' . "\n";
        die();
    }
    $mySQLversion = mysqli_get_server_info($link_id);
    echo 'MySQL Server version ' . $mySQLversion . '<br>' . "\n";

    if (version_compare($mySQLversion, '4.1.1', '<=') === true) {
        mysqli_close($link_id);
        die('This MySQL version not supported!!!');
    }

    echo 'Selected DB: ' . $dbDatabase . '<br>' . "\n";
    if (mysqli_select_db($link_id, $dbDatabase) === false) {
        mysqli_close($link_id);
        echo 'Error selecting database: ' . mysqli_errno($link_id) . ': ' . mysqli_error($link_id) . '<br>' . "\n";
        die();
    }
  
    //-bof-20160111-lat9-Show/update overall database collation, too.
    $query = db_query("SHOW VARIABLES LIKE \"character\_set\_database\"");
    $database_charset = mysqli_fetch_assoc($query);
    $charset_value = $database_charset['Value'];
    echo 'Current DB character-set: ' . $charset_value;
    if ($charset_value !== 'utf8') {
        if ($showOnly === true) {
            echo ' <span class="color-danger">&lt;== Will be converted, too, when you convert the tables!</span>';
        } else {
            db_query("ALTER DATABASE `" . $dbDatabase . "` CHARACTER SET utf8 COLLATE $desiredCollation");
            echo ' <span class="color-success">&lt;== Converted!</span>';
        }
    }
    echo '<br>' . "\n";
    //-eof-2016011-lat9-Show overall database collation

    $query = db_query("SHOW CHARACTER SET LIKE 'utf8'");
    if (!$charset = mysqli_fetch_assoc($query)) {
        mysqli_close($link_id);
        die("Charset 'utf8' not found!!!");
    }

    echo 'DB Prefix: "' . $dbPrefix . '"' . '<br>' . "\n";

    $query_tables = db_query("SHOW TABLE STATUS FROM `$dbDatabase`");
?>
    <table>
        <tr>
            <th class="text-left">Name</th>
            <th class="text-left">Collation</th>
            <th class="text-right">Rows</th>
            <th class="text-right">Data Length</th>
            <th class="text-center">Create Time</th>
            <th class="text-center">Update Time</th>
            <th>Action</th>
        </tr>
<?php
    $totalProcessingTables = $totalConvertedTables = 0;
    while (($table = mysqli_fetch_assoc($query_tables)) !== null) {
        if (!preg_match('@^' . $dbPrefix . '@', $table['Name'])) {
            continue;
        }
        $totalProcessingTables++;
?>
        <tr>
            <td class="text-left">
                <strong><?php echo $table['Name']; ?></strong>
<?php
        if ($table['Comment'] !== '') {
?>
                <br><i><?php echo $table['Comment']; ?></i>
<?php
        }
?>
            </td>
            <td class="text-left"><?php echo $table['Collation']; ?></td>
            <td class="text-right"><?php echo $table['Rows']; ?></td>
            <td class="text-right"><?php echo $table['Data_length']; ?></td>
            <td class="text-center"><?php echo $table['Create_time']; ?></td>
            <td class="text-center"><?php echo $table['Update_time']; ?></td>
            <td class="text-center">
<?php
        if ($table['Collation'] === $desiredCollation) {
            $action = 'Skip';
        } else {
            $totalConvertedTables++;
            if ($showOnly === true) {
                $action = 'Convert';
            } else {
                db_query("ALTER TABLE `" . $table['Name'] . "` CONVERT TO CHARACTER SET utf8 COLLATE " . $desiredCollation);
                db_query("ALTER TABLE `" . $table['Name'] . "` DEFAULT CHARACTER SET utf8 COLLATE " . $desiredCollation);
                db_query("OPTIMIZE TABLE `" . $table['Name'] . "`");
                $action = '<span class="text-success">Converted!</span>';
            }
        }
        echo $action;
?>
            </td>
        </tr>
<?php
    }
?>
    </table>
<?php
    if ($showOnly === false) {
        db_query("ALTER DATABASE `" . $dbDatabase . "` CHARACTER SET utf8 COLLATE " . $desiredCollation);
    }

    mysqli_close($link_id);

    $total_time = microtime_float() - $time_start;

    if ($showOnly === false) {
        echo 'Total processing tables ' . $totalProcessingTables . ', converted tables ' . $totalConvertedTables . '. Execution time ' . timefmt($total_time) . '<br>' . "\n";
    } else {
        echo 'Total processing tables ' . $totalProcessingTables . ', tables to be converted ' . $totalConvertedTables . '. Execution time ' . timefmt($total_time) . '<br>' . "\n";
    }

    echo 'DB statistic:<br>';
    foreach ($dbStat as $sql_command => $time) {
?>
    <span class="db-stat"><?php echo $sql_command . ': ' . count($time) . ' ' . timefmt(array_sum($time)); ?></span>
<?php
    }
    echo '<br>' . "\n";

    return $totalConvertedTables;
}

function db_query($sql)
{
    global $link_id, $dbStat;

    $st = microtime_float();

    $ret = mysqli_query($link_id, $sql);
    if ($ret === false) {
        echo 'Error: ' . mysqli_errno($link_id) . ': ' . mysqli_error($link_id) . '<br>' . "\n";
        echo 'SQL: ' . $sql . '<br>' . "\n";
    }

    $sql_command = explode(' ', substr($sql, 0, 16));
    $sql_command = strtoupper($sql_command[0]);
    $dbStat[$sql_command][] = microtime_float() - $st;

    return $ret;
}

function microtime_float()
{
    list($usec, $sec) = explode(' ', microtime());
    return ((float)$usec + (float)$sec);
}

function timefmt($s)
{
    $m = floor($s / 60);
    $s = $s - $m * 60;
    $h = floor($m / 60);
    $m = $m - $h * 60;
    if ($h > 0) {
        $tfmt = $h . ':' . $m . ':' . number_format($s, 4);
    } elseif ($m > 0) {
        $tfmt = $m . ':' . number_format($s, 4);
    } else {
        $tfmt = number_format($s, 4);
    }
    return $tfmt;
}
