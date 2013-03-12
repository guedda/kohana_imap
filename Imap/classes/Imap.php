<?php defined('SYSPATH') OR die('No direct script access.');

class Imap
{
    protected static $_instance;
    
    protected $_handle;
    protected $_mailbox;
    protected $_imap_server;
    protected $_connected = 0;
    
    public static function instance()
    {
        if (!isset(Imap::$_instance)) {
            Imap::$_instance = new Imap();
        }
        
        return Imap::$_instance;
    }
    
    /**
     * Connect to IMAP
     * @param string $username
     * @param string $password
     * @param string $folder
     * @param integer $port
     * @param string $tls
     * @return boolean
     */
    public function connect($server, $username, $password, $folder = "INBOX", $port = 143, $tls = "notls")
    {
        $this->_imap_server = '{' . $server . ':' . $port . '/' . $tls . '}';
        $this->_mailbox = $this->_imap_server . $folder;
        $this->_handle = @imap_open($this->_mbox, $username, $password);
        if ($this->_handle != false) {
            $this->_connected = 1;
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Check connected
     * @return boolean
     */
    public function isConnected()
    {
        return $this->_connected;
    }
    
    /**
     * Return all mailboxes of current user.
     * @return array
     */
    public function getMailboxes()
    {
        $result = array();
        $srv = '{' . $this->_server . ':' . $this->_port . '/' . $this->_tls . '}';
        $array = imap_list($this->_handle, $this->_imap_server, "*");
        if (!empty($array)) {
            foreach ($array as $array_element) {
                $result[] = str_replace($this->_imap_server, "", $array_element);
            }
        }
        return $result;
    }
    
    /**
     * Return header information of all messages from folder.
     * @return array
     */
    public function getMessages()
    {
        $headers = imap_headers($this->_handle);
        $array = array();
        $new = false;
        if (is_array($headers)) {            
            foreach ($headers as $key => $header) {
                $header = trim($header);
                if (substr($header, 0, 1) == "N") {
                    $header = trim(substr($header, 1, strlen($header)));
                    $new = true;
                }
                if (substr($header, 0, 1) == 'U') {
                    $header = trim(substr($header, 1, strlen($header)));
                }
                if(substr($header, 0, 1) != 'D') {
                    $position = strpos($header, ')');
                    $messageNumber = substr($header, 0, $position);
                    $array[$key] = $this->_emailHeadersForMessage($messageNumber, $new);
                }
            }
        }
        return $array;
    }
    
    /**
     * Return full compilated message array
     * @param integer $messageNumber - number of Imap message
     * @return array - key "header" = header information and "plain" or "html" = body of a message.
     */
    public function getMessage($messageNumber)
    {
        $array = array();
        $obj = @imap_fetchstructure($this->_handle, $messageNumber);
        if (is_object($obj)) {
            $array['header'] = $this->_emailFullHeadersForMessage($messageNumber);
            if (isset($obj->parts) && is_array($obj->parts)) {
                foreach ($obj->parts as $x => $part) {
                    if (isset($part->parts) && is_array($part->parts)) {
                        foreach ($part->parts as $y => $subpart) {
                            if ($subpart->subtype == "PLAIN") {
                                $array['plain'] = imap_fetchbody($this->_handle, $messageNumber, '1.1');
                            } elseif ($subpart->subtype == "HTML") {
                                $array['html'] = imap_fetchbody($this->_handle, $messageNumber, '1.2');
                            }
                        }
                    } else {
                        if ($part->subtype == "PLAIN") {
                            $array['plain'] = imap_fetchbody($this->_handle, $messageNumber, '1');
                        } elseif ($part->subtype == "HTML") {
                            $array['html'] = imap_fetchbody($this->_handle, $messageNumber, '2');
                        }
                    }
                }
            } elseif ($obj->subtype == "PLAIN") {
                $array['plain'] = imap_fetchbody($this->_handle, $messageNumber, '1');
            } else {
                $array['html'] = imap_fetchbody($this->_handle, $messageNumber, '2');
            }
        } else {
            return false;
        }
        return $array;        
    }
    
    /**
     * Create folder
     * @param string $folderName
     */
    public function createFolder($folderName)
    {
        imap_createmailbox($this->_handle, $this->_mailbox . "." . $folderName);
    }
    
    /**
     * Move message at destination folder
     * @param integer $messageNumber - number of Imap message
     * @param string $folderName - destination folder
     * @return boolean
     */
    public function moveMessage($messageNumber, $folderName)
    {
        $result = imap_mail_move($this->_handle, $messageNumber, $folderName);
        $this->expunge();
        return $result;
    }
    
    /**
     * Copy message at destination folder
     * @param integer $messageNumber - number of Imap message
     * @param string $folderName - destination folder
     * @return boolean;
     */
    public function copyMessage($messageNumber, $folderName)
    {
        return imap_mail_copy($this->_handle, $messageNumber, $folderName);
    }
    
    /**
     * Delete messages from folder
     * @param string $messageNumbers - Imap message-numbers with commas (ex.: 1,3,61,343)
     */
    public function deleteMessages($messageNumbers)
    {
        imap_delete($this->_handle, $messageNumbers);
    }
    
    /**
     * Expunge messages (Physically delete messages from IMAP)
     */
    public function expunge()
    {
        imap_expunge($this->_handle);
    }
    
    /**
     * Disconnect from IMAP
     */
    public function disconnect()
    {
        if ($this->_handle != false) {
            imap_close($this->_handle);
        }
        $this->_connected = false;
    }

    /**
     * Return basic header information from message
     * @param string $messageNumber
     * @param boolean $new
     * @return array
     */
    private function _emailHeadersForMessage($messageNumber, $new = false)
    {
        $array = array();
        $head = $this->_returnHeaderInfoObj($messageNumber);
        if (is_object($head)) {
            if (isset($head->date)) {
                $array['date'] = strtotime($head->date);
            } else {
                $array['date'] = 0;
            }
            if (isset($head->subject)) {
                $subject = imap_mime_header_decode($head->subject);
                $array['subject'] = $subject[0]->text;
            } else {
                $array['subject'] = "No subject";
            }
            
            
            if (isset($head->sender))
                $array['sender'] = $head->sender[0]->mailbox.'@'.$head->sender[0]->host;
            if (isset($head->from[0]->personal)) {
                $from = imap_mime_header_decode($head->from[0]->personal);
                $array['from'] = $from[0]->text;
            } else {
                if (isset($array['sender'])) {
                    $array['from'] = $array['sender'];
                } else {
                    $array['from'] = "undefined";
                }
                
            }
            $array['msgno'] = trim($head->Msgno);
            if ($new) {
                $array['status'] = "U";
            } else {
                $array['status'] = $head->Unseen;
            }
            
        }
        return $array;
    }
    
    /**
     * Return full header information from IMAP-message.
     * @param string $messageNumber
     * @return array
     */
    private function _emailFullHeadersForMessage($messageNumber)
    {
        $headers = imap_fetchheader($this->_handle, $messageNumber);
        preg_match_all('/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)\r\n/m', $headers, $matches);
        $res = array();
        foreach ($matches[0] as $k => $val) {
            $res[$matches[1][$k]] = $matches[2][$k];
        }
        return $res;
    }
    
    private function _returnHeaderInfoObj($messageNumber){
        return @imap_headerinfo($this->_handle,$messageNumber);
    }

}

