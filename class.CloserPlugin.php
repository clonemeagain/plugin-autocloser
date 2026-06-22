<?php
/**
 * @file class.CloserPlugin.php :: 
 * @  requires osTicket 1.17+ & PHP8.0+
 * @  multi-instance: yes
 *
 * @author Grizly <clonemeagain@gmail.com>
 * @see https://github.com/clonemeagain/plugin-autocloser
 * @fork by Cartmega <www.cartmega.com>
 * @see https://github.com/Cartmega/plugin-autocloser 
 */
foreach ([
 'canned',
 'format',
 'list',
 'orm',
 'misc',
 'plugin',
 'ticket',
 'signal',
 'staff'
] as $c) {
    require_once INCLUDE_DIR . "class.$c.php";
}
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
    private $DEBUG = FALSE;

    /**
     * Keeps all log entries for each run
     * for output to syslog
     *
     * @var array
     */
    private $LOG = [];

    /**
     * The name that appears in threads as: Closer Plugin.
     *
     * @var string
     */
    const PLUGIN_NAME = 'Closer Plugin';

    /**
     * Hook the bootstrap process Run on every instantiation, so needs to be
     * concise.
     *
     * {@inheritdoc}
     *
     * @see Plugin::bootstrap()
     */
    public function bootstrap() {
        // ---------------------------------------------------------------------
        // Fetch the config
        // ---------------------------------------------------------------------
        $config = $this->config;

        // Listen for cron Signal, which only happens at end of class.cron.php:
        Signal::connect('cron', function ($ignored, $data) use (&$config) {

            // set debug mode (reset debug vars)
            $this->DEBUG = (bool) $config->get('debug-mode-enabled');
            $this->LOG   = [];

            // Autocron is an admin option, we can filter out Autocron Signals
            // to ensure changing state for potentially hundreds/thousands
            // of tickets doesn't affect interactive Agent/User experience.
            $use_autocron = $config->get('use_autocron');

            // Autocron Cron Signals are sent with this array key set to TRUE
            $is_autocron = (isset($data['autocron']) && $data['autocron']);

            // Normal cron isn't Autocron:
            if (!$is_autocron || ($use_autocron && $is_autocron))
                $this->logans_run_mode($config);
        });
    }

    /**
     * Closes old tickets.. with extreme prejudice.. or, regular prejudice..
     * whatever. = Welcome to the 23rd Century. The perfect world of total
     * pleasure. ... there's just one catch.
     */
    private function logans_run_mode(&$config) {

        if (!$this->is_time_to_run($config))
            return true;

        list ($__, $_N) = self::translate('closer');

        try {
            $open_ticket_ids = $this->find_ticket_ids($config);
            if ($this->DEBUG) {
                $this->LOG[]=sprintf($__('%s tickets matched the criterias.'), count($open_ticket_ids));
            }

            // Bail out if there is no work to do
            if (!count($open_ticket_ids)) {
                if ($this->DEBUG)
                    $this->print2log();

                return true;
            }

            // Find the new TicketStatus from the Setting config:
            $new_status_config = (int) $config->get('to-status');
            if(!($new_status = TicketStatus::lookup(['id' => $new_status_config])) ) {
                if ($this->DEBUG) {
                    $this->LOG[]=sprintf($__('No valid status ID: %d'), $new_status_config);
                }
                return false;
            }

            // Admin note is just text
            $admin_note = $config->get('admin-note') ?: FALSE;

            // Fetch the actual content of the reply, "html" means load with images, 
            // I don't think it works with attachments though.
            $admin_reply_config = (int) $config->get('admin-reply');
            $admin_reply = null;
            if ($admin_reply_config > 0) {
                // We have a valid Canned_Response ID, fetch the actual Canned:
                if (   ($admin_reply_config = Canned::lookup($admin_reply_config))
                    && $admin_reply_config instanceof Canned
                   ) {
                    // Got a real Canned object, let's pull the body/string:
                    $admin_reply = $admin_reply_config->getFormattedResponse('html');
                }
            }

            if ($this->DEBUG) {
                $this->LOG[]=sprintf($__("Found the following details:\nAdmin Note: %s\n\nCanned Reply: %s\n"), $admin_note, $admin_reply);
            }

            // Get the robot for this config
            $robot_config = (int) $config->get('robot-account');
            $robot = ($robot_config > 0) ? (Staff::lookup($robot_config)?:null) : $robot_config;

            // Go through each ticket ID:
            foreach ($open_ticket_ids as $ticket_id) {

                // Fetch ticket as an Object
                $ticket = Ticket::lookup($ticket_id);
                if (!$ticket || !$ticket instanceof Ticket) {
                    $this->LOG[]=sprintf($__('Ticket with ID %d not found. :-('), $ticket_id);
                    continue;
                }

                // Some tickets aren't closeable.. either because of open tasks, or missing fields.
                // we can therefore only work on closeable tickets.
                // This won't close it, nor will it send a response, so it will likely trigger again
                // on the next run.. TRUE means send an alert.
                if ($new_status->getState() == 'closed' && ($warn = $ticket->isCloseable()) !== true) {
                    $msg = sprintf("%s\n%s"
                                   ,sprintf($__('Unable to change this ticket\'s status to %s'), $new_status->getLocalName())
                                    ,$warn
                    );
                    $ticket->LogNote($__('Error auto-changing status'), $msg, self::PLUGIN_NAME, TRUE);
                    if ($this->DEBUG) {
                        $this->LOG[]=sprintf($__("Error set status for ticket #%s (ID: %d)\n\nError: %s\n"), $ticket->getNumber(), $ticket_id, $msg);
                    }
                    continue;
                }

                // Actually change the ticket status
                if(!$this->change_ticket_status($ticket, $new_status)) {
                    if ($this->DEBUG) {
                        $msg = $__('Unable to set status');
                        $this->LOG[]=sprintf($__("Error set status for ticket #%s (ID: %d)\n\nError: %s\n"), $ticket->getNumber(), $ticket_id, $msg);
                    }
                    continue;
                }

                // Add a Note to the thread indicating it was closed by us, don't send an alert.
                if ($admin_note) {
                    $ticket->LogNote(sprintf($__('Changing status to %s'), $new_status->getLocalName())
                                    ,$admin_note, self::PLUGIN_NAME, FALSE);
                }

                // Post a Reply to the user, telling them the ticket is closed, relates to issue #2
                if ($admin_reply) {
                    $this->post_reply($ticket, $new_status, $admin_reply, $robot);
                }

                // force updating lastupdate
                $ticket->lastupdate = SqlFunction::NOW();
                $ticket->save();
            }

            $this->print2log();

        } catch (Exception $e) {
            // Well, something borked
            $this->LOG[]=$__("Exception encountered, we'll soldier on, but something is broken!");
            $this->LOG[]=$e->getMessage();
            if ($this->DEBUG) {$this->LOG[]='<pre>'.print_r($e->getTrace(),2).'</pre>';}
            $this->print2log();
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
    private function is_time_to_run(PluginConfig &$config) {
        $calculate_date = $config->get('calculate-date');

        // is time for "Day X of Month"?
        if($calculate_date === 'd') {
            $day  = (int) $config->get('day-of-month');
            $time = (int) $config->get('time-of-day');
            return $this->isDayOfMonthReached($day, $time);
        }

        // We can store arbitrary things in the config, like, when we ran this last:
        $last_run = (int) $config->get('last-run');
        $now = Misc::dbtime(); // Never assume about time.. 

        // assume a freqency of "Every Cron" means it is always overdue
        $next_run = 0;

        // Convert purge frequency to a comparable format to timestamps:
        $frequency = (int) $config->get('frequency');
        if (($fr=($frequency > 0) ? $frequency : 0)) {
            // Calculate when we want to run next, config hours into seconds,
            // plus the last run is the timestamp of the next scheduled run
            $next_run = $last_run + ($fr * 3600);
        }

        // See if it's time to check old tickets
        // Always run when in DEBUG mode.. because waiting for the scheduler is slow
        // If we don't have a next_run, it's because we want it to run
        // If the next run is in the past, then we are overdue, so, lets go!
        if ($this->DEBUG || !$next_run || $now > $next_run) {
            $config->set('last-run', $now);
            return true;
        }
        return false;
    }

    private function isDayOfMonthReached(int $day, int $time): bool {
        global $cfg;

        if ($day < 1)
            return false;
        elseif($day > 28)
            $day = 28;

        if($time > 1435) // 1435 = 24h - 5min
            $time = 1435;

        try {
            $tz = $cfg ? $cfg->getDbTimezone() : null;
            $dbtz = $tz ? new DateTimeZone($tz) : null;
        } catch (Exception $e) {
            $dbtz = null;
        }
        $now = new DateTimeImmutable('now', $dbtz);

        // day 28 is last day of month
        $targetDay = ($day === 28) ? (int) $now->format('t') : $day;
        $currentDay = (int) $now->format('j');

        if ($currentDay !== $targetDay)
            return false;

        $currentMinuteOfDay = ((int) $now->format('G')) * 60 + (int) $now->format('i');

        return $currentMinuteOfDay >= $time;
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
        list ($__, $_N) = self::translate('closer');

        if ($this->DEBUG) {
            $this->LOG[]=sprintf($__('Setting status %s (%s) for ticket with ID %d :: %s')
                                    ,$new_status->getLocalName()
                                    ,$new_status->getState()
                                    ,$ticket->getId()
                                    ,$ticket->getSubject()
                             );
        }

        // set the new status
        $comments = '';
        $errors = [];
        $set_closing_agent = false;
        $force_close = false;

        return $ticket->setStatus($new_status, $comments, $errors, $set_closing_agent, $force_close)
               // Save it, flag prevents it refetching the ticket data straight away (inefficient)
               && $ticket->save(FALSE);
    }

    /**
     * Retrieves an array of ticket_id's from the database
     *
     * @param PluginConfig $config
     * @return array of integers that are Ticket::lookup compatible ID's of Open Tickets
     * @throws Exception so you have something interesting to read in your cron logs..
     */
    private function find_ticket_ids(PluginConfig &$config) {
        list ($__, $_N) = self::translate('closer');

        $whereFilter = $leftJoins = [];

        // Limit
        $max = (int) $config->get('purge-num') ?: 20;
        if ($max < 1)
            $max = 20;

        // Filter

        #### time span ###
        $cDates = [
            'c' => 't.created',         // from ticket table
            'u' => 't.lastupdate',      // from ticket table
            'm' => 'th.lastmessage',    // from thread table
            'r' => 'th.lastresponse',   // from thread table
            'd' => '1'                  // special handling for on day X
        ];

        $calculate_date = $config->get('calculate-date');
        $valid_calculate_dates = array_keys($cDates);

        if(!in_array($calculate_date, $valid_calculate_dates))
            throw new \Exception(sprintf($__('Invalid calculate date: (string) calculate-date needs to be: %s'),
                                         implode(', ', $valid_calculate_dates) )
                                );

        if($calculate_date !== 'd') { // on day X = we do not need a time span
            $age_days = (int) $config->get('purge-age');
            if ($age_days < 1)
                throw new \Exception($__('Invalid parameter (int) age_days needs to be > 0'));

            // do we need a left join?
            if(in_array($calculate_date, ['m','r']))
                $leftJoins[] = sprintf(" LEFT JOIN `%s` th ON (t.ticket_id = th.object_id AND th.object_type = 'T') ", THREAD_TABLE);
            $whereFilter[] = sprintf(' %s < DATE_SUB(NOW(), INTERVAL %d DAY)', $cDates[$calculate_date], $age_days);
        }

        #### only answered ###
        if($config->get('close-only-answered'))
            $whereFilter[] = 't.isanswered=1';
        #### only overdue ###
        if($config->get('close-only-overdue')) {
            // is overdue OR duedate is set and in the past OR duedate not set and est_duedate set and in the past
            $whereFilter[] = '(t.isoverdue=1 OR (t.duedate IS NOT NULL AND t.duedate < NOW()) OR (t.duedate IS NULL AND t.est_duedate < NOW()))';
        }

        ### help topic filter ###
        $help_topics_selector = $config->get('help-topic-selector'); // p=process, i=ignore
        $help_topics = $config->get('help-topics');
        // extract array keys as topic_ids, if help topics selected
        if (is_array($help_topics) && count($help_topics)) {
            $topic_ids = array_filter(array_map('intval', array_keys($help_topics)));
            if (count($topic_ids)) {
                $whereFilter[] = sprintf('t.topic_id %s (%s)',
                                         $help_topics_selector === 'i' ? 'NOT IN' : 'IN',
                                         implode(',', $topic_ids)
                                        );
            }
        }

        ### departments filter ###
        $department_selector = $config->get('department-selector'); // p=process, i=ignore
        $depts = $config->get('departments');
        // extract array keys as dept_ids, if departments selected
        if (is_array($depts) && count($depts)) {
            $dept_ids = array_filter(array_map('intval', array_keys($depts)));
            if (count($dept_ids)) {
                $whereFilter[] = sprintf('t.dept_id %s (%s)',
                                         $department_selector === 'i' ? 'NOT IN' : 'IN',
                                         implode(',', $dept_ids)
                                        );
            }
        }

        ### status filter ###
        $from_status = $config->get('from-status');
        $from_status_ids = [];
        if(is_array($from_status))
            $from_status_ids = array_filter(array_map('intval', array_keys($from_status)));
        elseif((int) $from_status > 0)
            $from_status_ids[] = (int) $from_status;
        // extract selected status_ids
        if (count($from_status_ids)) {
            $whereFilter[] = sprintf('t.status_id IN (%s)', implode(',', $from_status_ids));
        } else
            throw new \Exception("Invalid parameter (int) / (array) from_status needs to be > 0 or [> 0]");

        // Ticket query, note MySQL is doing all the date maths:
        // Sidebar: Why haven't we moved to PDO yet?
        /*
         * Attempt to do this with ORM $tickets = Ticket::objects()->filter( array(
         * 'lastupdate' => SqlFunction::DATEDIFF(SqlFunction::NOW(),
         * SqlInterval($age_days, 'DAY')), 'status_id' => $from_status, 'isanswered'
         * => 1, 'isoverdue' => 1 ))->all(); print_r($tickets);
         */

        $sql = sprintf("SELECT t.ticket_id FROM %s %s WHERE %s ORDER BY t.ticket_id ASC LIMIT %d",
                       TICKET_TABLE.' t',
                       implode(' ', $leftJoins),
                       implode(' AND ', $whereFilter),
                       $max
                      );

        if ($this->DEBUG) {
            $this->LOG[]=sprintf($__('Looking for tickets with query: %s'), $sql);
        }

        $r = db_query($sql);
        // Fill an array with just the ID's of the tickets:
        $ids = array();
        while ($i = db_fetch_array($r, MYSQLI_ASSOC)) {
            $ids[] = $i['ticket_id'];
        }

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
    function post_reply(Ticket $ticket, TicketStatus $new_status, $admin_reply, Staff|int|null $robot = null) {
        // We need to override this for the notifications
        global $thisstaff;
        list ($__, $_N) = self::translate('closer');

        if ($robot instanceof Staff) {
            $assignee = $robot;
        } elseif($robot == -2) { // send as system
            $assignee = null;
        } else {                // send as assigned agent
            $assignee = $ticket->getAssignee();
            if (!$assignee instanceof Staff) {
                // Nobody, or a Team was assigned, and we haven't been told to use a Robot account.
                $ticket->logNote($__('AutoCloser Error')
                                , $__('Unable to send reply. No assigned Agent on ticket and no Robot account specified in config.')
                                , self::PLUGIN_NAME, FALSE);
                return;
            }
        }
        // This actually bypasses any authentication/validation checks..
        $realThisstaff = $thisstaff ?? null;
        try {
            $thisstaff = $assignee;

            // Replace any ticket variables in the message:
            $variables = [
                'recipient' => $ticket->getOwner()
            ];

            // Provide extra variables.. because. :-)
            $options = [
                'wholethread' => 'fetch_whole_thread',
                'firstresponse' => 'fetch_first_response',
                'lastresponse' => 'fetch_last_response'
            ];

            // See if they've been used, if so, call the function
            foreach ($options as $option => $method) {
                if (strpos($admin_reply, $option) !== FALSE) {
                    $variables[$option] = $this->{$method}($ticket);
                }
            }

            // Use the Ticket objects own replaceVars method, which replace
            // any other Ticket variables.
            $custom_reply = $ticket->replaceVars($admin_reply, $variables);

            // Build an array of values to send to the ticket's postReply function
            // 'emailcollab' => FALSE // don't send notification to all collaborators.. maybe.. dunno.
            $vars = [
                'reply-to' => 'all',
                'response' => $custom_reply
            ];
            $errors = [];

            if(!$thisstaff) { // send as system
                $vars['poster'] = $__('SYSTEM');
            }

            // Send the alert without claiming the ticket on our assignee's behalf.
            if (!$sent = $ticket->postReply($vars, $errors, TRUE, FALSE)) {
                $ticket->LogNote($__('Error Notification'), $__('We were unable to post a reply to the ticket creator.'), self::PLUGIN_NAME, FALSE);
            }
        } finally {
            $thisstaff = $realThisstaff;
        }
    }

    /**
     * Fetches the first response sent to the ticket Owner
     *
     * @param Ticket $ticket
     * @return string
     */
    private function fetch_first_response(Ticket $ticket) {
        // Apparently the ORM is fighting me.. it doesn't like me
        $thread = $ticket->getThread();
        if (!$thread instanceof Thread) {
            return '';
        }
        foreach ($thread->getEntries()->all() as $entry) {
            if ($this->is_valid_thread_entry($entry, FALSE, TRUE)) {
                // this is actually a Response. yes..
                return $this->render_thread_entry($entry);
            }
        }
        return ''; // the empty string overwrites the template
    }

    /**
     * Fetches the last response sent to the ticket Owner.
     *
     * @param Ticket $ticket
     * @return string
     */
    private function fetch_last_response(Ticket $ticket) {
        $thread = $ticket->getThread();
        if (!$thread instanceof Thread) {
            return '';
        }

        $last = '';
        // Can't seem to get this sorted in reverse.. thought I had it, but nope.
        foreach ($thread->getEntries()->all() as $entry) {
            if ($this->is_valid_thread_entry($entry, FALSE, TRUE)) {
                // We'll just render each response, overwriting the previous one..
                // screw it. 
                $last = $this->render_thread_entry($entry);
            }
        }
        return $last; // the empty string overwrites the template
    }

    /**
     * Fetches the whole thread that the client can see. As an HTML message.
     *
     * @param Ticket $ticket
     * @return string
     */
    private function fetch_whole_thread(Ticket $ticket) {
        $msg = '';
        $thread = $ticket->getThread();
        if (!$thread instanceof Thread) {
            return $msg;
        }

        // Iterate through all the thread entries (in order), 
        // Not sure the ->order_by() thing even does anything.
        foreach ($thread->getEntries()
                ->order_by('created', QuerySet::ASC)
                ->all() as $entry) {
            // Test each entries data-model, and the type of entry from it's model
            if ($this->is_valid_thread_entry($entry, TRUE, TRUE)) {
                // this is actually a Response or Message yes..
                $msg .= $this->render_thread_entry($entry);
            }
        }
        return $msg;
    }

    /**
     * Renders a ThreadEntry as HTML.
     *
     * @param AnnotatedModel $entry
     * @return string
     */
    private function render_thread_entry(AnnotatedModel $entry) {
        list ($__, $_N) = self::translate('closer');

        $from = ($entry->get('type') == 'R') ? $__('Sent Date') : $__('Received Date');
        $tag = ($entry->get('format') == 'text') ? 'pre' : 'div';
        $when = Format::datetime(strtotime($entry->get('created')));
        // TODO: Maybe make this a CannedResponse or admin template?
        $title = Format::htmlchars($entry->get('title'));
        return <<<PIECE
<hr />
<div class="thread">
  <h3>$title</h3>
  <p>$from: $when</p>
  <$tag>{$entry->model->getBody()}</$tag>
</div>
PIECE;
    }

    /**
     * $entry should be an AnnotatedModel object, however, we need to check that
     * it's actually a type of ThreadEntry, therefore we need to interrogate the
     * Object inside it. Would be good if the $ticket->getResponses() method
     * worked..
     *
     * @param AnnotatedModel $entry
     * @param bool $message
     * @param bool $response
     * @return boolean
     */
    private function is_valid_thread_entry(AnnotatedModel $entry, $message = FALSE, $response = FALSE) {
        list ($__, $_N) = self::translate('closer');

        if (!$entry->model instanceof ThreadEntry) {
            return FALSE;
        }
        if (!$message && !$response) {
            // you gotta pick one ..
            return FALSE;
        }
        if ($this->DEBUG) {
            $this->LOG[]=sprintf($__("Testing thread entry: %s : %s\n"), $entry->get('type'), $entry->get('title'));
        }
        if (isset($entry->model->ht['type'])) {
            if ($response && $entry->get('type') == 'R') {
                // this is actually a Response
                return TRUE;
            } elseif ($message && $entry->get('type') == 'M') {
                // this is actually a Message
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Required stub.
     *
     * {@inheritdoc}
     *
     * @see Plugin::uninstall()
     */
    function uninstall(&$errors) {
        list ($__, $_N) = self::translate('closer');

        global $ost;
        // Send an alert to the system admin:
        $ost->alertAdmin(sprintf($__('%s has been uninstalled'), self::PLUGIN_NAME), $__('You wanted that right?'), true);

        parent::uninstall($errors);
    }

    /**
     * Plugins seem to want this.
     */
    public function getForm() {
        return array();
    }

    /**
     * Outputs all log entries to the syslog
     *
     */
    private function print2log() {
        global $ost;
        if (empty($this->LOG)) {return false;}
        $msg='';
        foreach($this->LOG as $key=>$value) {$msg.=$value."\n";}
        $ost->logWarning(self::PLUGIN_NAME, $msg, false);
         // reset LOG
         $this->LOG = [];
    }
}
