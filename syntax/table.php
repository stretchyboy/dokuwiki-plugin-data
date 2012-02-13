<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class syntax_plugin_data_table extends DokuWiki_Syntax_Plugin {

    /**
     * will hold the data helper plugin
     */
    var $dthlp = null;

    /**
     * Constructor. Load helper plugin
     */
    function syntax_plugin_data_table(){
        $this->dthlp =& plugin_load('helper', 'data');
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 155;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *datatable(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_data_table');
    }


    /**
     * Handle the match - parse the data
     *
     * This parsing is shared between the multiple different output/control
     * syntaxes
     */
    function handle($match, $state, $pos, &$handler){
      
        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;
        
        // get lines and additional class
        $lines = explode("\n",$match);
        array_pop($lines);
        $class = array_shift($lines);
        $class = preg_replace('/^----+ *data[a-z]+/','',$class);
        $class = trim($class,'- ');

        $data = array('classes' => $class,
                      'limit'   => 0,
                      'headers' => array());

        // parse info
        foreach ( $lines as $line ) {
            // ignore comments
            $line = preg_replace('/(?<![&\\\\])#.*$/','',$line);
            $line = str_replace('\\#','#',$line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/',$line,2);
            $line[0] = strtolower($line[0]);

            $logic = 'OR';
            // handle line commands (we allow various aliases here)
            switch($line[0]){
                case 'select':
                case 'cols':
                case 'field':
                case 'col':
                        $cols = explode(',',$line[1]);
                        foreach($cols as $col){
                            $col = trim($col);
                            if(!$col) continue;
                            $column = $this->dthlp->_column($col);
                            $data['cols'][$column['key']] = $column;
                        }
                    break;
                case 'title':
                        $data['title'] = $line[1];
                    break;
                case 'head':
                case 'header':
                case 'headers':
                        $cols = explode(',',$line[1]);
                        foreach($cols as $col){
                            $col = trim($col);
                            $data['headers'][] = $col;
                        }
                    break;
                case 'min':
                        $data['min']   = abs((int) $line[1]);
                    break;
                case 'limit':
                case 'max':
                        $data['limit'] = abs((int) $line[1]);
                    break;
                case 'order':
                case 'sort':
                        $column = $this->dthlp->_column($line[1]);
                        $sort = $column['key'];
                        if(substr($sort,0,1) == '^'){
                            $data['sort'] = array(substr($sort,1),'DESC');
                        }else{
                            $data['sort'] = array($sort,'ASC');
                        }
                    break;
                case 'where':
                case 'filter':
                case 'filterand':
                case 'and':
                        $logic = 'AND';
                case 'filteror':
                case 'or':
                        if(!$logic) $logic = 'OR';
                        $flt = $this->dthlp->_parse_filter($line[1]);
                        if(is_array($flt)){
                            $flt['logic'] = $logic;
                            $data['filter'][] = $flt;
                        }
                    break;
                case 'page':
                case 'target':
                        $data['page'] = cleanID($line[1]);
                    break;
                default:
                    msg("data plugin: unknown option '".hsc($line[0])."'",-1);
            }
        }

        // we need at least one column to display
        if(!is_array($data['cols']) || !count($data['cols'])){
            msg('data plugin: no columns selected',-1);
            return null;
        }

        // fill up headers with field names if necessary
        $data['headers'] = (array) $data['headers'];
        $cnth = count($data['headers']);
        $cntf = count($data['cols']);
        for($i=$cnth; $i<$cntf; $i++){
            $item = array_pop(array_slice($data['cols'],$i,1));
            $data['headers'][] = $item['title'];
        }

        $data['sql'] = $this->_buildSQL($data);
        return $data;
    }

    protected $before_item = '<tr>';
    protected $after_item  = '</tr>';
    protected $before_val  = '<td>';
    protected $after_val   = '</td>';

    /**
     * Create output
     */
    function render($format, &$R, $data) {
        if($format != 'xhtml') return false;
        if(is_null($data)) return false;
        $R->info['cache'] = false;

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        $this->updateSQLwithQuery($data); // handles request params

        // run query
        $clist = array_keys($data['cols']);
        $res = $sqlite->query($data['sql']);

        $rows = $sqlite->res2arr($res);
        $cnt = count($rows);

        if ($cnt === 0) {
            $this->nullList($data, $clist, $R);
            return true;
        }

        if ($data['limit'] && $cnt > $data['limit']) {
            $rows = array_slice($rows, 0, $data['limit']);
        }

        $R->doc .= $this->preList($clist, $data);
        foreach ($rows as $row) {
            // build data rows
            $R->doc .= $this->before_item;
            foreach(array_values($row) as $num => $cval){
                $R->doc .= $this->before_val;
                $R->doc .= $this->dthlp->_formatData(
                                $data['cols'][$clist[$num]],
                                $cval,$R);
                $R->doc .= $this->after_val;
            }
            $R->doc .= $this->after_item;
        }
        $R->doc .= $this->postList($data, $cnt);

        return true;
    }

    function preList($clist, $data) {
        global $ID;
        // build table
        $text = '<div class="table dataaggregation">'
              . '<table class="inline dataplugin_table '.$data['classes'].'">';
        // build column headers
        $text .= '<tr>';
        foreach($data['headers'] as $num => $head){
            $ckey = $clist[$num];

            $text .= '<th>';

            // add sort arrow
            if(isset($data['sort']) && $ckey == $data['sort'][0]){
                if($data['sort'][1] == 'ASC'){
                    $text .= '<span>&darr;</span> ';
                    $ckey = '^'.$ckey;
                }else{
                    $text .= '<span>&uarr;</span> ';
                }
            }

            // keep url params
            $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
            $params['datasrt'] = $ckey;
            $params['dataofs'] = $_REQUEST['dataofs'];

            // clickable header
            $text .= '<a href="'.wl($ID,$params).
                       '" title="'.$this->getLang('sort').'">'.hsc($head).'</a>';

        /* merge conflict
            $R->doc .= '</th>';
        }
        $R->doc .= '</tr>';

        // build data rows
        $cnt = 0;
        while ($row = $sqlite->res_fetch_array($res)) {
            $R->doc .= '<tr>';
            foreach($row as $num => $cval){
                $R->doc .= '<td>';
                $R->doc .= $this->dthlp->_formatData(
                                $data['cols'][$clist[$num]],
                                $cval,$R);
                $R->doc .= '</td>';
            }
            $R->doc .= '</tr>';
            $cnt++;
            if($data['limit'] && ($cnt == $data['limit'])) break; // keep an eye on the limit
            */
            $text .= '</th>';
        }
        $text .= '</tr>';
        return $text;
    }

    function nullList($data, $clist, &$R) {
        $R->doc .= $this->preList($clist, $data);
        $R->tablerow_open();
        $R->tablecell_open(count($clist), 'center');
        $R->cdata($this->getLang('none'));
        $R->tablecell_close();
        $R->tablerow_close();
        $R->doc .= '</table></div>';
    }

    function postList($data, $rowcnt) {
        global $ID;
        $text = '';
        // if limit was set, add control
        if($data['limit']){
            $text .= '<tr><th colspan="'.count($data['cols']).'">';
            $offset = (int) $_REQUEST['dataofs'];
            if($offset){
                $prev = $offset - $data['limit'];
                if($prev < 0) $prev = 0;

                // keep url params
                $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
                $params['datasrt'] = $_REQUEST['datasrt'];
                $params['dataofs'] = $prev;

                $text .= '<a href="'.wl($ID,$params).
                              '" title="'.$this->getLang('prev').
                              '" class="prev">'.$this->getLang('prev').'</a>';
            }

            $text .= '&nbsp;';

/* merge conflict
            if($sqlite->res2count($res) > $data['limit']){
*/
            if($rowcnt > $data['limit']){
                $next = $offset + $data['limit'];

                // keep url params
                $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
                $params['datasrt'] = $_REQUEST['datasrt'];
                $params['dataofs'] = $next;

                $text .= '<a href="'.wl($ID,$params).
                              '" title="'.$this->getLang('next').
                              '" class="next">'.$this->getLang('next').'</a>';
            }
            $text .= '</th></tr>';
        }

        $text .= '</table></div>';
        return $text;
    }

    /**
     * Builds the SQL query from the given data
     */
    function _buildSQL(&$data){
      
        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;
        
        $cnt    = 0;
        $tables = array();
        $select = array();
        $from   = '';
        $where  = '1 = 1';
        $order  = '';

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        // prepare the columns to show
        foreach ($data['cols'] as &$col){
            $key = $col['key'];
            if($key == '%pageid%'){
                // Prevent stripping of trailing zeros by forcing a CAST
                $select[] = '" " || pages.page';
            }elseif($key == '%class%'){
                // Prevent stripping of trailing zeros by forcing a CAST
                $select[] = '" " || pages.class';
            }elseif($key == '%title%'){
                $select[] = "pages.page || '|' || pages.title";
            }else{
                if(!isset($tables[$key])){
                    $tables[$key] = 'T'.(++$cnt);
                    $from  .= ' LEFT JOIN data AS '.$tables[$key].' ON '.$tables[$key].'.pid = pages.pid';
                    $from  .= ' AND '.$tables[$key].".key = ".$sqlite->quote_string($key);
                }
                $type = $col['type'];
                if (is_array($type)) $type = $type['type'];
                switch ($type) {
                case 'pageid':
                    $select[] = "pages.page || '|' || group_concat(".$tables[$key].".value,'\n')";
                    $col['type'] = 'title';
                    break;
                case 'wiki':
                    $select[] = "pages.page || '|' || group_concat(".$tables[$key].".value,'\n')";
                    break;
                default:
                    // Prevent stripping of trailing zeros by forcing a CAST
                    $select[] = 'group_concat(" " || '.$tables[$key].".value,'\n')";
                }
            }
        }
        unset($col);

        // prepare sorting
        if(isset($data['sort'])){
            $col = $data['sort'][0];

            if($col == '%pageid%'){
                $order = 'ORDER BY pages.page '.$data['sort'][1];
            }elseif($col == '%class%'){
                $order = 'ORDER BY pages.class '.$data['sort'][1];
            }elseif($col == '%title%'){
                $order = 'ORDER BY pages.title '.$data['sort'][1];
            }else{
                // sort by hidden column?
                if(!$tables[$col]){
                    $tables[$col] = 'T'.(++$cnt);
                    $from  .= ' LEFT JOIN data AS '.$tables[$col].' ON '.$tables[$col].'.pid = pages.pid';
                    $from  .= ' AND '.$tables[$col].".key = " . $sqlite->quote_string($col);
                }

                $order = 'ORDER BY '.$tables[$col].'.value '.$data['sort'][1];
            }
        }else{
            $order = 'ORDER BY 1 ASC';
        }

        // add request filters
        if (!isset($data['filter'])) $data['filter'] = array();
        $data['filter'] = array_merge($data['filter'], $this->dthlp->_get_filters());
        $orginal_tables = $tables;
        // prepare filters
        if(is_array($data['filter']) && count($data['filter'])){

            foreach($data['filter'] as $filter){
                $col = $filter['key'];

                if($col == '%pageid%'){
                    $where .= " ".$filter['logic']." pages.page ".$filter['compare']." '".$filter['value']."'";
                }elseif($col == '%class%'){
                    $where .= " ".$filter['logic']." pages.class ".$filter['compare']." '".$filter['value']."'";
                }elseif($col == '%title%'){
                    $where .= " ".$filter['logic']." pages.title ".$filter['compare']." '".$filter['value']."'";
                }else{
                    // filter by hidden column?
                    if(!$orginal_tables[$col]){
                        $tables[$col] = 'T'.(++$cnt);
                        $from  .= ' LEFT JOIN data AS '.$tables[$col].' ON '.$tables[$col].'.pid = pages.pid';
                        $from  .= ' AND '.$tables[$col].".key = " . $sqlite->quote_string($col);
                    }

                    $where .= ' '.$filter['logic'].' '.$tables[$col].'.value '.$filter['compare'].
                              " '".$filter['value']."'"; //value is already escaped
                    unset($orginal_tables[$col]);
                }
            }
        }

        // build the query
        $sql = "SELECT DISTINCT ".join(', ',$select)."
                  FROM pages $from
                 WHERE $where
              GROUP BY pages.page
                $order";

        // offset and limit
        if($data['limit']){
            $sql .= ' LIMIT '.($data['limit'] + 1);
            // offset is added from REQUEST params in updateSQLwithQuery
        }

        return $sql;
    }

    function updateSQLwithQuery(&$data) {
        // take overrides from HTTP request params into account
        if(isset($_REQUEST['datasrt'])){
            if($_REQUEST['datasrt']{0} == '^'){
                $data['sort'] = array(substr($_REQUEST['datasrt'],1),'DESC');
            }else{
                $data['sort'] = array($_REQUEST['datasrt'],'ASC');
            }
            // Rebuild SQL FIXME do this smarter & faster
            $data['sql'] = $this->_buildSQL($data);
        }

        if($data['limit'] && (int) $_REQUEST['dataofs']){
            $data['sql'] .= ' OFFSET '.((int) $_REQUEST['dataofs']);
        }
    }
}

