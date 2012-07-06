<?php
/**
 * A model for content stored in database.
 * 
 * @package LydiaCore
 */
class CMContent extends CObject implements IHasSQL, ArrayAccess, IModule, Iterator {

  /**
   * Properties
   */
  public $data;
  public $set;
  public $position;


  /**
   * Constructor
   */
  public function __construct($id=null) {
    parent::__construct();
    if($id) {
      $this->LoadById($id);
    } else {
      $this->data = array();
    }
  }


  /**
   * Implementing ArrayAccess for $this->data
   */
  public function offsetSet($offset, $value) { if (is_null($offset)) { $this->data[] = $value; } else { $this->data[$offset] = $value; }}
  public function offsetExists($offset) { return isset($this->data[$offset]); }
  public function offsetUnset($offset) { unset($this->data[$offset]); }
  public function offsetGet($offset) { return isset($this->data[$offset]) ? $this->data[$offset] : null; }


  /**
   * Implementing Iterator for $this->data and $this->set
   */
  function rewind() { $this->position = 0; }
  function current() { 
    $this->data = $this->set[$this->position];
    $this->Prepare();
    return $this;
  }
  function key() { return $this->position; }
  function next() { ++$this->position; }
  function valid() { return isset($this->set[$this->position]); }
  

  /**
   * Implementing interface IHasSQL. Encapsulate all SQL used by this class.
   *
   * @param $key string the string that is the key of the wanted SQL-entry in the array.
   * @args $args array with arguments to make the SQL queri more flexible.
   * @returns string.
   */
  public static function SQL($key=null, $args=null) {
    $order_order  = isset($args['order-order']) ? $args['order-order'] : 'ASC';
    $order_by     = isset($args['order-by'])    ? $args['order-by'] : 'id';    
    $queries = array(
      'table name content'        => "Content",
      'drop table content'        => "DROP TABLE IF EXISTS Content;",
      'create table content'      => "CREATE TABLE IF NOT EXISTS Content (id INTEGER PRIMARY KEY, key TEXT KEY, type TEXT, title TEXT, data TEXT, datafile TEXT default NULL, filter TEXT, idUser INT, created DATETIME default (datetime('now')), updated DATETIME default NULL, deleted DATETIME default NULL, FOREIGN KEY(idUser) REFERENCES User(id));",
      'export table content'      => 'SELECT * FROM Content;',
      //'schema create table'       => "SELECT sql FROM sqlite_master WHERE tbl_name = 'Content' AND type = 'table';",
      'insert content'            => 'INSERT INTO Content (key,type,title,data,datafile,filter,idUser) VALUES (?,?,?,?,?,?,?);',
      'select * by id'            => 'SELECT c.*, u.acronym as owner FROM Content AS c INNER JOIN User as u ON c.idUser=u.id WHERE c.id=? AND deleted IS NULL;',
      'select * by key'           => 'SELECT c.*, u.acronym as owner FROM Content AS c INNER JOIN User as u ON c.idUser=u.id WHERE c.key=? AND deleted IS NULL;',
      'select * by type'          => "SELECT c.*, u.acronym as owner FROM Content AS c INNER JOIN User as u ON c.idUser=u.id WHERE type=? AND deleted IS NULL ORDER BY {$order_by} {$order_order};",
      'select *'                  => 'SELECT c.*, u.acronym as owner FROM Content AS c INNER JOIN User as u ON c.idUser=u.id WHERE deleted IS NULL;',
      'flexible select *'         => 'SELECT c.*, u.acronym as owner FROM Content AS c INNER JOIN User as u ON c.idUser=u.id WHERE deleted IS NULL',
      'update content'            => "UPDATE Content SET key=?, type=?, title=?, data=?, datafile=?, filter=?, updated=datetime('now') WHERE id=?;",
      'update content as deleted' => "UPDATE Content SET deleted=datetime('now') WHERE id=?;",
     );
    if(!isset($queries[$key])) {
      throw new Exception("No such SQL query, key '$key' was not found.");
    }
    return $queries[$key];
  }


