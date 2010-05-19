<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class syntax_plugin_data_loop extends DokuWiki_Syntax_Plugin {

    /**
     * will hold the data helper plugin
     */
    var $dthlp = null;

    /**
     * Constructor. Load helper plugin
     */
    function syntax_plugin_data_loop(){
        $this->dthlp =& plugin_load('helper', 'data');
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'container';
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
        $this->Lexer->addSpecialPattern('----+ *dataloop(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_data_loop');
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

        $data = array();
        $data['classes'] = $class;
        //$data['limit'] = 1000;

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
                    
                case 'looptemplate':
                      $data['looptemplate'] = cleanID($line[1]);
                  break;
                default:
                    msg("data plugin: unknown option '".hsc($line[0])."'",-1);
            }
        }
        
        $sFile = wikiFN($data['looptemplate']);
        if(!@file_exists($sFile))
        {
          
          $perm = auth_quickaclcheck($data['looptemplate']);
          $sCreate = '';
          $w = is_writable($sFile);
          if ($perm >= AUTH_EDIT) {
         
            $sCreate = ': <a href="'.wl($data['looptemplate'],array('do'=>'edit')).
            
                                '" title="'.'Create the template'.
                                '" class="" tagret="_blank">'.'Create the template'.'</a>'; 
          }
          
            msg('data plugin: looptemplate missing'.$sCreate,-1);
          
        }
        
        $sTpl = io_readFile($sFile);
        
        $aMatches = array();
        preg_match_all('/@@([^@]+)@@/', $sTpl, $aMatches);
        
        foreach($aMatches[1] as $iKey => $col)
        {
            $col = trim($col);
            if(!$col) continue;
            $column = $this->dthlp->_column($col);
            $column['markup'] = $aMatches[0][$iKey];
            $data['cols'][$column['key']] = $column;
            //$sTpl = str_replace($aMatches[0][$iKey], $iKey, $sTpl);
        }
  
        $data['tpl'] = $sTpl;
        
        //TODO get these out of the pagetemplate automatically\
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

        return $data;
    }

    /**
     * Create output
     */
    function render($format, &$R, $data)
    {
      $R->nocache();
      global $ID;
      if($format == 'metadata')
      {
        $R->meta['relation']['haspart'][$data['looptemplate']] = true;
        return true;
      }
      
      if(is_null($data)) return false;
      
      $sqlite = $this->dthlp->_getDB();
      if(!$sqlite) return false;

      #dbg($data);
      $sql = $this->_buildSQL($data); // handles request params, too
      #dbg($sql);

      // run query
      $clist = array_keys($data['cols']);
      $res = $sqlite->query($sql);
      
      // build loop
      $R->doc .= '<div class="inline dataplugin_loop '.$data['classes'].'">';
      
      $sOrg = $data['tpl']; 
      // build data rows
      $cnt = 0;
      while ($row = $sqlite->res_fetch_array($res))
      {
        $sChunk = $sOrg;
        foreach($row as $num => $cval)
        {
          $sMarkup = $data['cols'][$clist[$num]]['markup'];
          $vals = explode("\n", $cval);
          $outs = array();
          foreach($vals as $val)
          {
            $val = trim($val);
            if($val=='')
            {
              continue;
            }
            $outs[] = $val;// hsc($val);
          }
          $sValCommaed = join(', ',$outs);
          $sChunk = str_replace($sMarkup, $sValCommaed, $sChunk);
          
            
          // TODO : add different verision that uses an HTML template and the code below to insert html
          /*$sChunk = str_replace($sMarkup, $this->dthlp->_formatData(
                              $data['cols'][$clist[$num]],
                              $cval,$R), $sChunk);*/
        }
        //$R->doc .= $sChunk;
        $aInstructions = p_get_instructions($sChunk);
        $sXHTML = p_render($format, $aInstructions, $R->info);
        //$R->doc .= '<div class="dataplugin_loop_item">';
        $R->doc .= $sXHTML;
        //$R->doc .= '</div>';
        $cnt++;
        if($data['limit'] && ($cnt == $data['limit'])) break; // keep an eye on the limit
      }

      // if limit was set, add control
      if($data['limit']){
          $R->doc .= '<div>';
          $offset = (int) $_REQUEST['dataofs'];
          if($offset){
              $prev = $offset - $data['limit'];
              if($prev < 0) $prev = 0;

              // keep url params
              $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
              $params['datasrt'] = $_REQUEST['datasrt'];
              $params['dataofs'] = $prev;

              $R->doc .= '<a href="'.wl($ID,$params).
                            '" title="'.$this->getLang('prev').
                            '" class="prev">'.$this->getLang('prev').'</a>';
          }

          $R->doc .= '&nbsp;';

          if($sqlite->res2count($res) > $data['limit']){
              $next = $offset + $data['limit'];

              // keep url params
              $params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
              $params['datasrt'] = $_REQUEST['datasrt'];
              $params['dataofs'] = $next;

              $R->doc .= '<a href="'.wl($ID,$params).
                            '" title="'.$this->getLang('next').
                            '" class="next">'.$this->getLang('next').'</a>';
          }
          $R->doc .= '</div>';
      }
      
      $perm = auth_quickaclcheck($data['looptemplate']);
      if (($perm >= AUTH_EDIT) && (is_writable(wikiFN($data['looptemplate'])))) {
     
        $R->doc .= '<a href="'.wl($data['looptemplate'],array('do'=>'edit')).
                            '" title="'.'Edit the loop above'.
                            '" class="">'.'Edit the loop above'.'</a>'; 
      }
      $R->doc .= '</div>';
      

      return true;
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

        // take overrides from HTTP request params into account
        if($_REQUEST['datasrt']){
            if($_REQUEST['datasrt']{0} == '^'){
                $data['sort'] = array(substr($_REQUEST['datasrt'],1),'DESC');
            }else{
                $data['sort'] = array($_REQUEST['datasrt'],'ASC');
            }
        }

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
                if(!$tables[$key]){
                    $tables[$key] = 'T'.(++$cnt);
                    $from  .= ' LEFT JOIN data AS '.$tables[$key].' ON '.$tables[$key].'.pid = pages.pid';
                    $from  .= ' AND '.$tables[$key].".key = '".$sqlite->escape_string($key)."'";
                }
                switch ($col['type']) {
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
        if($data['sort'][0]){
            $col = $data['sort'][0];

            if($col == '%pageid%'){
                $order = 'ORDER BY pages.page '.$data['sort'][1];
            }elseif($col == '%class%'){ 
                $order = 'ORDER BY pages.class '.$data['sort'][1];
            }elseif($col == '%title%'){
                $order = 'ORDER BY pages.title '.$data['sort'][1];
            }elseif($col == '%pseudo%'){
              $day = date('j');
              $order = 'ORDER BY substr(ifnull(pages.title,"")||ifnull(pages.page),""), '.$day.'% length(ifnull(pages.title,"")||ifnull(pages.page)) '.$data['sort'][1];
            }else{
                // sort by hidden column?
                if(!$tables[$col]){
                    $tables[$col] = 'T'.(++$cnt);
                    $from  .= ' LEFT JOIN data AS '.$tables[$col].' ON '.$tables[$col].'.pid = pages.pid';
                    $from  .= ' AND '.$tables[$col].".key = '".$sqlite->escape_string($col)."'";
                }

                $order = 'ORDER BY '.$tables[$col].'.value '.$data['sort'][1];
            }
        }else{
            $order = 'ORDER BY 1 ASC';
        }

        // add request filters
        $data['filter'] = array_merge($data['filter'], $this->dthlp->_get_filters());
        $orginal_tables = $tables; //take a copy so that all filters need hidden columns generate new ones
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
                        $from  .= ' AND '.$tables[$col].".key = '".$sqlite->escape_string($col)."'";
                    }

                    $where .= ' '.$filter['logic'].' '.$tables[$col].'.value '.$filter['compare'].
                              " '".$filter['value']."'"; //value is already escaped
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

            if((int) $_REQUEST['dataofs']){
                $sql .= ' OFFSET '.((int) $_REQUEST['dataofs']);
            }
        }
        return $sql;
        
    }

}

