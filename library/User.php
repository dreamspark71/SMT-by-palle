<?php

/**
 * Klasse zur Verbindung und zum Abruf der Methoden via Soap
 * 
 * @author    Werner Pallentin <werner.pallentin@outlook.de>
 * @package   SMT
 */
class User {

  const USERNAME_BLOCKED = 'root,webmaster,postmaster,admin,Administrator';
  const USERNAME_MIN_LENGTH = 4;
  const USERNAME_MAX_LENGTH = 12;
  const PASSWORD_MIN_LENGTH = 6;
  const PASSWORD_MAX_LENGTH = 8;
  const NAME_MIN_LENGTH = 3;

  public $auth;
  public $allowed;

  public function __construct($conf) {
    $session = Session::getInstance();
    $this->auth = $conf['authentication'];
    $this->allowed = explode(',', $conf['allowed_controller_without_pass']);
    $mucheck = True;

    for ($i = 0; $i < count($this->allowed); $i++) {
      if (strstr(filter_input(INPUT_SERVER, 'REQUEST_URI'), $this->allowed[$i])) {
        $mucheck = False;
      }
    }
    
    if(!$this->isLogged() && $mucheck && !preg_match('/login/', filter_input(INPUT_SERVER, 'QUERY_STRING'))) {
      $session->set('redirect', filter_input(INPUT_SERVER, 'REQUEST_URI'));
      header("Location: /user/login");
    }
  }

  /**
   * Der Benutzer wird eingeloggt
   */
  public function loginUser($sUser, $sPass, $conf) {
    // Sessionverbindung herstellen
    $session = Session::getInstance();
    // Datenbankverbindung herstellen
    $db = new Database('SMT-USER');
    
    if($this->auth == 'intern') {
      // Prüfen ob es den Benutzer gibt
      $query = "SELECT * FROM db_user,db_user_contact WHERE db_user.username=db_user_contact.username && db_user_contact.email=:username && db_user.password=:password && db_user.status=:status";
      $value = array(':username' => $sUser, ':password' => md5($sPass), ':status' => 'on');

      $db->getQuery($query, $value);

      if ($db->getNumrows() == 0) {
        // Prüfen ob es einen Auth Code gibt
        $query = "SELECT * FROM db_user_secure,db_user_contact WHERE db_user_secure.username=db_user_contact.username && db_user_contact.email=:username && db_user_secure.authCode=:password && db_user_secure.lastAuthCode<=:datum";
        $value = array(':username' => $sUser, ':password' => $sPass, ':datum' => date("Y.m.d"));

        $db->getQuery($query, $value);
      }

      // Benutzernamen und Rechte in die Session schreiben
      if ($db->getNumrows() == 1) {
        // Zwischenspeichern das der Login via AuthCode erfolgte
        if (preg_match("/secure/i", $query)) {
          $session->set('authCode', True);
        }

        $session->set('username', $db->getValue('username', 0));
        $session->set('rechte', $db->getValue('rechte'), 0);
        
        if($session->get('rechte') == 'adm') { 
          $_SESSION['admin'] = True;
        }

        $query = "UPDATE db_user_secure SET countLogin=countLogin+1 WHERE username=:username";
        $value = array(':username' => $session->get('username'));
        $db->getQuery($query, $value);

        return True;
      } else {
        return False;
      }
    }
    
    if($this->auth == 'ldap') {
      $ldap = new LDAP($sUser, $sPass, $conf);
      
      if($ldap->isAuth) {        
        $ldap->checkUser($sUser);        
        // Usernamen aus der SMT Benutzerdatenbank holen
        $db->getQuery("SELECT username FROM db_user_config WHERE value=:username && name=:name LIMIT 1", array(':username' => $sUser, ':name'=>'ldap_auth'));
        
        if($db->getNumrows() > 0) {
          $session->set('username', $db->getValue('username', 0));
        }
        
        if($db->getNumrows() == 0) {
          $ldapValue = $ldap->ldapSearch(array('givenName','sn','company','mail','displayName'), $sUser);
          
          $aPost['private']['firstname']   = $ldapValue['givenname']['0'];
          $aPost['private']['lastname']    = $ldapValue['sn']['0'];
          $aPost['private']['displayName'] = $ldapValue['displayname']['0'];
          $aPost['private']['company']     = $ldapValue['company']['0'];
          $aPost['contact']['email']       = $ldapValue['mail']['0'];
          
          $session->set('username', $this->createUser($aPost));
          $db->getQuery("INSERT INTO db_user_config (username, name, value) VALUES (:username,:name,:value)", array(':username'=>$this->getUsername(), ':value' => $sUser, ':name'=>'ldap_auth'));
        }            
        
        return True;
      } else {
        return False;
      }
    }
  }

