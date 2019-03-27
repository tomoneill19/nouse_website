<?php
require_once __DIR__ . '/config.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

use voku\helper\HtmlDomParser;

use ChapterThree\AppleNewsAPI;
use ChapterThree\AppleNewsAPI\Document;
use ChapterThree\AppleNewsAPI\Document\Components\Body;
use ChapterThree\AppleNewsAPI\Document\Layouts\Layout;
use ChapterThree\AppleNewsAPI\Document\Styles\ComponentTextStyle;

//GLOBALS STUFF - DON'T CHANGE
function errorHandler()
{
    if (error_get_last() and error_get_last()['type'] == '1') {
        global $CONFIG;
        die('Sorry we hit an error. Our tech team have been automatically notified but please contact support@nouse.co.uk for help resolving this error for your device <p style="display:none;">' . "\n\n\n" . error_get_last()['message'] . "\n\n\n" . '</p>');
    }
}

//set_error_handler('errorHandler');
$CONFIG['ERRORS']['SENTRY-CLIENT']['MAIN'] = new Raven_Client($CONFIG['ERRORS']['SENTRY']);
$CONFIG['ERRORS']['SENTRY-CLIENT']['MAIN']->setRelease($CONFIG['VERSION']['TAG'] . "." . $CONFIG['VERSION']['COMMIT']);
$CONFIG['ERRORS']['SENTRY-CLIENT']['HANDLER'] = new Raven_ErrorHandler($CONFIG['ERRORS']['SENTRY-CLIENT']['MAIN']);
$CONFIG['ERRORS']['SENTRY-CLIENT']['HANDLER']->registerExceptionHandler();
$CONFIG['ERRORS']['SENTRY-CLIENT']['HANDLER']->registerErrorHandler();
$CONFIG['ERRORS']['SENTRY-CLIENT']['HANDLER']->registerShutdownFunction();
register_shutdown_function('errorHandler');

//Content security policy - BACKEND HAS A DIFFERENT ONE SO LOOK OUT FOR THAT
header("Content-Security-Policy: default-src 'none';" .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://*.pubnub.com https://cdnjs.cloudflare.com https://platform.twitter.com https://www.googletagmanager.com https://www.google-analytics.com https://cdn.syndication.twimg.com https://connect.facebook.net https://*.google.com https://*.google.co.uk https://www.gstatic.com https://*.googlesyndication.com https://www.googletagservices.com  http://*.liveblogpro.com https://*.liveblogpro.com https://platform.vine.co;".
    //          We have laods of inline JS              Live sockets         Libs                        Twitter embedd              Google webmaster tools                    Google analytics                  Twitter pictures for embedd   Facebook share           Recapatcha +adsense          Google adsense                                                     Google analytics etc                        Liveblog pro                                Liveblog pro
    "style-src 'unsafe-inline' 'self' https://*.twimg.com https://platform.twitter.com https://cdnjs.cloudflare.com https://fonts.googleapis.com;".
    //          We have loads of inline CSS  Twitter pics                             Live chat supports             Libs                        GFonts
    "font-src https://fonts.googleapis.com https://fonts.gstatic.com https://cdnjs.cloudflare.com;" .
    //          Loading in google fonts     more gfonts                 Fonts from libs like fontawesome
    "manifest-src 'self' https://*.digitaloceanspaces.com;" .
    //          Show images on mobile devices like favicons
    "img-src 'self' data: blob: https://cdnjs.cloudflare.com https://*.digitaloceanspaces.com https://*.twitter.com https://*.twimg.com https://www.google-analytics.com https://*.googlesyndication.com https://www.googletagmanager.com https://i2.wp.com             http://*.liveblogpro.com https://*.liveblogpro.com;".
    //                    Uploads    Images from libs                 Images                             Twitter embedd      More twitter          Google analytics                                                                          User icons fallback            Liveblog pro
    "connect-src 'self' https://*.digitaloceanspaces.com https://*.pndsn.com https://sentry.io https://www.google-analytics.com https://*.gstatic.com;".
    //                  File uploads                    Pubnub sockets          Error reporting     Google analytics
    "frame-src https://*.twitter.com https://staticxx.facebook.com https://www.google.com https://googleads.g.doubleclick.net https://e.issuu.com http://*.liveblogpro.com https://*.liveblogpro.com https://twitter.com;".
    //          Embedding twitter feed   Facebook feed              embedded maps               Google adsense                  Embedd editions         LiveBlog pro                                    Live blog pro
    "object-src 'self' blob:;".
    //          Inline PDFs generated by the system
    "worker-src 'self' blob:;" .
    //          Use of camera
    "frame-ancestors 'self';" .
    "report-uri https://bithell.report-uri.com/r/d/csp/enforce"); //Send to report-uri


