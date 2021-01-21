<?php
require_once __DIR__ . '/common/headSecure.php';

$PAGEDATA['pageConfig'] = ["TITLE" => "Home", "BREADCRUMB" => false];

$PAGEDATA['CHANGELOG'] = [];
exec("cd " . __DIR__ . "/../../ && git log -10", $PAGEDATA['CHANGELOG']);

$PAGEDATA['MOSTACTIVEUSERS'] = [];

$DBLIB->where("(auditLog.users_userid IS NOT NULL)");
$DBLIB->where("(auditLog.auditLog_timestamp >= curdate() - INTERVAL DAYOFWEEK(curdate())+6 DAY)");
$DBLIB->join("users","auditLog.users_userid = users.users_userid","LEFT");
$DBLIB->groupBy ("auditLog.users_userid");
$DBLIB->orderBy ("counter","DESC");
$PAGEDATA['MOSTACTIVEUSERS']['WEEK'] = $DBLIB->get("auditLog",5,["users.users_name1", "users.users_name2", "auditLog.users_userid", "COUNT(*) AS counter"]);

echo $TWIG->render('index.twig', $PAGEDATA);
?>