  /**
   * Methode zum ausloggen eines Benutzers
   */
  public function logoutUser() {
    // Sessionverbindung herstellen
    $session = Session::getInstance();

    unset($session->logged);
    unset($session->username);
    unset($session->rechte);
    unset($session->status);
  }

  /**
   * Methode zum einlesen aller Daten zum Benutzer
   */
  public function getUser($sUser = '') {
    if (empty($sUser)) {
      $sUser = $this->getUsername();
    }
    
    // Benutzerarray vorbereiten
    $aUser = array();
    $value = array(':username' => $sUser);

    $db = new Database('SMT-USER');
    // Array mit den Daten zum Benutzer füllen
    $aUser['user'] = $db->getQuery("SELECT * FROM db_user WHERE username=:username", $value, True);
    $aUser['private'] = $db->getQuery("SELECT * FROM db_user_private WHERE username=:username", $value, True);
    $aUser['contact'] = $db->getQuery("SELECT * FROM db_user_contact WHERE username=:username", $value, True);
    $aUser['secure'] = $db->getQuery("SELECT * FROM db_user_secure WHERE username=:username", $value, True);
    $aUser['config'] = $db->getQuery("SELECT * FROM db_user_config WHERE username=:username", $value, True);
    $aUser['favorite'] = $db->getQuery("SELECT * FROM db_user_favorite WHERE username=:username", $value, True);

    // Array mit den Daten zum Benutzer füllen
    $aUser['user'] = $aUser['user']['0'];
    $aUser['private'] = $aUser['private']['0'];
    $aUser['contact'] = $aUser['contact']['0'];
    $aUser['secure'] = $aUser['secure']['0'];

    return $aUser;
  }

  /**
   * Die Sicherheitstufe einstellen
   *
   * @param type $management
   */
  public function setSecurity($management) {
    $session = Session::getInstance();

    if (!$this->isLogged()) {
      die(header("Location: " . base::get('getPath') . '/user/login'));
    }

    if ($session->get('authCode')) {
      die(header("Location: " . base::get('getPath') . '/user/changePassword'));
    }

    $_SESSION['Security'] = $management;
  }

  /**
   * Prüft ob der User eingeloggt ist
   *
   * @return bool
   */
  public function isLogged() {
    $session = Session::getInstance();
    if (isset($_SESSION['username']) && $_SESSION['username'] != '') {
      return TRUE;
    } else {
      return False;
    }
  }

  /**
   * Gibt den Benutzernamen oder die Session ID zurück
   *
   * @return string
   */
  public function getUsername() {
    if ($this->isLogged()) {
      $session = Session::getInstance();
      return $session->get('username');
    } else {
      return session_id();
    }
  }

  public function createAuthcode() {
    $database = new Database('SMT-USER');
    $authCode = base::createCode();

    $query = "UPDATE db_user_secure SET authCode=:authCode, lastAuthCode=:datum WHERE username=:username";
    $value = array(':authCode' => $authCode, ':lastAuthCode' => date("Y-m-d H:i:s"), ':username' => $this->getUsername());

    $database->getQuery($query, $value);
    return $authCode;
  }

  /**
   * Methode zum erzeugen eines eindeutigen Benutzernamen
   *
   * @staticvar string $guid
   * @return string
   */
  public function createUsername() {
    static $guid = '';
    $data = '';
    $uid = uniqid("", true);

    $data .= filter_input(INPUT_SERVER, 'REQUEST_TIME');
    $data .= filter_input(INPUT_SERVER, 'HTTP_USER_AGENT');
    $data .= filter_input(INPUT_SERVER, 'REMOTE_ADDR');
    $data .= filter_input(INPUT_SERVER, 'REMOTE_PORT');
    $hash = strtoupper(hash('ripemd128', $uid . $guid . md5($data)));
    $guid = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);