/* DATBASE CONNECTIONS */
$CONN = new mysqli($CONFIG['DB_HOSTNAME'], $CONFIG['DB_USERNAME'], $CONFIG['DB_PASSWORD'], $CONFIG['DB_DATABASE']);
if ($CONN->connect_error) throw new Exception($CONN->connect_error);
$DBLIB = new MysqliDb ($CONN); //Re-use it in the wierd lib we love


/* FUNCTIONS */
class bCMS {

    private $cloudflare;

    function sanitizeString($var) {
        //Setup Sanitize String Function
        $var = strip_tags($var);
        //$var = htmlentities($var);
        //$var = stripslashes($var);
        //global $CONN;
        //return mysqli_real_escape_string($CONN, $var);
        return $var;
    }
    function randomString($length = 10, $stringonly = false) { //Generate a random string
        $characters = 'abcdefghkmnopqrstuvwxyzABCDEFGHKMNOPQRSTUVWXYZ';
        if (!$stringonly) $characters .= '0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    function cleanString($var)
    {
        //HTML Purification
        //$var = str_replace(array("\r", "\n"), '<br>', $var); //Replace newlines
        /*
                $config = HTMLPurifier_Config::createDefault();
                $config->set('Cache.DefinitionImpl', null);
                //$config->set('AutoFormat.Linkify', true);
                $purifier = new HTMLPurifier($config);
                $clean_html = $purifier->purify($var);
            return $clean_html; //NOTE THAT THIS REQUIRES THE USE OF PREPARED STATEMENTS AS IT'S NOT ESCAPED
        */
        return $var;

    }

    function formatSize($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 1) . ' GB';
        } elseif ($bytes >= 100000) {
            $bytes = number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 0) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }
        return $bytes;
    }

    function modifyGet($array)
    {
        //Used to setup links that don't affect search terms etc.
        foreach ($array as $key => $value) {
            $_GET[$key] = $value;
        }
        return $_GET;
    }

    public function articleThumbnail($article)
    {
        global $DBLIB, $CONFIG;
        if ($article == null) return false;
        $DBLIB->where("articles_id", $this->sanitizeString($article));
        $thumb = $DBLIB->getone("articles", ["articles_thumbnail"]);
        if (!$thumb or $thumb["articles_thumbnail"] == null) return false;
        if (is_numeric($thumb["articles_thumbnail"])) return $this->s3URL($thumb["articles_thumbnail"], "large");
        else return $CONFIG['FILESTOREURL'] . "/archive/public/articleImages/" . rawurlencode($thumb["articles_thumbnail"]);
    }

    function s3URL($fileid, $size = false, $forceDownload = false, $expire = '+1 minute', $returnArray = false) {
        global $DBLIB, $CONFIG;
        /*
         * File interface for Amazon AWS S3.
         *  Parameters
         *      f (required) - the file id as specified in the database
         *      s (filesize) - false to get the original - available is "tiny" (100px) "small" (500px) "medium" (800px) "large" (1500px)
         *      d (optional, default false) - should a download be forced or should it be displayed in the browser? (if set it will download)
         *      e (optional, default 1 minute) - when should the link expire? Must be a string describing how long in words basically. If this file type has security features then it will default to 1 minute.
         */
        $fileid = $this->sanitizeString($fileid);
        if (strlen($fileid) < 1) return false;
        $DBLIB->where("s3files_id", $fileid);
        $DBLIB->where("s3files_meta_deleteOn IS NULL"); //If the file is to be deleted soon or has been deleted don't let them download it
        $DBLIB->where("s3files_meta_physicallyStored", 1); //If we've lost the file or deleted it we can't actually let them download it
        $file = $DBLIB->getone("s3files");
        if (!$file) return false;
        if ($file['s3files_meta_public'] == 1) {
            $returnFilePath = $file['s3files_cdn_endpoint'] . "/" . $file['s3files_path'] . "/" . rawurlencode($file['s3files_filename']);
            switch ($size) {
                case "tiny":
                    $returnFilePath .= '%20(tiny)';
                    break; //The want the original
                case "small":
                    $returnFilePath .= '%20(small)';
                    break; //The want the original
                case "medium":
                    $returnFilePath .= '%20(medium)';
                    break; //The want the original
                case "large":
                    $returnFilePath .= '%20(large)';
                    break; //The want the original
                default:
                    //They want the original
            }
            $presignedUrl = $returnFilePath . "." . rawurlencode($file['s3files_extension']);
        } else {
            $s3Client = new Aws\S3\S3Client([
                'region' => $file["s3files_region"],
                'endpoint' => "https://" . $file["s3files_endpoint"],
                'version' => 'latest',
                'credentials' => array(
                    'key' => $CONFIG['AWS']['KEY'],
                    'secret' => $CONFIG['AWS']['SECRET'],
                )
            ]);

            $file['expiry'] = $expire;


            switch ($file['s3files_meta_type']) {
                case 1:
                    //This is a user thumbnail
                    break;
                default:
                    //There are no specific requirements for this file so not to worry.
            }

            $parameters = [
                'Bucket' => $file['s3files_bucket'],
                'Key' => $file['s3files_path'] . "/" . $file['s3files_filename'] . '.' . $file['s3files_extension'],
            ];
            if ($forceDownload) $parameters['ResponseContentDisposition'] = 'attachment; filename="' . $CONFIG['PROJECT_NAME'] . ' ' . $file['s3files_filename'] . '.' . $file['s3files_extension'] . '"';
            $cmd = $s3Client->getCommand('GetObject', $parameters);
            $request = $s3Client->createPresignedRequest($cmd, $file['expiry']);
            $presignedUrl = (string)$request->getUri();

            $presignedUrl = $file['s3files_cdn_endpoint'] . explode($file["s3files_endpoint"],$presignedUrl)[1]; //Remove the endpoint itself from the url in order to set a new one
        }
        if ($returnArray) return ["url" => $presignedUrl, "data" => $file];
        else return $presignedUrl;
    }

    public function cacheClearCategory($categoryid)
    {
        global $DBLIB, $CONFIG;
        if (!$categoryid) return false;

        $DBLIB->where("categories_id", $this->sanitizeString($categoryid));
        $category = $DBLIB->getOne("categories", ["categories_name", "categories_nestUnder"]);
        if (!$category) return false;
        $url = $CONFIG['ROOTFRONTENDURL'] . '/' . $category['categories_name'];
        if ($category['categories_nestUnder'] != null) {
            $DBLIB->where("categories_id", $category['categories_nestUnder']);
            $category = $DBLIB->getone("categories", ["categories_name", "categories_nestUnder"]);
            $url .= '/' . $category['categories_name'];
            if ($category['categories_nestUnder'] != null) {
                $DBLIB->where("categories_id", $category['categories_nestUnder']);
                $category = $DBLIB->getone("categories", ["categories_name"]);
                $url .= '/' . $category['categories_name'];
            }
        }

        return $this->cacheClear($url . "/");
    }

    public function cacheClear($URL)
    {
        if (!$this->cloudflare) $this->cloudflareInit();

        $URL = [$URL, rtrim($URL, "/")]; //Also purge without a leading slash

        if ($this->cloudflare['zones']->cachePurge($this->cloudflare['zoneid'], $URL)) {
            $this->auditLog("CACHECLEAR", null, json_encode($URL));
            return true;
        } else return false;
    }

    private function cloudflareInit()
    {
        global $CONFIG;
        $this->cloudflare = [];
        $this->cloudflare['key'] = new Cloudflare\API\Auth\APIKey($CONFIG['CLOUDFLARE']['EMAIL'], $CONFIG['CLOUDFLARE']['KEY']);
        $this->cloudflare['adapter'] = new Cloudflare\API\Adapter\Guzzle($this->cloudflare['key']);
        $this->cloudflare['zones'] = new \Cloudflare\API\Endpoints\Zones($this->cloudflare['adapter']);
        $this->cloudflare['zoneid'] = $this->cloudflare['zones']->getZoneID('nouse.co.uk');
        if (!$this->cloudflare['zoneid']) return false;
        else return true;
        //$this->cloudflare['user'] = new Cloudflare\API\Endpoints\User($this->cloudflare['adapter']);
    }

    function auditLog($actionType = null, $table = null, $revelantData = null, $userid = null, $useridTo = null)
    { //Keep an audit trail of actions - $userid is this user, and $useridTo is who this action was done to if it was at all
        global $DBLIB;
        $data = [
            "auditLog_actionType" => $this->sanitizeString($actionType),
            "auditLog_actionTable" => $this->sanitizeString($table),
            "auditLog_actionData" => $this->sanitizeString($revelantData),
            "auditLog_timestamp" => date("Y-m-d H:i:s")
        ];
        if ($userid > 0) $data["users_userid"] = $this->sanitizeString($userid);
        if ($useridTo > 0) $data["auditLog_actionUserid"] = $this->sanitizeString($useridTo);

        if ($DBLIB->insert("auditLog", $data)) return true;
        else return false;
    }

    public function categoryURL($categoryid) {
        global $DBLIB, $bCMS;
        $DBLIB->where("categories_id", $bCMS->sanitizeString($categoryid));
        $category = $DBLIB->getone("categories",["categories_name","categories_nestUnder"]);
        if ($category["categories_nestUnder"] == null) return $category["categories_name"];
        $url = $category["categories_name"];

        $DBLIB->where("categories_id", $category['categories_nestUnder']);
        $category = $DBLIB->getone("categories",["categories_name","categories_nestUnder"]);
        if ($category["categories_nestUnder"] == null) return $category["categories_name"] . "/" . $url;
        $url = $category["categories_name"] . "/" . $url;

        $DBLIB->where("categories_id", $category['categories_nestUnder']);
        $category = $DBLIB->getone("categories",["categories_name","categories_nestUnder"]);
        return $category["categories_name"] . "/" . $url;
    }

    public function yusuNotify($articleid)
    {
        global $DBLIB, $CONFIG;
        $DBLIB->where("articles.articles_id", $this->sanitizeString($articleid));
        $DBLIB->where("articles_mediaCharterDone", 0);
        $DBLIB->where("articles_showInSearch", 1);
        $DBLIB->join("articlesDrafts", "articles.articles_id=articlesDrafts.articles_id", "LEFT");
        $DBLIB->where("articlesDrafts.articlesDrafts_id = (SELECT articlesDrafts_id FROM articlesDrafts WHERE articlesDrafts.articles_id=articles.articles_id ORDER BY articlesDrafts_timestamp DESC LIMIT 1)");
        $article = $DBLIB->getone("articles", ["articles.articles_id", "articles_categories", "articles.articles_published", "articles.articles_slug", "articlesDrafts.articlesDrafts_headline", "articlesDrafts.articlesDrafts_excerpt"]);
        if (!$article) return false;

        //YUSU Notification email html
        $html = "You are receiving this email as a notification of a new article being uploaded to the Nouse.co.uk website in compliance with section 5.3 of the YUSU Media Charter.<br/><br/>";
        if (strtotime($article["articles_published"]) > time()) $html .= "This article will be published at " . $article["articles_published"] . " GMT and this email is an advanced notification of publication. No further notifications will follow and this article will be automatically published.<br/><br/>";
        $html .= "<b>Headline: </b>" . $article['articlesDrafts_headline'] . "<br/>";
        $html .= "<b>Excerpt: </b>" . $article['articlesDrafts_excerpt'] . "<br/>";
        if (strtotime($article["articles_published"]) > time()) $html .= "This article hasn't been published yet, so it's not accessible on our website. A secret link has been generated for you to preview it, but please don't share this externally: <a href='" . $CONFIG['ROOTFRONTENDURL'] . "/" . date("Y/m/d", strtotime($article['articles_published'])) . "/" . $article['articles_slug'] . "?key=" . md5($article['articles_id']) . "'>" . $CONFIG['ROOTFRONTENDURL'] . "/" . date("Y/m/d", strtotime($article['articles_published'])) . "/" . $article['articles_slug'] . "</a>";
        else $html .= "<b>Link to article: </b><a href='" . $CONFIG['ROOTFRONTENDURL'] . "/" . date("Y/m/d", strtotime($article['articles_published'])) . "/" . $article['articles_slug'] . "'>" . $CONFIG['ROOTFRONTENDURL'] . "/" . date("Y/m/d", strtotime($article['articles_published'])) . "/" . $article['articles_slug'] . "</a>";
        $html .= "<br/><br/><br/>If you have any questions about this notification please do not hesitate to contact us on support@nouse.co.uk.<br/>For queries relating to this article itself (for example concerns about its content) please contact editor@nouse.co.uk. <br/><br/><br/>Nouse Technical Team<br/><i>" . gethostname() . " (compliance tracked at  " . date("Y-m-d H:i:s") . " UTC)</i>";
        if (count(array_intersect([2, 6, 7], explode(",", $article['articles_categories']))) > 0) {
            if (sendemail("media-charter-notifications@nouse.co.uk", "New article on Nouse.co.uk", $html)) {
                $DBLIB->where("articles_id", $article['articles_id']);
                $DBLIB->update("articles", ["articles_mediaCharterDone" => 1]);
                return true;
            } else return false;
        } else {
            //We don't need to tell YUSU about this as it's not in categories 1,6 or 7
            $DBLIB->where("articles_id", $article['articles_id']);
            $DBLIB->update("articles", ["articles_mediaCharterDone" => 2]);
            return true;
        }

    }

    public function postSocial($articleid, $postToFacebook = true, $postToTwitter = true)
    {
        global $DBLIB, $CONFIG;
        $DBLIB->where("articles.articles_id", $this->sanitizeString($articleid));
        $DBLIB->where("articles.articles_showInSearch", 1); //ie those that can actually be shown - no point tweeting a dud link
        $DBLIB->where("articles.articles_published <= '" . date("Y-m-d H:i:s") . "'");
        $DBLIB->join("articlesDrafts", "articles.articles_id=articlesDrafts.articles_id", "LEFT");
        $DBLIB->where("articlesDrafts.articlesDrafts_id = (SELECT articlesDrafts_id FROM articlesDrafts WHERE articlesDrafts.articles_id=articles.articles_id ORDER BY articlesDrafts_timestamp DESC LIMIT 1)");
        $article = $DBLIB->getone("articles", ["articles_socialExcerpt", "articles.articles_socialConfig", "articles.articles_published", "articles.articles_slug", "articlesDrafts.articlesDrafts_headline", "articlesDrafts.articlesDrafts_excerpt"]);
        if (!$article) return false;

        $realpermalink = $CONFIG['ROOTFRONTENDURL'] . "/" . date("Y/m/d", strtotime($article["articles_published"])) . "/" . $article['articles_slug'];

        if (strlen($article['articles_socialExcerpt']) > 0) {
            $postExcerpt = $article['articles_socialExcerpt'];
        } elseif (strlen($article['articlesDrafts_excerpt']) > 0) {
            $postExcerpt = $article['articlesDrafts_excerpt'];
        } else {
            $postExcerpt = $article['articlesDrafts_headline'];
        }

        $article["articles_socialConfig"] = explode(",", $article["articles_socialConfig"]);
        if ($article["articles_socialConfig"][0] == 1 and $article["articles_socialConfig"][1] != 1 and $postToFacebook) {
            //Go ahead and post to facebook


            $url = 'https://maker.ifttt.com/trigger/socialMediaAutomationFB/with/key/' . $CONFIG['IFTTT'];
            $ch = curl_init($url);
            $xml = "value1=" . urlencode($postExcerpt) . "&value2=" . urlencode($realpermalink) . "&value3=null";
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Supress the output from being dumped
            $response = curl_exec($ch);
            curl_close($ch);

            if (true) $article["articles_socialConfig"][1] = 1; //TODO check the IFTTT response
        }
        if ($article["articles_socialConfig"][2] == 1 and $article["articles_socialConfig"][3] != 1 and $postToTwitter) {
            //Go ahead and post to twitter

            $url = 'https://maker.ifttt.com/trigger/socialMediaAutomationTwitter/with/key/' . $CONFIG['IFTTT'];
            $ch = curl_init($url);
            $xml = "value1=" . urlencode($postExcerpt) . "&value2=" . urlencode($realpermalink) . "&value3=null";
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Supress the output from being dumped
            $response = curl_exec($ch);
            curl_close($ch);

            if (true) $article["articles_socialConfig"][3] = 1; //TODO check the IFTTT response
        }

        $DBLIB->where("articles_id", $this->sanitizeString($articleid));
        if ($DBLIB->update("articles", ["articles.articles_socialConfig" => implode(",", $article["articles_socialConfig"])])) return true;
        else return false;
    }


    function htmlToAppleNews($html)
    {
        //HTML Purification
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.DefinitionID', 'enduser-customize.html tutorial');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('Cache.DefinitionImpl', null); // TODO: remove this later!
        $config->set('HTML.Allowed', 'p,strong,b,em,i,a[href],ul,ol,li,br,sub,sup,del,s,pre,code,samp,blockquote,img[src]');
        $purifier = new HTMLPurifier($config);
        $purified = $purifier->purify($html);

        $images = [];
        $simpleHtmlDom = HtmlDomParser::str_get_html($purified);
        $newHTML = $simpleHtmlDom->save();
        $newHTML = str_replace("<p></p>","", $newHTML);
        foreach($simpleHtmlDom->find("img") as $key=>$element){
            $newHTML = str_replace($simpleHtmlDom->find("img",$key)->outertext,"!IMG!", $newHTML);
            $images[] = str_replace(" ", "%20", $element->src);
        }
        $output = [];

        foreach (explode("!IMG!", $newHTML) as $key=>$part) {
            if ($key > 0) $output[] = [
                "role" => "photo",
                "URL" => $images[$key-1],
                "explicitContent"=> false
            ];
            $part = $purifier->purify($part); //Often get unclosed tags
            $replacements = [ //List of annoying formatting issues that tend to pad out our articles badly
                "<p></p>"=>"",
                "<br/><p>"=>"<p>",
                "</p><br/>"=>"</p>",
                "<br /><p>"=>"<p>",
                "</p><br />"=>"</p>",
                "<br><p>"=>"<p>",
                "</p><br>"=>"</p>",
                "<p><br /></p>"=>"",
                "<p><br/></p>"=>"",
                "<p><br></p>"=>""
            ];
            foreach ($replacements as $key=>$value) {
                $part = str_replace($key,$value, $part);
            }

            if (strlen($part) > 0) { //You can't have anything that is null
                $output[] = [
                    "role" => "body",
                    "text" => $part,
                    "layout" => "bodyLayout",
                    "format"=> "html",
                    "textStyle" => "bodyStyle"
                ];
            }
        }
        return $output;
    }
    public function postToAppleNews($articleid)
    {
        global $CONFIG, $DBLIB;
        $DBLIB->where("articles.articles_id", $this->sanitizeString($articleid));
        $DBLIB->where("articles.articles_showInLists", 1);
        $DBLIB->where("articles.articles_type IN (1,6)");
        $DBLIB->where("articles.articles_published <= '" . date("Y-m-d H:i:s") . "'");
        $DBLIB->join("articlesDrafts", "articles.articles_id=articlesDrafts.articles_id", "LEFT");
        $DBLIB->where("articlesDrafts.articlesDrafts_id = (SELECT articlesDrafts_id FROM articlesDrafts WHERE articlesDrafts.articles_id=articles.articles_id ORDER BY articlesDrafts_timestamp DESC LIMIT 1)");
        $article = $DBLIB->getone("articles");
        if (!$article) return false;


        $PublisherAPI = new ChapterThree\AppleNewsAPI\PublisherAPI(
            $CONFIG["APPLE"]["NEWS"]["KEY"],
            $CONFIG["APPLE"]["NEWS"]["SECRET"],
            "https://news-api.apple.com"
        );

        if (strlen($article['articles_authors']) > 0) {
            $DBLIB->where("users_deleted != 1");
            $DBLIB->where("users_userid IN (" . $article['articles_authors'] . ")");
            $article['authors'] = $DBLIB->get("users", null, ["users_name1", "users_name2"]);
        } else $article['authors'] = null;

        $authorString = "";
        foreach ($article['authors'] as $key=>$user) {
            if ($key > 0) $authorString .= " & ";
            $authorString .= $user["users_name1"] . " " . $user["users_name2"];
        }


       $metaData =  [
            'data' => [
                'isSponsored' => false,
                'links' => [
                    'sections' => [],
                ],
                "isPreview" => false,
                "maturityRating" => "GENERAL",
            ],
        ];

        $coreData = [
            'metadata' => [
                'authors' => [],
                'canonicalURL' => $CONFIG['ROOTFRONTENDURL'] . "/" . date("Y/m/d", strtotime($article["articles_published"])) . "/" . $article['articles_slug'],
                //'coverArt' => [''],
                'dateCreated' => date("c", strtotime($article["articles_published"])),
                'dateModified' => date("c", time()),
                'datePublished' => date("c", strtotime($article["articles_published"])),
                'excerpt' => $article["articlesDrafts_excerpt"],
                //'keywords' => '',
                'thumbnailURL' => $this->articleThumbnail($article['articles_id']), //At least 300x300
            ],
            "version" => "1.7",
            "identifier" => "nouse-" . $article['articles_id'],
            "title" => $article['articlesDrafts_headline'],
            "language" => "en-GB",
            "componentTextStyles" => [
                "default-title" => [

                    "fontName"=>"HelveticaNeue-Thin",
                    "fontSize"=>36,
                    "textColor"=>"#2F2F2F",
                    "textAlignment"=>"center",
                    "lineHeight"=>44,
                ],
                "default-subtitle" => [

                    "fontName"=>"HelveticaNeue-Thin",
                    "fontSize"=>20,
                    "textColor"=>"#2F2F2F",
                    "textAlignment"=>"center",
                    "lineHeight"=>24,
                ],
                "titleStyle" => [
                    "textAlignment"=>"left",
                    "fontName"=>"HelveticaNeue-Bold",
                    "fontSize"=>64,
                    "lineHeight"=>74,
                    "textColor"=>"#000",
                ],
                "introStyle" => [
                    "textAlignment"=>"left",
                    "fontName"=>"HelveticaNeue-Medium",
                    "fontSize"=>24,
                    "textColor"=>"#000",
                ],
                "authorStyle" => [
                    "textAlignment"=>"left",
                    "fontName"=>"HelveticaNeue-Bold",
                    "fontSize"=>16,
                    "textColor"=>"#000",
                ],
                "bodyStyle" => [
                    "textAlignment"=>"left",
                    "fontName"=>"Georgia",
                    "fontSize"=>18,
                    "lineHeight"=>26,
                    "textColor"=>"#000",
                ],
                "captionStyle" => [
                    "textAlignment"=>"left",
                    "fontName"=>"HelveticaNeue-Medium",
                    "fontSize"=>12,
                    "lineHeight"=>17,
                    "textColor"=>"#000",
                ],
                "heading1Style" => [
                    "textAlignment"=>"left",
                    "fontName"=>"HelveticaNeue-Medium",
                    "fontSize"=>28,
                    "lineHeight"=>41,
                    "textColor"=>"#000",
                ],
                "pullquoteStyle" => [
                    "textAlignment"=>"left",
                    "fontName"=>"HelveticaNeue-Bold",
                    "fontSize"=>28,
                    "lineHeight"=>41,
                    "textColor"=>"#000",
                ]
            ],
            "componentLayouts" => [
                "headerImageLayout" => [
                    "columnStart" => 0,
                    "columnSpan" => 7,
                    "ignoreDocumentMargin" => true,
                    "minimumHeight" => "42vh"
                ],
                "titleLayout" => [
                    "columnStart" => 0,
                    "columnSpan" => 7,
                    "margin" => [
                        "top" => 30,
                        "bottom" => 10
                    ]
                ],
                "introLayout" => [
                    "columnStart" => 0,
                    "columnSpan" => 7,
                    "margin" => [
                        "top" => 15,
                        "bottom" => 15
                    ]
                ],
                "authorLayout" => [
                    "columnStart" => 0,
                    "columnSpan" => 7,
                    "margin" => [
                        "top" => 15,
                        "bottom" => 15
                    ]
                ],
                "bodyLayout" => [
                    "columnStart" => 0,
                    "columnSpan" => 7,
                    "margin" => [
                        "top" => 15,
                        "bottom" => 15
                    ]
                ],
                "captionLayout" => [
                    "columnStart" => 5,
                    "columnSpan" => 2,
                    "margin" => [
                        "top" => 15,
                        "bottom" => 30
                    ]
                ]
            ],
            "layout" => [
                "columns" => 7,
                "width" => 1024,
                "margin"=> 60,
                "gutter"=> 20
            ]
        ];

        $coreData["components"] = [];

        if ($this->articleThumbnail($article['articles_id'])) {
            array_push($coreData["components"],[
                "role" => "header",
                "layout" => "headerImageLayout",
                "style" =>
                    ["fill" =>
                        [
                            "type" => "image",
                            "URL" => $this->articleThumbnail($article['articles_id']),
                            "fillMode" => "cover",
                            "verticalAlignment" => "center"
                        ]
                    ]
            ]);
            if ($article['articlesDrafts_thumbnailCredit'] != null) {
                array_push($coreData["components"],[
                    "role" => "body",
                    "text"=>    "Thumbnail Credit: " . $article['articlesDrafts_thumbnailCredit'],
                    "layout" => "bodyLayout",
                    "format"=> "html",
                    "textStyle" => "bodyStyle"
                ]);
            }
        }

        array_push($coreData["components"],[
            "role" => "title",
            "layout"=> "titleLayout",
            "text"=>$article['articlesDrafts_headline'],
            "textStyle" => "titleStyle"
        ],[
            "role" => "intro",
            "layout"=> "introLayout",
            "text"=>$article['articlesDrafts_excerpt'],
            "textStyle" => "introStyle"
        ],[
            "role" => "author",
            "layout"=> "authorLayout",
            "text"=>    $authorString . " | " . date("l d M y", strtotime($article["articles_published"])),
            "textStyle" => "authorStyle"
        ]);
        foreach ($this->htmlToAppleNews($article['articlesDrafts_text']) as $draftPoint) {
            $coreData['components'][] = $draftPoint;
        }


        $DBLIB->where("categories_appleNewsID IS NOT NULL");
        $DBLIB->where("categories_id IN (" . $article['articles_categories'] . ")");
        $article['categories'] = $DBLIB->get("categories", null, ["categories_appleNewsID"]);

        $metaData['data']['links']['sections'][] = "https://news-api.apple.com/sections/d2d73607-1406-4ece-bb8a-a16a613f0e86"; //Put it in home section by default
        foreach ($article['categories'] as $category) {
            $metaData['data']['links']['sections'][] = "https://news-api.apple.com/sections/" . $category['categories_appleNewsID'];
        }



        foreach ($article['authors'] as $user) {
            $coreData['metadata']['authors'][] = $user["users_name1"] . " " . $user["users_name2"];

        }

        if ($article['articles_appleNewsID']) {
            //Update it as an existing article
            $getArticleData = $PublisherAPI->Get('/articles/' . $article['articles_appleNewsID'],
                [
                    'article_id' => $article['articles_appleNewsID']
                ]
            );
            if ($getArticleData->errors) $getArticleData = false;
        }
        if ($getArticleData) {
            $metaData['data']['revision'] = $getArticleData->data->revision; //This weird thing apple do where they require you to get a special id just before you update it to prevent you accidentally updating someone else's work
            $replaceResponse = $PublisherAPI->post('/articles/' . $article['articles_appleNewsID'],
                [
                    'article_id' => $article['articles_appleNewsID']
                ],
                [
                    // required. Apple News Native formatted JSON string.
                    'json' => json_encode($coreData, JSON_UNESCAPED_SLASHES),
                    'metadata' => json_encode($metaData, JSON_UNESCAPED_SLASHES), // required
                ]
            );
            if ($replaceResponse->data->id) {
                $DBLIB->where("articles_id", $article['articles_id']);
                $DBLIB->update("articles", ["articles_appleNewsShareLink" => $replaceResponse->data->shareUrl]);
                return true;
            }
            else return false;
        } else {
            //Upload it as a new article
            $uploadResponse = $PublisherAPI->Post('/channels/' . $CONFIG["APPLE"]["NEWS"]["CHANNEL"] . '/articles',
                [
                    'channel_id' => $CONFIG["APPLE"]["NEWS"]["CHANNEL"]
                ],
                [
                    // required. Apple News Native formatted JSON string.
                    'json' => json_encode($coreData, JSON_UNESCAPED_SLASHES),
                    'metadata' => json_encode($metaData, JSON_UNESCAPED_SLASHES), // required
                ]
            );
            if ($uploadResponse) {
                $DBLIB->where("articles_id", $article['articles_id']);
                $DBLIB->update("articles", ["articles_appleNewsID" => $uploadResponse->data->id, "articles_appleNewsShareLink" => $uploadResponse->data->shareUrl]);
            }
            if ($uploadResponse->data->id) return true;
            else return false;
        }
    }
    public function deleteAppleNews($articleid)
    {
        global $CONFIG, $DBLIB;
        if ($articleid == null) return false;
        $DBLIB->where("articles.articles_id", $this->sanitizeString($articleid));
        $DBLIB->where("(articles_appleNewsID IS NOT NULL)");
        $article = $DBLIB->getone("articles", ["articles_appleNewsID","articles_id"]);
        if (!$article) return false;


        $PublisherAPI = new ChapterThree\AppleNewsAPI\PublisherAPI(
            $CONFIG["APPLE"]["NEWS"]["KEY"],
            $CONFIG["APPLE"]["NEWS"]["SECRET"],
            "https://news-api.apple.com"
        );


        $getArticleData = $PublisherAPI->Get('/articles/' . $article['articles_appleNewsID'],
            [
                'article_id' => $article['articles_appleNewsID']
            ]
        );
        if ($getArticleData->errors) return false;

        $deleteResponse = $PublisherAPI->delete('/articles/' . $article['articles_appleNewsID'],
            [
                'article_id' => $article['articles_appleNewsID']
            ]
        );
        $DBLIB->where("articles.articles_id", $article['articles_id']);
        $articleRemoveAppleNews = $DBLIB->update("articles", ["articles_appleNewsID"=>null,"articles_appleNewsShareLink"=>null]);
        if ($articleRemoveAppleNews) return true;
        else return false;
    }
}

$GLOBALS['bCMS'] = new bCMS;