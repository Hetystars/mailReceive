<?php
/**
 * Created by PhpStorm.
 * User: Hety <Hetystars@gmail.com>
 * Date: 12/14/2017
 * Time: 8:24 PM
 */

class receiveMail
{
    /**
     * @var string
     */
    private
        $server = '',
        $username = '',
        $password = '',
        $email = '';

    /**
     * @var resource
     */
    private $mailbox;

    /**
     * @var int
     */
    public
        $totalValid = 0;

    /**
     * receiveMail constructor.
     * @param $username
     * @param $password
     * @param $EmailAddress
     * @param string $mailserver
     * @param string $servertype
     * @param string $port
     * @param bool $ssl
     */
    public function __construct($username, $password, $EmailAddress, $mailserver = 'localhost', $servertype = 'pop', $port = '110', $ssl = true)
    {
        if ($servertype === 'imap') {
            '' === $port && $port = '143';
            $strConnect = '{' . $mailserver . ':' . $port . '}INBOX';
        } else {
            $strConnect = "{{$mailserver}:{$port}}INBOX";
        }
        $this->server = $strConnect;
        $this->username = $username;
        $this->password = $password;
        $this->email = $EmailAddress;
    }

    /**
     * @return bool
     */
    public function connect()
    {
        $this->mailbox = imap_open($this->server, $this->username, $this->password, OP_DEBUG);

        if (!$this->mailbox) {
            return false;
        }
        return true;
    }

    /**
     * @return array|bool
     */
    public function mailList()
    {
        if (!$this->mailbox)
            return false;
        return imap_list($this->mailbox, $this->server, '*');
    }


    /**
     * @param $mid
     * @return array|bool
     */
    public function getHeaders($mid)
    {
        if (!$this->mailbox)
            return false;

        $mail_header = imap_header($this->mailbox, $mid);
        $sender = $mail_header->from[0];
        $sender_replyto = $mail_header->reply_to[0];
        if (($senderFrom = strtolower($sender->mailbox)) !== 'mailer-daemon' && $senderFrom !== 'postmaster') {
            $subject = $this->decode_mime($mail_header->subject);

            $ccList = array();
            foreach ($mail_header->from as $k => $v) {
                $ccList[] = $v->mailbox . '@' . $v->host;
            }
            $toList = array();
            foreach ($mail_header->to as $k => $v) {
                $toList[] = $v->mailbox . '@' . $v->host;
            }
            $ccList = implode(",", $ccList);
            $toList = implode(",", $toList);
            $mail_details = array(
                'fromBy' => $senderFrom . '@' . $sender->host,
                'fromName' => $this->decode_mime($sender->personal),
                'ccList' => $ccList,
                'toNameOth' => $this->decode_mime($sender_replyto->personal),
                'subject' => $subject,
                'mailDate' => date("Y-m-d H:i:s", $mail_header->udate),
                'udate' => $mail_header->udate,
                'toList' => $toList,
                'seen'=>$mail_header->Unseen,
                'recent'=>$mail_header->Recent
            );
        }
        return $mail_details;
    }


    /**
     * @param $structure
     * @return string
     */
    public function get_mime_type(&$structure)
    {
        $primary_mime_type = array('TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER');
        if ($structure->subtype && $structure->subtype !== 'PNG') {
            return $primary_mime_type[(int)$structure->type] . '/' . $structure->subtype;
        }
        return 'TEXT/PLAIN';
    }

