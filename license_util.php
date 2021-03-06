<?php
require_once __DIR__ . "/tools.php";
require_once __DIR__ . "/common.php";

db_connect($db);
$servers = db_get_servers($db, array('name'));
update_servers($db, $servers);
update_licenses($db, $servers);
$db->close();
exit;

/**
 * Update statuses of all active servers in DB
 *
 * All entries in $servers will be updated in DB.  Afterwards any servers that
 * are not up get culled from the list as their license info is inaccessible.
 *
 * @param &$db DB connection object.
 * @param &$servers active servers list array.
 */
function update_servers(&$db, &$servers) {
    global $lmutil_binary;

    $update_data = array();
    foreach ($servers as $index => $server) {
        // Retrieve server details via lmstat.
        $fp=popen("{$lmutil_binary} lmstat -c {$server['name']}", "r");

        $stdout = "";
        while (!feof($fp)) {
            $stdout .= fgets($fp);
        }
        pclose($fp);

        // DB update data as parralel arrays, using server's $index
        $status[$index] = SERVER_DOWN; // assume server is down unless determined otherwise.
        $lmgrd_version[$index] = "";
        $name[$index] = $server['name'];

        // If server is up, also read its lmgrd version.
        if (preg_match("/license server UP (?:\(MASTER\) )?(v\d+[\d\.]*)$/im", $stdout, $matches)) {
            $status[$index] = SERVER_UP;
            $lmgrd_version[$index] = $matches[1];
        }

        // This can override $status[$index] is UP.
        if (preg_match("/vendor daemon is down/im", $stdout)) {
            $status[$index] = SERVER_VENDOR_DOWN;
        }

        // Servers that are not SERVER_UP are discarded so they are not checked for license info, later.
        if ($status[$index] !== SERVER_UP) {
            unset ($servers[$index]);
        }
    }

    // Server statuses DB update
    $db->query("LOCK TABLES `servers` WRITE;");
    $query = $db->prepare("UPDATE `servers` SET `status`=?, `lmgrd_version`=?, `last_updated`=NOW() WHERE `name`=?;");
    foreach($name as $index => $_n) {
        $query->bind_param("sss", $status[$index], $lmgrd_version[$index], $name[$index]);
        $query->execute();
    }

    $query->close();
    $db->query("UNLOCK TABLES;");
} // END function update_servers()

/**
 * Get license use info from all servers that are up.
 *
 * @param &$db DB connection object.
 * @param $servers active/up server list array.
 */
function update_licenses(&$db, $servers) {
    global $lmutil_binary;

    $db->query("LOCK TABLES `features`, `licenses`, `usage` WRITE");
    foreach ($servers as $server) {
        $fp=popen("{$lmutil_binary} lmstat -a -c {$server['name']}", "r");

        $licenses = array();
        while ( !feof ($fp) ) {
            $stdout = fgets ($fp, 1024);

        	// Look for features and licenses used in stdout.  Example of stdout:
        	// "Users of Allegro_Viewer: (Total of 5 licenses issued;  Total of 1 license in use)\n"
            // Features is "Allegro_Viewer" and licenses used is 1.
            if (preg_match("/^Users of (?<feature>.*):  \(Total of \d* license[s]? issued;  Total of (?<licenses_used>\d*) license[s]? in use\)$/", $stdout, $matches)) {
                $licenses[] = array(
                    'feature' => $matches['feature'],
                    'licenses_used' => $matches['licenses_used']
                );
            }
        }

        pclose($fp);

        // INSERT licence data to DB
        foreach ($licenses as $license) {
            $sql = array();
            $data = array();
            $queries = array();

            // Populate feature, if needed.
            // ? = $license['feature']
            $data[0] = array($license['feature']);
            $type[0] = "s"; // needed for mysqli_stmt::bind_param()
            $sql[0] = <<<SQL
INSERT IGNORE INTO `features` (`name`, `show_in_lists`, `is_tracked`)
    VALUES (?, 1, 1);
SQL;

            // Populate server/feature to licenses, if needed.
            // ?, ? = $server['name'], $license['feature']
            $data[1] = array($server['name'], $license['feature']);
            $type[1] = "ss"; // needed for mysqli_stmt::bind_param()
            $sql[1] = <<<SQL
INSERT IGNORE INTO `licenses` (`server_id`, `feature_id`)
    SELECT DISTINCT `servers`.`id` AS `server_id`, `features`.`id` AS `feature_id`
    FROM `servers`, `features`
    WHERE `servers`.`name` = ? AND `features`.`name` = ?;
SQL;

            // Insert license usage.  Needs feature and license populated, first.
            // ?, ?, ? = $license['licenses_used'], $server['name'], $license['feature']
            $data[2] = array($license['licenses_used'], $server['name'], $license['feature']);
            $type[2] = "sss"; // needed for mysqli_stmt::bind_param()
            $sql[2] = <<<SQL
INSERT IGNORE INTO `usage` (`license_id`, `time`, `num_users`)
    SELECT `licenses`.`id`, NOW(), ?
    FROM `licenses`
    JOIN `servers` ON `licenses`.`server_id`=`servers`.`id`
    JOIN `features` ON `licenses`.`feature_id`=`features`.`id`
    WHERE `servers`.`name`=? AND `features`.`name`=? AND `features`.`is_tracked`=1;
SQL;

            // Prepare each query and bind parameter data
            foreach($sql as $i => $query) {
                $queries[$i] = $db->prepare($query);
                // q.v. https://www.php.net/functions.arguments#functions.variable-arg-list for '...' token
                $queries[$i]->bind_param($type[$i], ...$data[$i]);
            }

            // Attempt to INSERT license usage...
            $queries[2]->execute();

            // when affectedRows < 1, feature and license first needs to be
            // populated and then license usage query re-run.
            if ($db->affected_rows < 1) {
                foreach ($queries as $query) {
                    $query->execute();
                }
            }

            // Release each prepared query.
            foreach ($queries as &$query) {
                $query->close();
            }
            unset($query);
        } // END foreach($licenses as $license)
    } // END foreach($servers as $server)

    $db->query("UNLOCK TABLES;");
} // END function update_licenses()

?>