  /**
   * Implementing interface IModule. Manage install/update/deinstall and equal actions.
   */
  public function Manage($action=null) { require_once(__DIR__.'/CMContentModule.php'); $m = new CMContentModule(); return $m->Manage($action); }

 
  /**
   * Save content. If it has a id, use it to update current entry or else insert new entry.
   *
   * @returns boolean true if success else false.
   */
  public function Save() {
    $msg = null;
    if($this['id']) {
      $this->db->ExecuteQuery(self::SQL('update content'), array($this['key'], $this['type'], $this['title'], $this['data'], $this['datafile'], $this['filter'], $this['id']));
      $msg = 'update';
    } else {
      $this->db->ExecuteQuery(self::SQL('insert content'), array($this['key'], $this['type'], $this['title'], $this['data'], $this['datafile'], $this['filter'], $this->user['id']));
      $this['id'] = $this->db->LastInsertId();
      $msg = 'created';
    }
    $rowcount = $this->db->RowCount();
    if($rowcount) {
      $this->AddMessage('success', "Successfully {$msg} content '" . htmlEnt($this['key']) . "'.");
    } else {
      $this->AddMessage('error', "Failed to {$msg} content '" . htmlEnt($this['key']) . "'.");
    }
    return $rowcount === 1;
  }
    

  /**
   * Delete content. Set its deletion-date to enable wastebasket functionality.
   *
   * @returns boolean true if success else false.
   */
  public function Delete() {
    if($this['id']) {
      $this->db->ExecuteQuery(self::SQL('update content as deleted'), array($this['id']));
    }
    $rowcount = $this->db->RowCount();
    if($rowcount) {
      $this->AddMessage('success', "Successfully set content '" . htmlEnt($this['key']) . "' as deleted.");
    } else {
      $this->AddMessage('error', "Failed to set content '" . htmlEnt($this['key']) . "' as deleted.");
    }
    return $rowcount === 1;
  }
    

  /**
   * Load content by id.
   *
   * @param $id integer the id of the content.
   * @returns boolean true if success else false.
   */
  public function LoadById($id) {
    $res = $this->db->ExecuteSelectQueryAndFetchAll(self::SQL('select * by id'), array($id));
    if(empty($res)) {
      $this->AddMessage('error', "Failed to load content with id '$id'.");
      return false;
    } else {
      $this->data = $res[0];
    }
    return true;
  }
  
  
  /**
   * List all content.
   *
   * @param $args array with various settings for the request. Default is null.
   * @returns array with listing or null if empty.
   */
  public function ListAll($args=null) {    
    try {
      if(isset($args) && isset($args['type'])) {
        return $this->db->ExecuteSelectQueryAndFetchAll(self::SQL('select * by type', $args), array($args['type']));
      } else {
        return $this->db->ExecuteSelectQueryAndFetchAll(self::SQL('select *', $args));
      }
    } catch(Exception $e) {
      echo $e;
      return null;
    }
  }
  
  /**
   * List all content as specified in array, build custom SQL-query.
   *
   * @param array $options with various settings for the request.
   * @returns array with listing or null if empty.
   */
  public function GetEntries($options=array()) {
    $default = array(
      'type' => null,
      'order_by' => null,
      'order_order' => null,
      'limit' => 7,
    );
    $options = array_merge($default, $options);
    $sqlArgs = array();
    
    $type = empty($options['type']) ? null : " AND type = ?";
    if($type) $sqlArgs[] = $options['type'];
    
    $limit = empty($options['limit']) ? null : " LIMIT ?";
    if($limit) $sqlArgs[] = $options['limit'];
    
    $order_by = empty($options['order_by']) ? null : " ORDER BY {$options['order_by']}";
    
    $order_order = null;
    if(isset($options['order_order'])) {
      if(in_array(strtolower($options['order_order']), array('asc', 'desc'))) {
        $order_order = " {$options['order_order']}";
      } else {
        throw new Exception(t('Not valid group by order.'));
      }
    }
    $this->position = 0;
    $this->set = $this->db->ExecuteSelectQueryAndFetchAll(self::SQL('flexible select *').$type.$order_by.$order_order.$limit, $sqlArgs);
    return $this;
  }
  

