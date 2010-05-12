<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
require_once(DOKU_PLUGIN.'action.php');

require_once DOKU_PLUGIN.'data/bureaucracy_field.php';

class action_plugin_data extends DokuWiki_Action_Plugin {

    /**
     * will hold Modes the caching works for
     */
    var $supportedModes = array('xhtml', 'i');
    
    /**
     * will hold the data helper plugin
     */
    var $dthlp = null;

    /**
     * Constructor. Load helper plugin
     */
    function action_plugin_data(){
        $this->dthlp =& plugin_load('helper', 'data');
    }

    /**
     * Registers a callback function for a given event
     */
    function register(&$controller) {
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, '_handle');
        $controller->register_hook('HTML_SECEDIT_BUTTON', 'BEFORE', $this, '_editbutton');
        $controller->register_hook('HTML_EDIT_FORMSELECTION', 'BEFORE', $this, '_editform');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, '_handle_edit_post');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, '_handle_ajax');
        $controller->register_hook('PARSER_CACHE_USE','BEFORE', $this, '_cache_prepare');
    }

    /**
     * Handles the page write event and removes the database info
     * when the plugin code is no longer in the source
     */
    function _handle(&$event, $param){
        $data = $event->data;
        if(strpos($data[0][1],'dataentry') !== false) return; // plugin seems still to be there

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return;
        $id = ltrim($data[1].':'.$data[2],':');

        // get page id
        $res = $sqlite->query('SELECT pid FROM pages WHERE page = ?',$id);
        $pid = (int) $sqlite->res2single($res);
        if(!$pid) return; // we have no data for this page

        $sqlite->query('DELETE FROM data WHERE pid = ?',$pid);
        $sqlite->query('DELETE FROM pages WHERE pid = ?',$pid);
    }

    function _editbutton(&$event, $param) {
        if ($event->data['target'] !== 'plugin_data') {
            return;
        }

        $event->data['name'] = $this->getLang('dataentry');
    }

    function _editform(&$event, $param) {
        global $TEXT;
        if ($event->data['target'] !== 'plugin_data') {
            // Not a data edit
            return;
        }

        $event->stopPropagation();
        $event->preventDefault();
        unset($event->data['intro_locale']);
        $event->data['media_manager'] = false;

        echo $this->locale_xhtml('edit_intro' . ($this->getConf('edit_content_only') ? '_contentonly' : ''));

        require_once 'renderer_data_edit.php';
        $Renderer = new Doku_Renderer_plugin_data_edit();
        $Renderer->form = $event->data['form'];

        // Loop through the instructions
        $instructions = p_get_instructions($TEXT);
        foreach ( $instructions as $instruction ) {
            // Execute the callback against the Renderer
            call_user_func_array(array(&$Renderer, $instruction[0]),$instruction[1]);
        }
    }

    function _handle_edit_post($event) {
        if (!isset($_POST['data_edit'])) {
            return;
        }
        global $TEXT;

        require_once 'syntax/entry.php';
        $TEXT = syntax_plugin_data_entry::editToWiki($_POST['data_edit']);
    }

    function _handle_ajax($event) {
        if (strpos($event->data, 'data_page_') !== 0) {
            return;
        }
        $event->preventDefault();

        $type = substr($event->data, 10);
        $aliases = $this->dthlp->_aliases();
        if (!isset($aliases[$type])) {
            echo 'Unknown type';
            return;
        }
        if ($aliases[$type]['type'] !== 'page') {
            echo 'AutoCompletion is only supported for page types';
            return;
        }

        if (substr($aliases[$type]['postfix'], -1, 1) === ':') {
            // Resolve namespace start page ID
            global $conf;
            $aliases[$type]['postfix'] .= $conf['start'];
        }

        require_once(DOKU_INC.'inc/fulltext.php');
        $search = cleanID($_POST['search']);
        $pages = ft_pageLookup($search, false);
        $result = array();
        foreach ($pages as $page) {
            if (($aliases[$type]['prefix'] !== '' &&
                 stripos($page, $aliases[$type]['prefix']) !== 0) ||
                ($aliases[$type]['postfix'] !== '' &&
                 strripos($page, $aliases[$type]['postfix']) !== strlen($page) -
                  strlen($aliases[$type]['postfix']))) {
                continue;
            }

            $rtrim = -strlen($aliases[$type]['postfix']);
            if ($rtrim === 0) {
                // trimming with -0 gives the empty string, not the untrimmed
                // string
                $id = substr($page, strlen($aliases[$type]['prefix']));
            } else {
                $id = substr($page, strlen($aliases[$type]['prefix']), $rtrim);
            }

            if (useHeading('content')) {
                $heading = p_get_first_heading($page,true);
            } else {
                $heading = '';
            }

            if ($search !== '' &&
                (stripos($id, $search) === false &&
                stripos($heading, $search) === false) ||
                strpos($id, ':') !== false) {
                continue;
            }

            $id = utf8_ucwords(str_replace('_', ' ', $id));

            if ($heading === '') {
                $heading = $id;
            }
            $result[hsc($id)] = hsc($heading);
        }

        require_once DOKU_INC . 'inc/JSON.php';
        $json = new JSON();
        echo '(' . $json->encode($result) . ')';
    }
    
     /**
     * prepare the cache object for default _useCache action
     */
    function _cache_prepare(&$event, $param) {
        global $ID;
        global $conf;

        $cache =& $event->data;
        
        // we're only interested in instructions of the current page
        // without the ID check we'd get the cache objects for included pages as well
        if(!isset($cache->page) && ($cache->page != $ID)) return;
        if(!isset($cache->mode) || !in_array($cache->mode, $this->supportedModes)) return;
        
        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return;
        $cache->depends['files'][] = $sqlite->dbfile;
        
        // get additional depends
        $depends = p_get_metadata($ID, 'relation haspart');
        if(empty($depends)) return;
        
        
        $key = ''; 
        foreach(array_keys($depends) as $page) {
            if(strpos($page,'/') || cleanID($page) != $page) {
                continue;
            } else {
                $file = wikiFN($page);
                if(!in_array($cache->depends['files'], array($file)) && @file_exists($file)) {
                    $cache->depends['files'][] = $file;
                    $key .= '#' . $page . '|ACL' . auth_quickaclcheck($page);
                }
            }
        }

        // empty $key implies no includes, so nothing to do
        if(empty($key)) return;

        // mark the cache as being modified by the include plugin
        $cache->dataplugin = true;
        // set new cache key & cache name
        // now also dependent on included page ids and their ACL_READ status
        $cache->key .= $key;
        $cache->cache = getCacheName($cache->key, $cache->ext);
    }
}
