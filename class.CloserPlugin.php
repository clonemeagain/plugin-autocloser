<?php
foreach (array(
  'canned',
  'list',
  'orm',
  'plugin',
  'ticket',
  'signal',
  'staff'
) as $c)
  require_once INCLUDE_DIR . "class.$c.php";
require_once 'config.php';

/**
 * The goal of this Plugin is to close tickets when they get old. Logans Run
 * style.
 */
class CloserPlugin extends Plugin {

  var $config_class = 'CloserPluginConfig';

  /**
   * Set to TRUE to enable extra logging.
   *
   * @var boolean
   */
  const DEBUG = TRUE;

  /**
   * Hook the bootstrap process Run on every instantiation, so needs to be
   * concise.
   *
   * {@inheritdoc}
   *
   * @see Plugin::bootstrap()
   */
  public function bootstrap() {
    // Listen for cron Signal, which only happens at end of class.cron.php:
    Signal::connect('cron',
      function ($ignored, $data) {
        
        // Autocron is an admin option, we can filter out Autocron Signals
        // to ensure changing state for potentially hundreds/thousands
        // of tickets doesn't affect interactive Agent/User experience.
        $use_autocron = $this->getConfig()->get('use_autocron');
        
        // Autocron Cron Signals are sent with this array key set to TRUE
        $is_autocron = (isset($data['autocron']) && $data['autocron']);
        
        // Normal cron isn't Autocron:
        if (! $is_autocron || ($use_autocron && $is_autocron))
          $this->logans_run_mode();
      });
  }

  /**
   * Closes old tickets.. with extreme prejudice.. or, regular prejudice..
   * whatever. = Welcome to the 23rd Century. The perfect world of total
   * pleasure. ... there's just one catch.
   */
  private function logans_run_mode() {
    $config = $this->getConfig();
    if ($this->is_time_to_run($config)) {
      // Use the number of config groups to run the closer as many times as is needed.
      foreach (range(1, CloserPluginConfig::NUMBER_OF_SETTINGS) as $group_id) {
        if (! $config->get('group-enabled-' . $group_id))
          continue;
        
        try {
          $open_ticket_ids = $this->find_ticket_ids($config, $group_id);
          if (self::DEBUG)
            error_log(
              "CloserPlugin group [$group_id] $group_name has " .
                 count($open_ticket_ids) . " open tickets.");
          
          // Bail if there's no work to do
          if (! count($open_ticket_ids))
            continue;
          
          // Gather the resources required to start changing statuses:
          $new_status = TicketStatus::lookup(
            array(
              'id' => (int) $config->get('to-status-' . $group_id)
            ));
          
          // Admin note is just text
          $admin_note = $config->get('admin-note-' . $group_id) ?: FALSE;
          
          // Fetch the actual content of the reply text.html == with images, don't think it works with attachments.
          $admin_reply = $config->get('admin-reply-' . $group_id);
          if ($admin_reply) {
            $admin_reply = Canned::lookup($admin_reply);
            if ($admin_reply instanceof Canned) {
              $admin_reply = $admin_reply->getFormattedResponse('html');
            }
          }
          
          if (self::DEBUG)
            print 
              "Found the following details:\nAdmin Note: $admin_note\n\nAdmin Reply: $admin_reply\n";
          
          // Go through the tickets:
          foreach ($open_ticket_ids as $ticket_id) {
            
            // Fetch the ticket as an Object
            $ticket = Ticket::lookup($ticket_id);
            if (! $ticket instanceof Ticket) {
              error_log("Ticket $ticket_id is not instatiable. :-(");
              continue;
            }
            
            // Some tickets aren't closeable.. either because of open tasks, or missing fields.
            // we can therefore only work on closeable tickets.
            if (! $ticket->isCloseable()) {
              $ticket->LogNote(__('Error auto-changing status'),
                __(
                  'Unable to change this ticket\'s status to ' .
                     $new_status->getState()), get_class(), FALSE);
              continue;
            }
            
            // Add a Note to the thread indicating it was closed by us
            if ($admin_note)
              $ticket->LogNote(
                __('Changing status to: ' . $new_status->getState()), $admin_note,
                get_class(), FALSE); // FALSE == no email alert
            
            // Post Reply to the user, telling them the ticket is closed, relates to issue #2
            if ($admin_reply)
              $this->post_reply($ticket, $new_status, $admin_reply);
            
            $this->change_ticket_status($ticket, $new_status);
          }
        } catch (Exception $e) {
          // Well, something borked
          error_log(
            "Exception encountered, we'll soldier on, but something is broken!");
          error_log($e->getMessage());
          print_r($e->getTrace());
        }
      }
    }
  }

