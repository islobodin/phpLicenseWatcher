<?php
require_once __DIR__ . "/common.php";

db_connect($db);
$servers = db_get_servers($db, array('name'));

foreach ($servers as $server) {
    $fp = popen($lmutil_binary . " lmstat -a -c " . $server['name'], "r");
    while ( !feof ($fp) ) {
        $line = fgets ($fp, 1024);

        // Look for features in the output. You will see stuff like
        // Users of Allegro_Viewer: (Total of 5 licenses available
        if ( preg_match("/^Users of (.*)Total /i", $line ) )  {
            $out = explode(" ", $line);
            // Remove the : in the end of the string
            $feature = str_replace(":", "", $out[2]);

            $sql = <<<SQL
INSERT IGNORE INTO `available` (`license_id`, `date`, `num_licenses`)
    SELECT `licenses`.`id`, NOW(), {$out[6]}
    FROM `licenses`
    JOIN `servers` ON `licenses`.`server_id`=`servers`.`id`
    JOIN `features` ON `licenses`.`feature_id`=`features`.`id`
    WHERE `servers`.`name`='{$server["name"]}' AND `features`.`name`='{$feature}';
SQL;

            $result = $db->query($sql);
            if (!$result) {
                die ($db->error);
            }
        }
    }
    pclose($fp);
}

$db->close();

?>
