<?php

/*
 * This file is part of the Mailhog service provider for the Codeception Email Testing Framework.
 * (c) 2015-2016 Eric Martel
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Codeception\Module;

use Codeception\Module;

class MailHog extends Module
{
  use \Codeception\Email\TestsEmails;

  use \Codeception\Email\EmailServiceProvider;

  /**
   * HTTP Client to interact with MailHog
   *
   * @var \GuzzleHttp\Client
   */
  protected $mailhog;

  /**
   * Raw email header data converted to JSON
   *
   * @var array
   */
  protected $fetchedEmails;

  /**
   * Currently selected set of email headers to work with
   *
   * @var array
   */
  protected $currentInbox;

  /**
   * Starts as the same data as the current inbox, but items are removed as they're used
   *
   * @var array
   */
  protected $unreadInbox;

  /**
   * Contains the currently open email on which test operations are conducted
   *
   * @var mixed
   */
  protected $openedEmail;

  /**
   * Codeception exposed variables
   *
   * @var array
   */
  protected $config = array('url', 'port', 'guzzleRequestOptions', 'deleteEmailsAfterScenario');

  /**
   * Codeception required variables
   *
   * @var array
   */
  protected $requiredFields = array('url', 'port');

  public function _initialize()
  {
    $url = trim($this->config['url'], '/') . ':' . $this->config['port'];

    $this->mailhog = new \GuzzleHttp\Client(['base_uri' => $url, 'timeout' => 1.0]);

    if (isset($this->config['guzzleRequestOptions'])) {
        foreach ($this->config['guzzleRequestOptions'] as $option => $value) {
            $this->mailhog->setDefaultOption($option, $value);
        }
    }
  }

  /**
   * Method executed after each scenario
   */
  public function _after(\Codeception\TestCase $test)
  {
    if(isset($this->config['deleteEmailsAfterScenario']) && $this->config['deleteEmailsAfterScenario'])
    {
      $this->deleteAllEmails();
    }
  }

  /**
   * Delete All Emails
   *
   * Accessible from tests, deletes all emails
   */
  public function deleteAllEmails()
  {
    try
    {
      if (in_array(__FUNCTION__, $this->config['api_v2'])) {
        $this->mailhog->request('DELETE', '/api/v2/messages');
      }
      else {
        $this->mailhog->request('DELETE', '/api/v1/messages');
      }
    }
    catch(Exception $e)
    {
      $this->fail('Exception: ' . $e->getMessage());
    }
  }

  /**
   * Fetch Emails
   *
   * Accessible from tests, fetches all emails
   */
  public function fetchEmails()
  {
    $this->fetchedEmails = array();

    try
    {
      if (in_array(__FUNCTION__, $this->config['api_v2'])) {
        $response = $this->mailhog->request('GET', '/api/v2/messages');
        $repsonseBodyDecoded = json_decode($response->getBody());
        $this->fetchedEmails = $repsonseBodyDecoded->items;
      }
      else {
        $response = $this->mailhog->request('GET', '/api/v1/messages');
        $this->fetchedEmails = json_decode($response->getBody());
      }
    }
    catch(Exception $e)
    {
      $this->fail('Exception: ' . $e->getMessage());
    }

    $this->sortEmails($this->fetchedEmails);

    // by default, work on all emails
    $this->setCurrentInbox($this->fetchedEmails);

    // Return all the mails.
    return $this->fetchedEmails;
  }

  /**
   * Access Inbox For
   *
   * Filters emails to only keep those that are received by the provided address
   *
   * @param string $address Recipient address' inbox
   */
  public function accessInboxFor($address)
  {
    $inbox = array();
    foreach($this->fetchedEmails as $email)
    {
      if(!isset($email->Content->Headers->Bcc))
      {
        if(strpos($email->Content->Headers->To[0], $address) !== FALSE || (isset($email->Content->Headers->Cc) && array_search($address, $email->Content->Headers->Cc)))
        {
          array_push($inbox, $email);
        }
      }
      else if(strpos($email->Content->Headers->Bcc[0], $address) !== FALSE)
      {
        array_push($inbox, $email);
      }
    }
    $this->setCurrentInbox($inbox);

    // Return the inbox.
    return $inbox;
  }

  /**
   * Open Next Unread Email
   *
   * Pops the most recent unread email and assigns it as the email to conduct tests on
   */
  public function openNextUnreadEmail()
  {
    $this->openedEmail = $this->getMostRecentUnreadEmail();
  }

  /**
   * Get Opened Email
   *
   * Main method called by the tests, providing either the currently open email or the next unread one
   *
   * @param bool $fetchNextUnread Goes to the next Unread Email
   * @return mixed Returns a JSON encoded Email
   */
  protected function getOpenedEmail($fetchNextUnread = FALSE)
  {
    if($fetchNextUnread || $this->openedEmail == NULL)
    {
      $this->openNextUnreadEmail();
    }

    return $this->openedEmail;
  }

  /**
   * Get Most Recent Unread Email
   *
   * Pops the most recent unread email, fails if the inbox is empty
   *
   * @return mixed Returns a JSON encoded Email
   */
  protected function getMostRecentUnreadEmail()
  {
    if(empty($this->unreadInbox))
    {
      $this->fail('Unread Inbox is Empty');
    }

    $email = array_shift($this->unreadInbox);
    return $this->getFullEmail($email->ID);
  }

  /**
   * Get Full Email
   *
   * Returns the full content of an email
   *
   * @param string $id ID from the header
   * @return mixed Returns a JSON encoded Email
   */
  protected function getFullEmail($id)
  {
    try
    {
      $response = $this->mailhog->request('GET', "/api/v1/messages/{$id}");
    }
    catch(Exception $e)
    {
      $this->fail('Exception: ' . $e->getMessage());
    }
    $fullEmail = json_decode($response->getBody());
    return $fullEmail;
  }

  /**
   * Get Email Subject
   *
   * Returns the subject of an email
   *
   * @param mixed $email Email
   * @return string Subject
   */
  protected function getEmailSubject($email)
  {
    return $email->Content->Headers->Subject[0];
  }

  /**
   * Get Email Body
   *
   * Returns the body of an email
   *
   * @param mixed $email Email
   * @return string Body
   */
  protected function getEmailBody($email)
  {
    return $email->Content->Body;
  }

  /**
   * Get Email To
   *
   * Returns the string containing the persons included in the To field
   *
   * @param mixed $email Email
   * @return string To
   */
  protected function getEmailTo($email)
  {
    return $email->Content->Headers->To[0];
  }

  /**
   * Get Email CC
   *
   * Returns the string containing the persons included in the CC field
   *
   * @param mixed $email Email
   * @return string CC
   */
  protected function getEmailCC($email)
  {
    return $email->Content->Headers->Cc[0];
  }

  /**
   * Get Email BCC
   *
   * Returns the string containing the persons included in the BCC field
   *
   * @param mixed $email Email
   * @return string BCC
   */
  protected function getEmailBCC($email)
  {
    if(isset($email->Content->Headers->Bcc))
    {
      return $email->Content->Headers->Bcc[0];
    }
    return "";
  }

  /**
   * Get Email Recipients
   *
   * Returns the string containing all of the recipients, such as To, CC and if provided BCC
   *
   * @param mixed $email Email
   * @return string Recipients
   */
  protected function getEmailRecipients($email)
  {
    $recipients = $email->Content->Headers->To[0] . ' ' .
                  $email->Content->Headers->Cc[0];
    if(isset($email->Content->Headers->Bcc))
    {
      $recipients .= ' ' . $email->Content->Headers->Bcc[0];
    }

    return $recipients;
  }

  /**
   * Get Email Sender
   *
   * Returns the string containing the sender of the email
   *
   * @param mixed $email Email
   * @return string Sender
   */
  protected function getEmailSender($email)
  {
    return $email->Content->Headers->From[0];
  }

  /**
   * Get Email Reply To
   *
   * Returns the string containing the address to reply to
   *
   * @param mixed $email Email
   * @return string ReplyTo
   */
  protected function getEmailReplyTo($email)
  {
    return $email->Content->Headers->{'Reply-To'}[0];
  }

  /**
   * Get Email Priority
   *
   * Returns the priority of the email
   *
   * @param mixed $email Email
   * @return string Priority
   */
  protected function getEmailPriority($email)
  {
    return $email->Content->Headers->{'X-Priority'}[0];
  }

  /**
   * Set Current Inbox
   *
   * Sets the current inbox to work on, also create a copy of it to handle unread emails
   *
   * @param array $inbox Inbox
   */
  protected function setCurrentInbox($inbox)
  {
    $this->currentInbox = $inbox;
    $this->unreadInbox = $inbox;
  }

  /**
   * Get Current Inbox
   *
   * Returns the complete current inbox
   *
   * @return array Current Inbox
   */
  protected function getCurrentInbox()
  {
    return $this->currentInbox;
  }

  /**
   * Get Unread Inbox
   *
   * Returns the inbox containing unread emails
   *
   * @return array Unread Inbox
   */
  protected function getUnreadInbox()
  {
    return $this->unreadInbox;
  }

  /**
   * Sort Emails
   *
   * Sorts the inbox based on the timestamp
   *
   * @param array $inbox Inbox to sort
   */
  protected function sortEmails($inbox)
  {
    usort($inbox, array($this, 'sortEmailsByCreationDatePredicate'));
  }

  /**
   * Get Email To
   *
   * Returns the string containing the persons included in the To field
   *
   * @param mixed $emailA Email
   * @param mixed $emailB Email
   * @return int Which email should go first
   */
  static function sortEmailsByCreationDatePredicate($emailA, $emailB)
  {
    $sortKeyA = $emailA->Content->Headers->Date;
    $sortKeyB = $emailB->Content->Headers->Date;
    return ($sortKeyA > $sortKeyB) ? -1 : 1;
  }

  /**
  * Decodes MIME/HTTP encoded header values.
  *
  * @param $header
  *   The header to decode.
  *
  * @return string
  *   The mime-decoded header.
  *
  * Proudly found in includes/unicode.inc of Drupal 7.
  */
  public function mime_header_decode($header) {
    // First step: encoded chunks followed by other encoded chunks (need to collapse whitespace)
    $header = preg_replace_callback('/=\?([^?]+)\?(Q|B)\?([^?]+|\?(?!=))\?=\s+(?==\?)/', array(__CLASS__, '_mime_header_decode'), $header);
    // Second step: remaining chunks (do not collapse whitespace)
    return preg_replace_callback('/=\?([^?]+)\?(Q|B)\?([^?]+|\?(?!=))\?=/', array(__CLASS__, '_mime_header_decode'), $header);
  }

  /**
   * Decodes encoded header data passed from mime_header_decode().
   *
   * Callback for preg_replace_callback() within mime_header_decode().
   *
   * @param $matches
   *   The array of matches from preg_replace_callback().
   *
   * @return string
   *   The mime-decoded string.
   *
   * @see mime_header_decode()
   */
  protected function _mime_header_decode($matches) {
    // Regexp groups:
    // 1: Character set name
    // 2: Escaping method (Q or B)
    // 3: Encoded data
    $data = ($matches[2] == 'B') ? base64_decode($matches[3]) : str_replace('_', ' ', quoted_printable_decode($matches[3]));
    if (strtolower($matches[1]) != 'utf-8') {
      $data = drupal_convert_to_utf8($data, $matches[1]);
    }
    return $data;
  }
}