  /**
   * Calculates when it's time to run the plugin, based on the config. Uses
   * things like: How long the admin defined the cycle to be? When it was last
   * run
   *
   * @param PluginConfig $config
   * @return boolean
   */
  private function is_time_to_run(PluginConfig $config) {
    // We can store arbitrary things in the config, like, when we ran this last:
    $last_run = $config->get('last-run');
    $now = time(); // Assume server timezone doesn't change enough to break this
    $config->set('last-run', $now);
    
    // assume a freqency of "Every Cron" means it is always overdue
    $next_run = 0;
    
    // Convert purge frequency to a comparable format to timestamps:
    if ($freq_in_config = (int) $config->get('purge-frequency')) {
      // Calculate when we want to run next, config hours into seconds,
      // plus the last run is the timestamp of the next scheduled run
      $next_run = $last_run + ($freq_in_config * 3600);
    }
    
    // See if it's time to check old tickets
    // Always run when in DEBUG mode.. because waiting for the scheduler is slow
    // If we don't have a next_run, it's because we want it to run
    // If the next run is in the past, then we are overdue, so, lets go!
    if (self::DEBUG || ! $next_run || $now > $next_run)
      return TRUE;
    return FALSE;
  }

  /**
   * This is the part that actually "Closes" the tickets Well, depending on the
   * admin settings I mean. Could use $ticket->setStatus($closed_status)
   * function however, this gives us control over _how_ it is closed. preventing
   * accidentally making any logged-in staff associated with the closure, which
   * is an issue with AutoCron
   *
   * @param Ticket $ticket
   * @param TicketStatus $new_status
   */
  private function change_ticket_status(Ticket $ticket, TicketStatus $new_status) {
    if (self::DEBUG)
      error_log(
        "Setting status " . $new_status->getState() .
           " for ticket {$ticket->getId()}::{$ticket->getSubject()}");
    
    // Start by setting the last update and closed timestamps to now
    $ticket->closed = $ticket->lastupdate = SqlFunction::NOW();
    
    // Remove any duedate or overdue flags
    $ticket->duedate = null;
    $ticket->clearOverdue(FALSE); // flag prevents saving, we'll do that
    
    // Post an Event with the current timestamp.
    $ticket->logEvent($new_status->getState(),
      array(
        'status' => array(
          $new_status->getId(),
          $new_status->getName()
        )
      ));
    // Actually apply the new "TicketStatus" to the Ticket.
    $ticket->status = $new_status;
    
    // Save it, flag prevents it refetching the ticket data straight away (inefficient)
    $ticket->save(FALSE);
  }

  /**
   * Retrieves an array of ticket_id's from the database
   *
   * @param PluginConfig $config
   * @param int $group_id
   * @return array of integers that are Ticket::lookup compatible ID's of Open
   *         Tickets
   * @throws Exception so you have something interesting to read in your cron
   *         logs..
   */
  private function find_ticket_ids(PluginConfig $config, $group_id) {
    $from_status = (int) $config->get('from-status-' . $group_id);
    if (! $from_status)
      throw new \Exception("Invalid parameter (int) from_status needs to be > 0");
    
    $age_days = (int) $config->get('purge-age-' . $group_id);
    if ($age_days < 1)
      throw new \Exception("Invalid parameter (int) age_days needs to be > 0");
    
    $max = (int) $config->get('purge-num');
    if ($max < 1)
      throw new \Exception("Invalid parameter (int) max needs to be > 0");
    
    $whereFilter = ($config->get('close-only-answered-' . $group_id)) ? ' AND isanswered=1' : '';
    $whereFilter .= ($config->get('close-only-overdue-' . $group_id)) ? ' AND isoverdue=1' : '';
    
    // Ticket query, note MySQL is doing all the date maths:
    // Sidebar: Why haven't we moved to PDO yet?
    /*
     * Attempt to do this with ORM $tickets = Ticket::objects()->filter( array(
     * 'lastupdate' => SqlFunction::DATEDIFF(SqlFunction::NOW(),
     * SqlInterval($age_days, 'DAY')), 'status_id' => $from_status, 'isanswered'
     * => 1, 'isoverdue' => 1 ))->all(); print_r($tickets);
     */
    
    $sql = sprintf(
      "
SELECT ticket_id 
FROM %s WHERE lastupdate < DATE_SUB(NOW(), INTERVAL %d DAY)
AND status_id=%d %s
ORDER BY ticket_id ASC
LIMIT %d",
      TICKET_TABLE, $age_days, $from_status, $whereFilter, $max);
    
    if (self::DEBUG)
      error_log("Looking for tickets with query: $sql");
    
    $r = db_query($sql);
    // Fill an array with just the ID's of the tickets:
    $ids = array();
    while ($i = db_fetch_array($r, MYSQLI_ASSOC))
      $ids[] = $i['ticket_id'];
    
    return $ids;
  }

