<?php
require_once __DIR__ . '/../common/head.php';

$PAGEDATA['pageConfig'] = ["TITLE" => "Editions", "ADS" => false];

$DBLIB->orderBy("editions_published", "DESC");
$DBLIB->where("editions_deleted", 0); //ie those that can actually be shown
$DBLIB->where("(editions_thumbnail IS NOT NULL)");
$DBLIB->where("editions.editions_show",1);
$DBLIB->where("editions.editions_published <= '" . date("Y-m-d H:i:s") . "'");
$PAGEDATA['editions'] = $DBLIB->get("editions", null, ["editions_slug", "editions_published","editions_printNumber","editions_thumbnail","editions_name","editions_excerpt","editions_type"]);

echo $TWIG->render('editions.twig', $PAGEDATA);
?>