    /**
     * Get Part Of Message Internal Private Use
     * @param $stream
     * @param $msg_number
     * @param $mime_type
     * @param bool|object $structure
     * @param bool|string $part_number
     * @return bool|string
     */
    public function get_part($stream, $msg_number, $mime_type, $structure = false, $part_number = false)
    {
        !$structure && $structure = imap_fetchstructure($stream, $msg_number);
        if ($structure) {
            if ($mime_type == $this->get_mime_type($structure)) {
                !$part_number && $part_number = '1';
                $text = imap_fetchbody($stream, $msg_number, $part_number);
                if ($structure->encoding === 1)
                    return $text;
                if ($structure->encoding === 3) {
                    return imap_base64($text);
                }
                if ($structure->encoding === 4) {
                    return iconv('gb2312', 'utf8', imap_qprint($text));
                }
                return iconv('gb2312', 'utf8', $text);
            }
            /* multipart */
            if ($structure->type === 1) {
                $prefix = '';
                while (list($index, $sub_structure) = each($structure->parts)) {
                    $part_number && $prefix = $part_number . '.';
                    $data = $this->get_part($stream, $msg_number, $mime_type, $sub_structure, $prefix . ($index + 1));
                    if ($data) {
                        return $data;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @return bool|int
     */
    public function getTotalMails()
    {
        if (!$this->mailbox) {
            return false;
        }
        return imap_mailboxmsginfo($this->mailbox)->Unread;
        return imap_num_recent($this->mailbox);
    }

    /**
     * Get Atteced File from Mail
     * @param $mid
     * @param $path
     * @return array|bool
     */
    public function GetAttach($mid, $path)
    {
        if (!$this->mailbox)
            return false;

        $structure = imap_fetchstructure($this->mailbox, $mid);

        $files = array();
        if ($structure->parts) {
            foreach ($structure->parts as $key => $value) {
                $enc = $structure->parts[$key]->encoding;
                //取邮件附件
                if ($structure->parts[$key]->ifdparameters) {
                    $this->totalValid++;
                    //命名附件,转码
                    $name = $this->decode_mime($structure->parts[$key]->dparameters[0]->value);
                    $extend = explode('.', $name);
                    $file['extension'] = $extend[count($extend) - 1];
                    $file['pathname'] = $this->setPathName($key, $file['extension']);
                    $file['title'] = !empty($name) ? htmlspecialchars($name) : str_replace('.' . $file['extension'], '', $name);
                    $file['size'] = $structure->parts[$key]->bytes;
                    $file['tmpname'] = $structure->parts[$key]->dparameters[0]->value;
                    if ($structure->parts[$key]->disposition === 'ATTACHMENT') {
                        $file['type'] = 1;
                    } else {
                        $file['type'] = 0;
                    }

                    $message = imap_fetchbody($this->mailbox, $mid, $key + 1);
                    0 == $enc && $message = imap_8bit($message);
                    1 == $enc && $message = imap_8bit($message);
                    2 == $enc && $message = imap_binary($message);
                    3 == $enc && $message = imap_base64($message);
                    4 == $enc && $message = quoted_printable_decode($message);

                    $files[] = $file;
                    $filePath = $path . '\\' . $file['tmpname'];
                    $fp = fopen($filePath, 'wb+');
                    !$fp && exit($filePath . '文件打开失败');
                    fwrite($fp, $message);
                    fclose($fp);

                }
            }
        }
        return $files;
    }

    /**
     * Get Message Body
     * @param $mid
     * @param $path
     * @param $imageList
     * @return bool|mixed|string
     */
    public function getBody($mid, &$path)
    {
        $body = [];
        if (!$this->mailbox)
            return false;

        $body['text'] = $this->get_part($this->mailbox, $mid, 'TEXT/HTML');
        empty($body) && $body = $this->get_part($this->mailbox, $mid, 'TEXT/PLAIN');
        if (empty($body)) {
            return '';
        }
        //处理图片
        $body['img'] = $this->embed_images($body, $path);
        return $body;
    }

    /**
     * @param $body
     * @param $path
     * @param $imageList
     * @return mixed
     */
    public function embed_images(&$body, &$path)
    {
        preg_match_all('/<img.*?>/', $body['text'], $matches);
        if (!isset($matches[0])) return '';
        foreach ($matches[0] as $img) {
            preg_match('/src="(.*?)"/', $img, $m);
            if (!isset($m[1])) continue;
            $arr = parse_url($m[1]);
            if (!isset($arr['scheme'], $arr['path'])) continue;

            if ("http" !== $arr['scheme']) {
                $filename = explode("@", $arr['path']);
                $body = str_replace($img, '<img alt="" src="' . $path . $filename . '" style="border: none;" />', $body);
            }
        }
        return $body;
    }

    /**
     * Get Message Body
     * @param $mid
     * @return bool
     */
    public function deleteMails($mid, $isAll = true)
    {
        if (!$this->mailbox)
            return false;

        $isAll && imap_deletemailbox($this->mailbox, $this->server);
        imap_delete($this->mailbox, $mid);
        imap_expunge($this->mailbox);
    }

    /**
     * @return bool
     */
    public function close_mailbox()
    {
        if (!$this->mailbox)
            return false;

        imap_close($this->mailbox, CL_EXPUNGE);
    }

    /**
     * 移动邮件到指定分组
     * @param $msglist
     * @param $mailbox
     * @return bool
     */
    public function move_mails($msglist, $mailbox)
    {
        if (!$this->mailbox)
            return false;
        imap_mail_move($this->mailbox, $msglist, 'db_address');
    }

    /**
     * @param $mailbox
     * @return bool
     */
    public function creat_mailbox($mailbox)
    {
        if (!$this->mailbox)
            return false;
        imap_create($this->mailbox, $mailbox);
    }

    /**
     *  decode_mime()转换邮件标题的字符编码,处理乱码
     * @param $str
     * @return string
     */
    public function decode_mime($str)
    {
        $str = imap_mime_header_decode($str);
        return $str[0]->text;
    }

    /**
     * Set path name of the uploaded file to be saved.
     * @param  int $fileID
     * @param  string $extension
     * @access public
     * @return string
     */
    public function setPathName($fileID, $extension)
    {
        return date('Ym\dHis') . $fileID . mt_rand(0, 10000) . '.' . $extension;
    }

}