  /**
   * Sends a reply to the ticket creator Wrapper/customizer around the
   * Ticket::postReply method.
   *
   * @param Ticket $ticket
   * @param TicketStatus $new_status
   * @param string $admin_reply
   */
  function post_reply(Ticket $ticket, TicketStatus $new_status, $admin_reply) {
    // We can re-use the robot to send notifications for multiple tickets
    // no point rebuilding the object for each one.
    static $robot;
    if (! isset($robot)) {
      $robot = $this->getConfig()->get('robot-account');
      if ($robot)
        $robot = Staff::lookup($robot);
    }
    
    // We need to override this for the notifications
    global $thisstaff;
    
    if ($robot) {
      $assignee = $robot;
    }
    else {
      $assignee = $ticket->getAssignee();
      if (! $assignee instanceof Staff) {
        // Nobody, or a Team was assigned, and we haven't been told to use a Robot account.
        $ticket->logNote(__('AutoCloser Error'),
          __(
            'Unable to send reply, no assigned Agent on ticket, and no Robot account specified in config.'),
          get_class(), FALSE);
        return;
      }
    }
    // This actually bypasses any authentication/validation checks..
    $thisstaff = $assignee;
    
    // Replace any ticket variables in the message:
    $variables = array(
      'recipient' => $ticket->getOwner()
    );
    
    // Provide extra variables.. because. :-)
    $options = array(
      'wholethread' => 'fetch_whole_thread',
      'firstresponse' => 'fetch_first_response',
      'lastresponse' => 'fetch_last_response'
    );
    
    // See if they've been used, if so, call the function
    foreach ($options as $option => $method) {
      if (strpos($admin_reply, $option) !== FALSE)
        $variables[$option] = $this->{$method}($ticket);
    }
    
    $custom_reply = $ticket->replaceVars($admin_reply, $variables);
    
    // Build an array of values to send to the ticket's postReply function
    // 'emailcollab' => FALSE // don't send notification to all collaborators.. maybe.. dunno.
    $vars = array(
      'response' => $custom_reply
    );
    $errors = array();
    
    // Send the alert without claiming the ticket on our assignee's behalf.
    if (! $sent = $ticket->postReply($vars, $errors, TRUE, FALSE))
      $ticket->LogNote(__('Error Notification'),
        __('We were unable to post a reply to the ticket creator.'), get_class(),
        FALSE);
  }

  /**
   * Fetches the first response sent to the ticket Owner
   *
   * @param Ticket $ticket
   * @return string
   */
  private function fetch_first_response(Ticket $ticket) {
    // Apparently the ORM is fighting me.. it doesn't like pulling arbitrary threads using common semantics repeatably.. ffs
    // Let's try it a different way.
    $ticket->getClientThread(); // trick it.. 
    $response = $ticket->getResponses()->first();
    return $this->render_thread_piece($response);
  }

  /**
   * Fetches the last response sent to the ticket Owner.
   *
   * @param Ticket $ticket
   * @return string
   */
  private function fetch_last_response(Ticket $ticket) {
    $ticket->getClientThread(); // trick it..     
    $response = $ticket->getResponses()
      ->order_by('created', QuerySet::DESC)
      ->first();
    return $this->render_thread_piece($response);
  }

  /**
   * Fetches the whole thread that the client can see. As an HTML message.
   *
   * @param Ticket $ticket
   * @return string
   */
  private function fetch_whole_thread(Ticket $ticket) {
    $msg = '';
    $ticket->getClientThread(); // trick it.. 
    foreach ($ticket->getClientThread()->all() as $entry) {
      $msg .= $this->render_thread_piece($entry);
    }
    return $msg;
  }

  private function render_thread_piece(AnnotatedModel $entry) {
    $tag = ($entry->get('format') == 'text') ? 'pre' : 'p';
    return <<<PIECE
<hr />
<p class="thread">
  <h3>{$entry->get('title')}</h3>
  <$tag>{$entry->get('body')}</$tag>
</p>
PIECE;
  }

  /**
   * Required stub.
   *
   * {@inheritdoc}
   *
   * @see Plugin::uninstall()
   */
  function uninstall() {
    $errors = array();
    global $ost;
    $ost->alertAdmin(get_class() . ' has been uninstalled',
      "Old open tickets will remain active.", true);
    
    parent::uninstall($errors);
  }

  /**
   * Plugins seem to want this.
   */
  public function getForm() {
    return array();
  }
}