    return $guid;
  }

  /**
   * Benutzerdaten in den Standardtabellen aktualisieren
   *
   * @param array $aPost
   * @param string $sUsername
   * @param string $sDatabase
   */
  public function saveUserdata($aPost, $sUsername = '') {
    $db = new Database('SMT-USER');
    
    if (empty($sUsername)) {
      $sUsername = $this->getUsername();
    }

    $aData = array();

    $query_private = '';
    $query_contact = '';

    $aData['private'][':username'] = $sUsername;
    $aData['contact'][':username'] = $sUsername;

    foreach ($aPost['private'] AS $name => $value) {
      $aData['private'][':' . $name] = $value;
      $query_private .= $name . '=:' . $name . ',';
    }

    foreach ($aPost['contact'] AS $name => $value) {
      $aData['contact'][':' . $name] = $value;
      $query_contact .= $name . '=:' . $name . ',';
    }

    $query_private = "UPDATE db_user_private SET " . substr($query_private, 0, -1) . " WHERE username=:username";
    $value_private = $aData['private'];

    $query_contact = "UPDATE db_user_contact SET " . substr($query_contact, 0, -1) . " WHERE username=:username";
    $value_contact = $aData['contact'];

    $db->getQuery($query_private, $value_private, False);
    $db->getQuery($query_contact, $value_contact, False);

    if (isset($aPost['user']['password']) && !empty($aPost['user']['password'])) {
      $this->changePasswort($aPost['user']['password'], $sUsername);
    }
    return $sUsername;
  }

  /**
   * Passwort des Benutzers ändern und per md5 verschlüsseln
   *
   * @param string $sPasswort
   * @param string $sUsername
   * @param string $sDatabase
   */
  public function changePasswort($sPasswort, $sUsername) {
    $db = new Database('SMT-USER');

    $query_user = "UPDATE db_user SET password=:password WHERE username=:username";
    $value_user = array(':username' => $sUsername, ':password' => md5($sPasswort));

    $db->getQuery($query_user, $value_user, False);
  }

  /**
   * Neuen Benutzer in den Standardtabellen anlegen
   *
   * @param array $aPost
   * @param string $sDatabase
   */
  public function createUser($aPost) {
    $username = $this->createUsername();
    $value = array(':username' => $username);
    $db = new Database('SMT-USER');

    $query_user = "INSERT INTO db_user (username) VALUES (:username)";
    $query_secure = "INSERT INTO db_user_secure (username) VALUES (:username)";
    $query_private = "INSERT INTO db_user_private (username) VALUES (:username)";
    $query_contact = "INSERT INTO db_user_contact (username) VALUES (:username)";

    $db->getQuery($query_user, $value, False);
    $db->getQuery($query_secure, $value, False);
    $db->getQuery($query_private, $value, False);
    $db->getQuery($query_contact, $value, False);


    return $this->saveUserdata($aPost, $username);
  }
 
  public function getUserDaten($tabelle, $username, $feld = '*') {
    $db = new Database('SMT-USER');

    $query = "SELECT " . $feld . " FROM " . $tabelle . " WHERE username=:username";
    $value = array(':username' => $username);

    $db->getQuery($query, $value, False);
    return $db->getValue();
  }

  public function delUser($sUsername) {
    $db = new Database('SMT-USER');

    $query_user = "DELETE FROM db_user WHERE username=:username";
    $query_secure = "DELETE FROM db_user_secure WHERE username=:username";
    $query_private = "DELETE FROM db_user_private WHERE username=:username";
    $query_contact = "DELETE FROM db_user_contact WHERE username=:username";
    $query_config = "DELETE FROM db_user_config WHERE username=:username";
    $query_favorite = "DELETE FROM db_user_favorite WHERE username=:username";

    $db->getQuery($query_user, array(':username' => $sUsername), False);
    $db->getQuery($query_secure, array(':username' => $sUsername), False);
    $db->getQuery($query_private, array(':username' => $sUsername), False);
    $db->getQuery($query_contact, array(':username' => $sUsername), False);
    $db->getQuery($query_config, array(':username' => $sUsername), False);
    $db->getQuery($query_favorite, array(':username' => $sUsername), False);
  }
  
  public function checkFavorite($sys) {
    $db = new Database('SMT-USER');

    $query = "SELECT * FROM db_user_favorite WHERE username=:username && server_id=:server_id LIMIT 1";
    $value = array(':username' => $this->getUsername(), ':server_id'=>$sys);
    $db->getQuery($query, $value);
    
    if($db->getNumrows() == 0) {
      return False;
    } else {
      return True;
    }
  }
  
  /**
   * Neuen Benutzer in den Standardtabellen anlegen
   *
   * @param array $aPost
   * @param string $sDatabase
   */
  public function Favorite($sys) {
    $db = new Database('SMT-USER');
    
    $query = "SELECT * FROM db_user_favorite WHERE username=:username && server_id=:server_id";
    $value = array(':username'=>$this->getUsername(), ':server_id'=>$sys);
    $db->getQuery($query, $value);
    
    if($db->getNumrows() == 0) {
      $query = "INSERT INTO db_user_favorite (username,server_id) VALUES (:username,:server_id)";
    } else {
      $query = "DELETE FROM db_user_favorite WHERE username=:username && server_id=:server_id";
    }
    
    $db->getQuery($query, $value);
  }
  
  public function listUsers() {
    $db = new Database('SMT-USER');
    
    $ses = Session::getInstance();
    $lim = $ses->get('db_limit');
    
    $sum = $db->getQuery("SELECT count(id) FROM db_user_private", array(), True);
    $ses->set('db_summe', $sum['0']['count(id)']);

    $query = "SELECT * FROM db_user_private, db_user_contact, db_user WHERE db_user.username=db_user_private.username && db_user_private.username=db_user_contact.username ORDER BY lastname LIMIT " . $lim['start'] . "," . $lim['limit'];
    $value = array();
    
    return $db->getQuery($query, $value, True);
  }

}

?>
