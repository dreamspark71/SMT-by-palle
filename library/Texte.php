<?php  /**   * Sprachen & Texte   *    * @author    Werner Pallentin <werner.pallentin@outlook.de>   * @package   SMT   */class Texte extends Language {  public function __construct() {        if(preg_match('/language/', filter_input(INPUT_SERVER, 'REQUEST_URI'))) {      parent::setLanguage(substr(filter_input(INPUT_SERVER, 'REQUEST_URI'), -2));      header("Location: " . filter_input(INPUT_SERVER, 'HTTP_REFERER'));    }    self::getSprachen();    $this->loadTexte();  }  /**   * Methode zum auslesen von einer Textvariablen   * @param string $sName    */  public static function getText($sName) {    $session = Session::getInstance();    $tabelle = "wos_language_".$session->get('language');        $query = "SELECT * FROM $tabelle WHERE text_name=:name";    $value = array(':name'=>$sName);    $db = new Database('SMT-ADMIN');    $db->getQuery($query, $value);    if ($db->getNumrows() == 1) {      return $db->getValue('text_value', 0);    }  }  /**   * Methode zum auslesen aller Systemtexte   * und den Texten zum aktuellen Controller   */  public function loadTexte() {    $session = Session::getInstance();    $tabelle = "wos_language_".$session->get('language');    $text = array();    $db = new Database('SMT-ADMIN');    $db->getQuery("SELECT * FROM $tabelle", array());    for ($i = 0; $i < $db->getNumrows(); $i++) {      $text[$db->getValue('text_name', $i)] = $db->getValue('text_value', $i);    }    Template::setText('text', $text);  }    /**   * Methode zum auslesen aller Systemtexte   * und den Texten zum aktuellen Controller   */  public static function loadAdminTexte($language) {    $session = Session::getInstance();    $tabelle = "wos_language_".$language;        $db = new Database('SMT-ADMIN');    return $db->getQuery("SELECT * FROM $tabelle ORDER BY art DESC,text_name", array(), True);  }    public static function saveTexte($post) {    $db = new Database('SMT-ADMIN');    $tabelle = "wos_language_".$post['sprache'];        foreach ($post as $key => $value) {      $query = "UPDATE $tabelle SET text_value=:value WHERE id=:key";          $value = array(':value' => $value, ':key' => $key);              $db->getQuery($query, $value);     }  }    public static function insertText($post) {        $session = Session::getInstance();    $sprachen = $session->get('sprachen');        $db = new Database('SMT-ADMIN');          $query = "INSERT INTO wos_language_de (text_name, text_value) VALUES (:name,:value)";        $value = array(':name' => $post['text_name'], ':value' => $post['text_value']);          $db->getQuery($query, $value);    $id = $db->getLastID();        for($i=0; $i<count($sprachen); $i++) {      $db = new Database('SMT-ADMIN');            $query = "INSERT INTO wos_language_".$sprachen[$i]['id']." (id, text_name, text_value) VALUES (:id,:name,:value)";          $value = array(':id'=>$id, ':name' => $post['text_name'], ':value' => $post['text_value']);            if($sprachen[$i]['id'] != 'de') {        $db->getQuery($query, $value);       }    }  }    public static function getSprachen() {    $session = Session::getInstance();        $db = new Database('SMT-ADMIN');    $db->getQuery("SELECT * FROM wos_international WHERE aktiv=:aktiv ORDER BY id", array(':aktiv'=>'ja'));    $session->set('sprachen', $db->getValue());        $db->getQuery("SELECT * FROM wos_international WHERE aktiv=:aktiv ORDER BY id", array(':aktiv'=>'nein'));    return $db->getValue();  }    public static function activateLanguage($lang) {    /**     * neue Tabelle für die Sprache anlegen     */    $db = new Database('SMT-ADMIN');    $db->getQuery("CREATE TABLE wos_language_".$lang." "            . "(id INT(11) NOT NULL , "            . "sprache CHAR(3) NOT NULL , "            . "text_name CHAR(60) NOT NULL , "            . "text_value TEXT NOT NULL , "            . "art ENUM('sys','usr','not') "            . "NOT NULL DEFAULT 'sys' ) "            . "ENGINE = InnoDB", array());        /**     * Datenbank mit den Vorlagen füllen     */    $db = new Database('SMT-ADMIN');    $vorlage = $db->getQuery("SELECT * FROM wos_language_de", array(), true);    $db->insertMultiple("wos_language_".$lang, $vorlage);           /**     * Sprache nun aktivieren     */    $db = new Database('SMT-ADMIN');    $db->getQuery("UPDATE wos_international set aktiv=:aktiv WHERE id=:id", array(':aktiv'=>'ja', ':id'=>$lang));  }      /**     * Einen Eintrag löschen     * @param type $id     */    public static function deleteText($id) {      $session = Session::getInstance();      $sprachen = $session->get('sprachen');      for($i=0; $i<count($sprachen); $i++) {        $db = new Database('SMT-ADMIN');        $db->getQuery("DELETE FROM wos_language_".$sprachen[$i]['id']." WHERE id=:id", array(':id'=>$id));      }    }}?>