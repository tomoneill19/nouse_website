<?php
require_once __DIR__ . '/common/headSecure.php';

$PAGEDATA['pageConfig'] = ["TITLE" => "Articles", "BREADCRUMB" => false];

if (!$AUTH->permissionCheck(30)) die("Sorry - you can't access this page");

if (isset($_GET['q'])) $PAGEDATA['search'] = $bCMS->sanitizeString($_GET['q']);
else $PAGEDATA['search'] = null;

if (isset($_GET['page'])) $page = $bCMS->sanitizeString($_GET['page']);
else $page = 1;
$DBLIB->pageLimit = 20;
if (strlen($PAGEDATA['search']) > 0) {
	//Search
	$DBLIB->where("
		(articlesDrafts.articlesDrafts_headline LIKE '%" . $bCMS->sanitizeString($PAGEDATA['search']) . "%'
		OR articles.articles_slug LIKE '%" . $bCMS->sanitizeString($PAGEDATA['search']) . "%'
		OR articlesDrafts.articlesDrafts_excerpt LIKE '%" . $bCMS->sanitizeString($PAGEDATA['search']) . "%')
    ");
}
$DBLIB->orderBy("articles_published", "DESC");
$DBLIB->where("articles_showInAdmin", 1); //ie those that can actually be shown
if (isset($_GET['a'])) {
	$DBLIB->where("FIND_IN_SET('" . $bCMS->sanitizeString($_GET['a']) . "',articles_authors)");
	$PAGEDATA['pageConfig']['author'] = true;
}
$DBLIB->join("articlesDrafts", "articles.articles_id=articlesDrafts.articles_id", "LEFT");
$DBLIB->join("editions", "articles.editions_id=editions.editions_id", "LEFT");
$DBLIB->where("articlesDrafts.articlesDrafts_id = (SELECT articlesDrafts_id FROM articlesDrafts WHERE articlesDrafts.articles_id=articles.articles_id ORDER BY articlesDrafts_timestamp DESC LIMIT 1)");
$PAGEDATA['articles'] = [];
$articles = $DBLIB->arraybuilder()->paginate("articles", $page, ["articles.articles_categories","articles.articles_slug", "articles.articles_authors", "articles.articles_published", "articles.articles_updated", "articles.articles_showInSearch", "articles.articles_showInLists","articles.articles_id","articles.articles_published", "articlesDrafts.articlesDrafts_headline","editions.editions_id", "editions.editions_printNumber"]);
$PAGEDATA['pagination'] = ["page" => $page, "total" => $DBLIB->totalPages];
foreach ($articles as $article) {
	if ($article['articles_authors'] != null) {
		$authors = explode(",",$article['articles_authors']);
		$article['articles_authors'] = [];
		foreach ($authors as $author) {
			if (strlen($author) < 1) continue;
			$DBLIB->where("users_userid", $author);
			$DBLIB->where("users_deleted", 0);
			$article['articles_authors'][] = $DBLIB->getone("users", ["users.users_name1", "users.users_name2", "users.users_userid"]);
		}
	} else $article['articles_authors'] = false;

	$article['articles_categories'] = explode(",", $article['articles_categories']);
	$PAGEDATA['articles'][] = $article;
}




$DBLIB->orderBy("categories_nestUnder", "ASC");
$DBLIB->orderBy("categories_order", "ASC");
$DBLIB->orderBy("categories_displayName", "ASC");
$PAGEDATA['CATEGORIES'] = $DBLIB->get("categories",null, ["categories_id","categories_displayName","categories_backgroundColor","categories_backgroundColorContrast"]);


echo $TWIG->render('articles.twig', $PAGEDATA);
?>
