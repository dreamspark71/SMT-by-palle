<?php

/**
 * Update Klasse für die Benachrichtigung der SMT Anwedung
 * 
 * @author    Werner Pallentin <werner.pallentin@outlook.de>
 * @package   SMT
 */
class Notifier {

  protected $send_emails = true;
  protected $send_pushover = true;
  protected $save_logs = true;
  protected $server_id;
  protected $server;
  protected $status_old;
  protected $status_new;
  protected $GLOBALS;
  
  function __construct() {
    
  }

  /**
   * This function initializes the sending (text msg & email) and logging
   *
   * @param int $server_id
   * @param boolean $status_old
   * @param boolean $status_new
   * @return boolean
   */
  public function notify($server_id, $status_old, $status_new) {
    $db = new Database('SMT-MONITOR');

    $this->server_id = $server_id;
    $this->status_old = $status_old;
    $this->status_new = $status_new;

    $query = "SELECT * FROM psm_servers WHERE server_id=:server_id";
    $db->getQuery($query, array(':server_id' => $this->server_id));

    $value = $db->getValue();
    $this->server = $value['0'];
    $notify = True;

    if (empty($this->server['user'])) {
      return $notify;
    }

    if ($this->server['email'] == 'yes') {
      $this->notifyByEmail($this->server['user']);
    }

    if ($this->server['pushover'] == 'yes') {
      $this->notifyByPushover($this->server['user']);
    }
    
    return $notify;
  }

  /**
   * This functions performs the email notifications
   *
   * @param array $users
   * @return boolean
   */
  protected function notifyByEmail($users) {
    $mail = new Mail();
    $db = new Database('SMT-USER');
    $user = explode(',', $users);

    $subject = $this->parse_msg($this->status_new, 'email_subject', $this->server);
    $utf8Html = $this->parse_msg($this->status_new, 'email_body', $this->server);

    // go through empl
    for ($i = 0; $i < count($user); $i++) {
      $db->getQuery("SELECT * FROM db_user_contact WHERE username=:username", array(':username'=>$user[$i]));
      $mail->sendMail($db->getValue('email'), 'monitor@kgu-consulting.com', $subject, $utf8Html);
    }

    $this->add_log($this->server_id, 'email', $subject, $users);
  }

  /**
   * This functions performs the pushover notifications
   *
   * @param array $users
   * @return boolean
   */
  protected function notifyByPushover($users) {
    $db = new Database('SMT-USER');
    $user = explode(',', $users);
    $userlist = array();
    $pushover = $this->build_pushover();
    $pushover->setPriority(0);
    $message = $this->parse_msg($this->status_new, 'pushover_message', $this->server);

    $pushover->setTitle($this->parse_msg($this->status_new, 'pushover_title', $this->server));
    $pushover->setMessage(str_replace('<br/>', "\n", $message));
    $pushover->setUrl($this->build_url());

    for ($i = 0; $i < count($user); $i++) {
      $db->getQuery("SELECT * FROM db_user_contact WHERE username=:username", array(':username'=>$user[$i]));
      $pushover->setUser($db->getValue('pushover'));
      $pushover->send();
    }

    $this->add_log($this->server_id, 'pushover', $message, $users);
  }

  /**
   * Prepare a new Pushover util.
   *
   * @return \Pushover
   */
  public function build_pushover() {
    $pushover = new Pushover();
    $pushover->setToken($this->get_conf('pushover_api_token'));

    return $pushover;
  }

  /**
   * Get a setting from the config.
   *
   * @param string $key
   * @param mixed $alt if not set, return this alternative
   * @return string
   * @see psm_load_conf()
   */
  public function get_conf($key, $alt = null) {
    if (!isset($this->GLOBALS['sm_config'])) {
      $this->load_conf();
    }
    $result = (isset($this->GLOBALS['sm_config'][$key])) ? $this->GLOBALS['sm_config'][$key] : $alt;
    return $result;
  }

  /**
   * Load config from the database to the $GLOBALS['sm_config'] variable
   *
   * @global object $db
   * @return boolean
   * @see psm_get_conf()
   */
  public function load_conf() {
    $session = Session::getInstance();
    $tabelle = "wos_language_".$session->get('language');
    
    $db = new Database('SMT-ADMIN');
    $this->GLOBALS['sm_config'] = array();

    $query = "SELECT * FROM $tabelle WHERE art=:art";
    $value = array(':art'=>'not');
    
    $e = $db->getQuery($query, $value, True);

    for ($i = 0; $i < $db->getNumrows(); $i++) {
      $this->GLOBALS['sm_config'][$e[$i]['text_name']] = $e[$i]['text_value'];
    }
  }

  /**
   * This function merely adds the message to the log table. It does not perform any checks,
   * everything should have been handled when calling this function
   *
   * @param string $server_id
   * @param string $message
   */
  public function add_log($server_id, $type, $message, $user) {
    $db = new Database('SMT-MONITOR');

    $query = "INSERT INTO psm_log (server_id, type, message, user_id) VALUES (:server_id, :type, :message, :user_id)";
    $value = array(':server_id' => $server_id, ':type' => $type, ':message' => $message, ':user_id' => $user);
    $db->getQuery($query, $value);
  }

  /**
   * Parses a string from the language file with the correct variables replaced in the message
   *
   * @param boolean $status
   * @param string $type is either 'sms' or 'email'
   * @param array $server information about the server which may be placed in a message: %KEY% will be replaced by your value
   * @return string parsed message
   */
  function parse_msg($status, $type, $vars) {
    $message = $this->get_conf($status . '_' . $type);

    if (!$message) {
      return $message;
    }
    $vars['date'] = date('Y-m-d H:i:s');

    foreach ($vars as $k => $v) {
      $message = str_replace('%' . strtoupper($k) . '%', $v, $message);
    }
   
    return $message;
  }

  /**
   * Generate a new link to the current monitor
   * @param array|string $params key value pairs or pre-formatted string
   * @param boolean $urlencode urlencode all params?
   * @param boolean $htmlentities use entities in url?
   * @return string
   */
  public function build_url($params = array(), $urlencode = true, $htmlentities = true) {
    if (defined('PSM_BASE_URL') && BASE_URL !== null) {
      $url = BASE_URL;
    } else {
      $url = (filter_input(INPUT_SERVER, 'SERVER_PORT') == 443 ? 'https' : 'http') . '://' . filter_input(INPUT_SERVER, 'HTTP_HOST');
      $url .= dirname(filter_input(INPUT_SERVER, 'SCRIPT_NAME')) . '/';
      $url = str_replace('\\', '', $url);
    }

    if ($params != null) {
      $url .= '?';
      if (is_array($params)) {
        $delim = ($htmlentities) ? '&amp;' : '&';
        foreach ($params as $k => $v) {
          if ($urlencode) {
            $v = urlencode($v);
          }
          $url .= $delim . $k . '=' . $v;
        }
      } else {
        $url .= $params;
      }
    }
    return $url;
  }

}
