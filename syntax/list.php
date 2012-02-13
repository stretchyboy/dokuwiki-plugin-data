<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
require_once(dirname(__FILE__).'/table.php');

/**
 * This inherits from the table syntax, because it's basically the
 * same, just different output
 */
class syntax_plugin_data_list extends syntax_plugin_data_table {

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *datalist(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_data_list');
    }

    protected $before_item = '<li><div class="li">';
    protected $after_item  = '</div></li>';
    protected $before_val  = '';
    protected $after_val   = ' ';

    /**
     * Create output
     */
    function preList($clist, $data) {
        return '<div class="dataaggregation"><ul class="dataplugin_list '.$data['classes'].'">';
    }

/*merge conflict
// build list
        $cnt = 0;
        while ($row = $sqlite->res_fetch_array($res)) {
            $R->doc .= '<li><div class="li">';
            foreach($row as $num => $cval){
                $text .= $this->dthlp->_formatData($data['cols'][$clist[$num]],$cval,$R)."\n";
            }
            $text .= '</div></li>';
            $cnt++;
            if($data['limit'] && ($cnt == $data['limit'])) break; // keep an eye on the limit
        }
        if ($cnt === 0) {
            $R->doc .= '<p class="dataplugin_list '.$data['classes'].'">';
            $R->cdata($this->getLang('none'));
            $R->p_close();
            return;
        }
        */

    function nullList($data, $clist, &$R) {
        $R->doc .= '<div class="dataaggregation"><p class="dataplugin_list '.$data['classes'].'">';
        $R->cdata($this->getLang('none'));
        $R->doc .= '</p></div>';
    }

    function postList($data) {
        return '</ul></div>';
    }

}
