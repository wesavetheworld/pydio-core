<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
namespace Pydio\Gui\Ajax;

use DOMXPath;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pydio\Core\Http\Middleware\SecureTokenMiddleware;
use Pydio\Core\Model\Context;
use Pydio\Core\Model\ContextInterface;
use Pydio\Core\Services\ConfService;
use Pydio\Core\Controller\Controller;
use Pydio\Core\Services\LocaleService;
use Pydio\Core\Services\UsersService;
use Pydio\Core\Utils\Utils;
use Pydio\Core\Controller\XMLWriter;
use Pydio\Core\Controller\HTMLWriter;
use Pydio\Core\PluginFramework\Plugin;
use Pydio\Core\PluginFramework\PluginsService;
use Zend\Diactoros\Response\JsonResponse;

defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * User Interface main implementation
 * @package AjaXplorer_Plugins
 * @subpackage Gui
 */
class AJXP_ClientDriver extends Plugin
{
    private static $loadedBookmarks;

    public function isEnabled()
    {
        return true;
    }

    public function loadConfigs($configData)
    {
        parent::loadConfigs($configData);
        if (!defined("AJXP_THEME_FOLDER")) {
            define("CLIENT_RESOURCES_FOLDER", AJXP_PLUGINS_FOLDER."/gui.ajax/res");
            define("AJXP_THEME_FOLDER", CLIENT_RESOURCES_FOLDER."/themes/".$this->pluginConf["GUI_THEME"]);
        }
        if (!isSet($configData["CLIENT_TIMEOUT_TIME"])) {
            $this->pluginConf["CLIENT_TIMEOUT_TIME"] = intval(ini_get("session.gc_maxlifetime"));
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     */
    public function getBootConf(ServerRequestInterface &$request, ResponseInterface &$response){

        /** @var ContextInterface $ctx */
        $ctx = $request->getAttribute("ctx");
        $out = array();
        Utils::parseApplicationGetParameters($ctx, $request->getQueryParams(), $out, $_SESSION);
        $config = $this->computeBootConf($ctx);
        $response = new JsonResponse($config);

    }
        /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     */
    public function getBootGui(ServerRequestInterface &$request, ResponseInterface &$response){

        if (!defined("AJXP_THEME_FOLDER")) {
            define("CLIENT_RESOURCES_FOLDER", AJXP_PLUGINS_FOLDER."/gui.ajax/res");
            define("AJXP_THEME_FOLDER", CLIENT_RESOURCES_FOLDER."/themes/".$this->pluginConf["GUI_THEME"]);
        }
        $mess = LocaleService::getMessages();
        /** @var ContextInterface $ctx */
        $ctx = $request->getAttribute("ctx");

        $httpVars = $request->getParsedBody();
        HTMLWriter::internetExplorerMainDocumentHeader($response);

        if (!is_file(TESTS_RESULT_FILE)) {
            $outputArray = array();
            $testedParams = array();
            $passed = Utils::runTests($outputArray, $testedParams);
            if (!$passed && !isset($httpVars["ignore_tests"])) {
                Utils::testResultsToTable($outputArray, $testedParams);
                die();
            } else {
                Utils::testResultsToFile($outputArray, $testedParams);
            }
        }

        $root = parse_url($request->getServerParams()['REQUEST_URI'], PHP_URL_PATH);
        $configUrl = ConfService::getCoreConf("SERVER_URL");
        if(!empty($configUrl)){
            $root = '/'.ltrim(parse_url($configUrl, PHP_URL_PATH), '/');
            if(strlen($root) > 1) $root = rtrim($root, '/').'/';
        }else{
            preg_match ('/ws-(.)*\/|settings|dashboard|welcome|user/', $root, $matches, PREG_OFFSET_CAPTURE);
            if(count($matches)){
                $capture = $matches[0][1];
                $root = substr($root, 0, $capture);
            }
        }
        $START_PARAMETERS = array(
            "BOOTER_URL"        =>"index.php?get_action=get_boot_conf",
            "MAIN_ELEMENT"      => "ajxp_desktop",
            "APPLICATION_ROOT"  => $root,
            "REBASE"            => $root
        );
        if($request->getAttribute("flash") !== null){
            $START_PARAMETERS["ALERT"] = $request->getAttribute("flash");
        }

        Utils::parseApplicationGetParameters($ctx, $request->getQueryParams(), $START_PARAMETERS, $_SESSION);

        $confErrors = ConfService::getErrors();
        if (count($confErrors)) {
            $START_PARAMETERS["ALERT"] = implode(", ", array_values($confErrors));
        }
        // PRECOMPUTE BOOT CONF
        $userAgent = $request->getServerParams()['HTTP_USER_AGENT'];
        if (!preg_match('/MSIE 7/',$userAgent) && !preg_match('/MSIE 8/',$userAgent)) {
            $preloadedBootConf = $this->computeBootConf($ctx);
            Controller::applyHook("loader.filter_boot_conf", array(&$preloadedBootConf));
            $START_PARAMETERS["PRELOADED_BOOT_CONF"] = $preloadedBootConf;
        }

        // PRECOMPUTE REGISTRY
        if (!isSet($START_PARAMETERS["FORCE_REGISTRY_RELOAD"])) {
            $clone = PluginsService::getInstance($ctx)->getFilteredXMLRegistry(true, true);
            if(!AJXP_SERVER_DEBUG){
                $clonePath = new DOMXPath($clone);
                $serverCallbacks = $clonePath->query("//serverCallback|hooks");
                foreach ($serverCallbacks as $callback) {
                    $callback->parentNode->removeChild($callback);
                }
            }
            $START_PARAMETERS["PRELOADED_REGISTRY"] = XMLWriter::replaceAjxpXmlKeywords($clone->saveXML());
        }

        $JSON_START_PARAMETERS = json_encode($START_PARAMETERS);
        $crtTheme = $this->pluginConf["GUI_THEME"];
        $additionalFrameworks = $this->getContextualOption($ctx, "JS_RESOURCES_BEFORE");
        $ADDITIONAL_FRAMEWORKS = "";
        if( !empty($additionalFrameworks) ){
            $frameworkList = explode(",", $additionalFrameworks);
            foreach($frameworkList as $index => $framework){
                $frameworkList[$index] = '<script language="javascript" type="text/javascript" src="'.$framework.'"></script>'."\n";
            }
            $ADDITIONAL_FRAMEWORKS = implode("", $frameworkList);
        }
        if (ConfService::getConf("JS_DEBUG")) {
            if (!isSet($mess)) {
                $mess = LocaleService::getMessages();
            }
            if (is_file(AJXP_INSTALL_PATH."/plugins/gui.ajax/res/themes/$crtTheme/html/gui_debug.html")) {
                include(AJXP_INSTALL_PATH."/plugins/gui.ajax/res/themes/$crtTheme/html/gui_debug.html");
            } else {
                include(AJXP_INSTALL_PATH."/plugins/gui.ajax/res/html/gui_debug.html");
            }
        } else {
            if (is_file(AJXP_INSTALL_PATH."/plugins/gui.ajax/res/themes/$crtTheme/html/gui.html")) {
                $content = file_get_contents(AJXP_INSTALL_PATH."/plugins/gui.ajax/res/themes/$crtTheme/html/gui.html");
            } else {
                $content = file_get_contents(AJXP_INSTALL_PATH."/plugins/gui.ajax/res/html/gui.html");
            }
            if (preg_match('/MSIE 7/',$userAgent)){
                $ADDITIONAL_FRAMEWORKS = "";
            }
            $content = str_replace("AJXP_ADDITIONAL_JS_FRAMEWORKS", $ADDITIONAL_FRAMEWORKS, $content);
            $content = XMLWriter::replaceAjxpXmlKeywords($content, false);
            $content = str_replace("AJXP_REBASE", isSet($START_PARAMETERS["REBASE"])?'<base href="'.$START_PARAMETERS["REBASE"].'"/>':"", $content);
            if ($JSON_START_PARAMETERS) {
                $content = str_replace("//AJXP_JSON_START_PARAMETERS", "startParameters = ".$JSON_START_PARAMETERS.";", $content);
            }
            $response->getBody()->write($content);
        }

    }

    public function switchAction($action, $httpVars, $fileVars)
    {
        if (!defined("AJXP_THEME_FOLDER")) {
            define("CLIENT_RESOURCES_FOLDER", AJXP_PLUGINS_FOLDER."/gui.ajax/res");
            define("AJXP_THEME_FOLDER", CLIENT_RESOURCES_FOLDER."/themes/".$this->pluginConf["GUI_THEME"]);
        }
        foreach ($httpVars as $getName=>$getValue) {
            $$getName = Utils::securePath($getValue);
        }
        $mess = LocaleService::getMessages();

        switch ($action) {
            //------------------------------------
            //	GET AN HTML TEMPLATE
            //------------------------------------
            case "get_template":

                HTMLWriter::charsetHeader();
                $folder = CLIENT_RESOURCES_FOLDER."/html";
                if (isSet($httpVars["pluginName"])) {
                    $folder = AJXP_INSTALL_PATH."/".AJXP_PLUGINS_FOLDER."/".Utils::securePath($httpVars["pluginName"]);
                    if (isSet($httpVars["pluginPath"])) {
                        $folder.= "/".Utils::securePath($httpVars["pluginPath"]);
                    }
                }
                $thFolder = AJXP_THEME_FOLDER."/html";
                if (isset($template_name)) {
                    if (is_file($thFolder."/".$template_name)) {
                        include($thFolder."/".$template_name);
                    } else if (is_file($folder."/".$template_name)) {
                        include($folder."/".$template_name);
                    }
                }

            break;

            //------------------------------------
            //	GET I18N MESSAGES
            //------------------------------------
            case "get_i18n_messages":

                $refresh = false;
                if (isSet($httpVars["lang"])) {
                    LocaleService::setLanguage($httpVars["lang"]);
                    $refresh = true;
                }
                if(isSet($httpVars["format"]) && $httpVars["format"] == "json"){
                    HTMLWriter::charsetHeader("application/json");
                    echo json_encode(LocaleService::getMessages($refresh));
                }else{
                    HTMLWriter::charsetHeader('text/javascript');
                    HTMLWriter::writeI18nMessagesClass(LocaleService::getMessages($refresh));
                }

            break;

            //------------------------------------
            //	DISPLAY DOC
            //------------------------------------
            case "display_doc":

                HTMLWriter::charsetHeader();
                echo HTMLWriter::getDocFile(Utils::securePath(htmlentities($httpVars["doc_file"])));

            break;

            
            default;
            break;
        }

        return false;
    }

    /**
     * @param ContextInterface $ctx
     * @return array
     */
    public function computeBootConf(ContextInterface $ctx)
    {
        if (isSet($_GET["server_prefix_uri"])) {
            $_SESSION["AJXP_SERVER_PREFIX_URI"] = str_replace("_UP_", "..", $_GET["server_prefix_uri"]);
        }
        $currentIsMinisite = (strpos(session_name(), "AjaXplorer_Shared") === 0);
        $config = array();
        $config["ajxpResourcesFolder"] = "plugins/gui.ajax/res";
        if ($currentIsMinisite) {
            $config["ajxpServerAccess"] = "public/";
        } else {
            $config["ajxpServerAccess"] = AJXP_SERVER_ACCESS;
        }
        $config["zipEnabled"] = ConfService::zipBrowsingEnabled();
        $config["multipleFilesDownloadEnabled"] = ConfService::zipCreationEnabled();
        $customIcon = $this->getContextualOption($ctx, "CUSTOM_ICON");
        self::filterXml($customIcon);
        $config["customWording"] = array(
            "welcomeMessage" => $this->getContextualOption($ctx, "CUSTOM_WELCOME_MESSAGE"),
            "title"			 => ConfService::getCoreConf("APPLICATION_TITLE"),
            "icon"			 => $customIcon,
            "iconWidth"		 => $this->getContextualOption($ctx, "CUSTOM_ICON_WIDTH"),
            "iconHeight"     => $this->getContextualOption($ctx, "CUSTOM_ICON_HEIGHT"),
            "iconOnly"       => $this->getContextualOption($ctx, "CUSTOM_ICON_ONLY"),
            "titleFontSize"	 => $this->getContextualOption($ctx, "CUSTOM_FONT_SIZE")
        );
        $cIcBin = $this->getContextualOption($ctx, "CUSTOM_ICON_BINARY");
        if (!empty($cIcBin)) {
            $config["customWording"]["icon_binary_url"] = "get_action=get_global_binary_param&binary_id=".$cIcBin;
        }
        $config["usersEnabled"] = UsersService::usersEnabled();
        $config["loggedUser"] = ($ctx->hasUser());
        $config["currentLanguage"] = LocaleService::getLanguage();
        $config["session_timeout"] = intval(ini_get("session.gc_maxlifetime"));
        $timeoutTime = $this->getContextualOption($ctx, "CLIENT_TIMEOUT_TIME");
        if (empty($timeoutTime)) {
            $to = $config["session_timeout"];
        } else {
            $to = $timeoutTime;
        }
        if($currentIsMinisite) $to = -1;
        $config["client_timeout"] = intval($to);
        $config["client_timeout_warning"] = floatval($this->getContextualOption($ctx, "CLIENT_TIMEOUT_WARN"));
        $config["availableLanguages"] = ConfService::getConf("AVAILABLE_LANG");
        $config["usersEditable"] = ConfService::getAuthDriverImpl()->usersEditable();
        $config["ajxpVersion"] = AJXP_VERSION;
        $config["ajxpVersionDate"] = AJXP_VERSION_DATE;
        $analytic = $this->getContextualOption($ctx, 'GOOGLE_ANALYTICS_ID');
        if (!empty($analytic)) {
            $config["googleAnalyticsData"] = array(
                "id"=> 		$analytic,
                "domain" => $this->getContextualOption($ctx, 'GOOGLE_ANALYTICS_DOMAIN'),
                "event" => 	$this->getContextualOption($ctx, 'GOOGLE_ANALYTICS_EVENT')
            );
        }
        $config["i18nMessages"] = LocaleService::getMessages();
        $config["SECURE_TOKEN"] = SecureTokenMiddleware::generateSecureToken();
        $config["streaming_supported"] = "true";
        $config["theme"] = $this->pluginConf["GUI_THEME"];
        return $config;
    }

    /**
     * @param \Pydio\Access\Core\Model\AJXP_Node $ajxpNode
     * @return void
     */
    public function nodeBookmarkMetadata(&$ajxpNode)
    {
        $user = $ajxpNode->getContext()->getUser();
        if(empty($user)) return;
        $metadata = $ajxpNode->retrieveMetadata("ajxp_bookmarked", true, AJXP_METADATA_SCOPE_REPOSITORY, true);
        if (is_array($metadata) && count($metadata)) {
            $ajxpNode->mergeMetadata(array(
                     "ajxp_bookmarked" => "true",
                     "overlay_icon"  => "bookmark.png",
                     "overlay_class" => "icon-bookmark-empty"
                ), true);
            return;
        }
        if (!isSet(self::$loadedBookmarks)) {
            self::$loadedBookmarks = $user->getBookmarks($ajxpNode->getRepositoryId());
        }
        foreach (self::$loadedBookmarks as $bm) {
            if ($bm["PATH"] == $ajxpNode->getPath()) {
                $ajxpNode->mergeMetadata(array(
                         "ajxp_bookmarked" => "true",
                         "overlay_icon"  => "bookmark.png",
                        "overlay_class" => "icon-bookmark-empty"
                    ), true);
                $ajxpNode->setMetadata("ajxp_bookmarked", array("ajxp_bookmarked"=> "true"), true, AJXP_METADATA_SCOPE_REPOSITORY, true);
            }
        }
    }

    /**
     * @param \Pydio\Access\Core\Model\AJXP_Node $fromNode
     * @param \Pydio\Access\Core\Model\AJXP_Node $toNode
     * @param bool $copy
     */
    public function nodeChangeBookmarkMetadata($fromNode=null, $toNode=null, $copy=false){
        if($copy || $fromNode == null) return;
        $user = $fromNode->getContext()->getUser();
        if($user == null) return;
        if (!isSet(self::$loadedBookmarks)) {
            self::$loadedBookmarks = $user->getBookmarks($fromNode->getRepositoryId());
        }
        if($toNode == null) {
            $fromNode->removeMetadata("ajxp_bookmarked", true, AJXP_METADATA_SCOPE_REPOSITORY, true);
        } else {
            $toNode->copyOrMoveMetadataFromNode($fromNode, "ajxp_bookmarked", "move", true, AJXP_METADATA_SCOPE_REPOSITORY, true);
        }
        Controller::applyHook("msg.instant", array($fromNode->getContext(), "<reload_bookmarks/>", $user->getId()));
    }

    public static function filterXml(&$value)
    {
        $instance = PluginsService::getInstance(Context::emptyContext())->findPlugin("gui", "ajax");
        if($instance === false) return null;
        $confs = $instance->getConfigs();
        $theme = $confs["GUI_THEME"];
        if (!defined("AJXP_THEME_FOLDER")) {
            define("CLIENT_RESOURCES_FOLDER", AJXP_PLUGINS_FOLDER."/gui.ajax/res");
            define("AJXP_THEME_FOLDER", CLIENT_RESOURCES_FOLDER."/themes/".$theme);
        }
        $value = str_replace(array("AJXP_CLIENT_RESOURCES_FOLDER", "AJXP_CURRENT_VERSION"), array(CLIENT_RESOURCES_FOLDER, AJXP_VERSION), $value);
        if (isSet($_SESSION["AJXP_SERVER_PREFIX_URI"])) {
            $value = str_replace("AJXP_THEME_FOLDER", $_SESSION["AJXP_SERVER_PREFIX_URI"]."plugins/gui.ajax/res/themes/".$theme, $value);
        } else {
            $value = str_replace("AJXP_THEME_FOLDER", "plugins/gui.ajax/res/themes/".$theme, $value);
        }
        return $value;
    }
}

Controller::registerIncludeHook("xml.filter", array("Pydio\\Gui\\Ajax\\AJXP_ClientDriver", "filterXml"));