  /**
   * Get a list of supported textfilters.
   *
   * @returns array with list of supported filters.
   */
  public static function SupportedFilters() {
    return array(
      'plain' => t('Convert http://webb.com/ to clickable links. Convert newline to <br />.'),
      'bbcode' => t('Support bbcode. Convert newline to <br />.'),
      'htmlpurify' => t('Treat data as HTML and use HTMLPurify to filter content. Convert newline to <br />.'),
      'markdown' => t('Support Markdown-syntax together with Typographer (SmartyPants).'),
      'markdownx' => t('Support extended Markdown-syntax together with Typographer (SmartyPants). Converts links, shorttags'),
    );
  }
  
  
  /**
   * Filter content according to a filter.
   *
   * @param $data string of text to filter and format according its filter settings.
   * @returns string with the filtered data.
   */
  public static function Filter($data, $filter) {
    switch($filter) {
      /*case 'php': $data = nl2br(makeClickable(eval('?>'.$data))); break;
      case 'html': $data = nl2br(makeClickable($data)); break;*/
      case 'markdownx': $data = CTextFilter::MakeClickable(CTextFilter::Typographer(CTextFilter::MarkdownExtra(CTextFilter::ShortTags($data)))); break;
      case 'markdown': $data = CTextFilter::Typographer(CTextFilter::Markdown($data)); break;
      case 'htmlpurify': $data = nl2br(CTextFilter::Purify($data)); break;
      case 'bbcode': $data = nl2br(CTextFilter::Bbcode2HTML(htmlEnt($data))); break;
      case 'plain': 
      default: $data = nl2br(CTextFilter::MakeClickable(htmlEnt($data))); break;
    }
    return $data;
  }
  
  
  /**
   * Get the filtered content.
   *
   * @returns string with the filtered data.
   */
  public function GetFilteredData() {
    $data = null;
    if($this['datafile']) {
      $data = "\n".file_get_contents(LYDIA_SITE_PATH.'/data/'.get_class().'/txt/'.$this['datafile']);
    }
    $this->data['data_filtered'] = $this->Filter($this['data'] . $data, $this['filter']);
    return $this->data['data_filtered'];
  }
  
  
  /**
   * Get the TOC of headings to a certain level.
   *
   * @param integer $level which level of headings to use for toc.
   * @returns array with entries to generate a TOC.
   */
  public function GetTableOfContent($level=4) {
  	$pattern = '/<(h[2-'.$level.'])([^>]*)>(.*)<\/h[2-'.$level.']>/';
  	preg_match_all($pattern, $this->data['data_filtered'], $matches, PREG_SET_ORDER);
    $this->data['toc'] = array();
    $this->data['toc_formatted'] = null;
    foreach($matches as $val) {
      preg_match('/id=[\'"]([^>"\']+)/', $val[2], $id);
      $id = isset($id[1]) ? $id[1] : null;
      $this->data['toc'][] = array('level' => (isset($matches[1]) ? $matches[1] : null), 'id' => $id, 'label' => (isset($val[3]) ? $val[3] : null));
      $a1 = $id ? "<a href='#{$id}'>" : null;
      $a2 = $id ? "</a>" : null;
      $this->data['toc_formatted'] .= "<li class='{$val[1]}'>{$a1}{$val[3]}{$a2}</li>\n";
    }
    $this->data['toc_formatted'] = "<ul>\n" . $this->data['toc_formatted'] . "</ul>\n";
    return $this->data['toc'];
  }
  
  
  /**
   * Prepare all data before sending to view, stores all prepared data in object, easy for views to access.
   */
  public function Prepare() {
    $this->GetFilteredData();
    $this->GetTableOfContent();
  }
  
  
}
