<?php
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once (INCLUDE_DIR . 'class.signal.php');
require_once (INCLUDE_DIR . 'class.canned.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to close tickets when they get old.
 * Logans Run style.
 */
class CloserPlugin extends Plugin
{

    var $config_class = 'CloserPluginConfig';

    /**
     * Set to TRUE to enable extra logging.
     *
     * @var boolean
     */
    const DEBUG = TRUE;

    /**
     * Hook the bootstrap process
     *
     * Run on every instantiation, so needs to be concise.
     *
     * {@inheritdoc}
     *
     * @see Plugin::bootstrap()
     */
    public function bootstrap()
    {
        // Listen for cron Signal, which only happens at end of class.cron.php:
        Signal::connect('cron', function ($ignored, $data) {
            
            // Autocron is an admin option, we can filter out Autocron Signals
            // to ensure changing state for potentially hundreds/thousands
            // of tickets doesn't affect interactive Agent/User experience.
            $use_autocron = $this->getConfig()->get('use_autocron');
            
            // Autocron Cron Signals are sent with this array key set to TRUE
            $is_autocron = (isset($data['autocron']) && $data['autocron']);
            
            // Normal cron isn't Autocron:
            if (! $is_autocron || ($use_autocron && $is_autocron)) {
                $this->logans_run_mode();
            }
        });
    }

    /**
     * Closes old tickets..
     * with extreme prejudice.. or, regular prejudice.. whatever.
     *
     * = Welcome to the 23rd Century.
     * The perfect world of total pleasure.
     *
     * ... there's just one catch.
     */
    private function logans_run_mode()
    {
        $config = $this->getConfig();
        
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
            $next_run = $last_run + ($freq_in_config * 60 * 60);
        }
        
        // See if it's time to check old tickets
        // Always run when in DEBUG mode.. because waiting for the scheduler is slow
        // If we don't have a next_run, it's because we want it to run
        // If the next run is in the past, then we are overdue, so, lets go!
        if (self::DEBUG || ! $next_run || $now > $next_run) {
            
            $sql_now = SqlFunction::NOW();
            
            $max_per_run = (int) $config->get('purge-num');
            
            // Use the numbner of config groups to run the closer as many times as is needed.
            foreach (range(1, CloserPluginConfig::NUMBER_OF_SETTINGS) as $group_id) {
                if (! $config->get('group-enabled-' . $group_id)) {
                    if (self::DEBUG)
                        error_log("Group $group_id is not enabled.");
                    continue;
                } elseif (self::DEBUG) {
                    error_log("Running group $group_id");
                }
                
                // Build an array of settings for this task:
                // fetch config for finder:
                $age_days = (int) $config->get('purge-age-' . $group_id);
                
                $onlyAnswered = (bool) $config->get('close-only-answered-' . $group_id);
                $onlyOverdue = (bool) $config->get('close-only-overdue-' . $group_id);
                
                if (self::DEBUG)
                    $group_name = $config->get('group-name-' . $group_id); // for logging
                $from_status = (int) $config->get('from-status-' . $group_id);
                $to_status = (int) $config->get('to-status-' . $group_id);
                
                // Find tickets that we might need to work on:
                $open_ticket_ids = $this->findTicketIds($from_status, $age_days, $max_per_run, $onlyAnswered, $onlyOverdue);
                if (self::DEBUG)
                    error_log("CloserPlugin group [$group_id] $group_name has " . count($open_ticket_ids) . " open tickets.");
                
                // Bail if there's no work to do
                if (! count($open_ticket_ids))
                    continue; // Not return, as the next group might have work.
                              
                // Gather the resources required to start changing statuses:
                $new_status = TicketStatus::lookup($to_status);
                $admin_note = $config->get('admin-note-' . $group_id) ?: FALSE;
                $admin_reply = ($config->get('admin-reply-' . $group_id)) ? Canned::lookup($config->get('admin-reply-' . $group_id)) : FALSE;
                
                if ($admin_reply) {
                    // Fetch the actual content of the reply:
                    $admin_reply = $admin_reply->getFormattedResponse('html');
                }
                
                if (self::DEBUG) {
                    print "Found the following details:\nAdmin Note: $admin_note\n\nAdmin Reply: $admin_reply\n";
                }
                
                // Go through the old tickets, close em:
                foreach ($open_ticket_ids as $ticket_id) {
                    
                    // Fetch the ticket as an Object, let's us call ->save() on it when we're done.
                    $ticket = Ticket::lookup($ticket_id);
                    if ($ticket instanceof Ticket) {
                        
                        // Some tickets aren't closeable.. either because of open tasks, or missing fields.
                        // we can therefore only work on closeable tickets.
                        if ($ticket->isCloseable()) {
                            // We ignore any posting errors, but the functions like to take an array anyway
                            $errors = array();
                            
                            // Post Note to thread indicating it was closed because it hasn't been updated in X days.
                            if ($admin_note) {
                                $ticket->getThread()->addNote(array(
                                    'note' => $admin_note // Posts Note as SYSTEM, no ticket vars, no email alert
                                ), $errors);
                            }
                            
                            // Post Reply to the user, telling them the ticket is closed, relates to issue #2
                            if ($admin_reply) {
                                // Replace any ticket variables in the message:
                                $custom_reply = $ticket->replaceVars($admin_reply, array(
                                    'recipient' => $ticket->getOwner() // send as the assigned staff.. sneaky
                                ));
                                // Send the alert. TRUE flag indicates send the email alert..
                                $ticket->postReply(array(
                                    'response' => $custom_reply
                                ), $errors, TRUE);
                            }
                            
                            $this->closeTicket($ticket, $sql_now, $new_status);
                            $done ++;
                        } else {
                            error_log("Unable to close ticket {$ticket->getSubject()}.");
                        }
                    } else {
                        error_log("ticket $ticket_id is not instatiable. :-(");
                    }
                }
            }
        }
    }

    /**
     * This is the part that actually "Closes" the tickets
     *
     * Well, depending on the admin settings I mean.
     *
     * Could use $ticket->setStatus($closed_status) function
     * however, this gives us control over _how_ it is closed.
     * preventing accidentally making any logged-in staff
     * associated with the closure, which is an issue with AutoCron
     *
     * @param Ticket $ticket
     * @param SqlFunction $sql_now
     * @param TicketStatus $new_status
     */
    private function closeTicket($ticket, $sql_now, $new_status)
    {
        if (self::DEBUG)
            error_log("Setting status " . $new_status->getState() . " for ticket {$ticket->getId()}::{$ticket->getSubject()}");
        
        // Start by setting the last update and closed timestamps to now
        $ticket->closed = $ticket->lastupdate = $sql_now;
        
        // Remove any duedate or overdue flags
        $ticket->duedate = null;
        $ticket->clearOverdue(FALSE); // flag prevents saving, we'll do that
                                      
        // Post an Event with the current timestamp.
        $ticket->logEvent($new_status->getState(), array(
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
     * Filtered to only show those that are still open for more than $age_days, oldest first.
     *
     * Could be made static so other classes can find old tickets..
     *
     * @param int $from_status
     *            the id of the status to select tickets from
     * @param int $age_days
     *            admin configuration max-age for an un-updated ticket.
     * @param int $max
     *            don't find more than this many at once
     * @param bool $onlyAnswered
     *            set to true to filter tickets to only those that have an agent answer
     * @param bool $onlyOverdue
     *            set to true to filter tickets to only those that are overdue
     * @return array of integers that are Ticket::lookup compatible ID's of Open Tickets
     * @throws Exception so you have something interesting to read in your cron logs..
     */
    private function findTicketIds($from_status, $age_days, $max, $onlyAnswered = FALSE, $onlyOverdue = FALSE)
    {
        if (! $from_status)
            throw new \Exception("Invalid parameter (int) from_status needs to be >0");
        
        if ($age_days < 1)
            throw new \Exception("Invalid parameter (int) age_days needs to be > 0");
        
        if ($max < 1)
            throw new \Exception("Invalid parameter (int) max needs to be > 0");
        
        $whereFilter = '';
        
        if ($onlyAnswered)
            $whereFilter .= ' AND isanswered=1';
        
        if ($onlyOverdue)
            $whereFilter .= ' AND isoverdue=1';
        
        // Ticket query, note MySQL is doing all the date maths:
        // Sidebar: Why haven't we moved to PDO yet?
        $sql = sprintf("
SELECT ticket_id 
FROM %s WHERE lastupdate < DATE_SUB(NOW(), INTERVAL %d DAY)
AND status_id=%d %s
ORDER BY ticket_id ASC
LIMIT %d", TICKET_TABLE, $age_days, $from_status, $whereFilter, $max);
        
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
     * Required stub.
     *
     * {@inheritdoc}
     *
     * @see Plugin::uninstall()
     */
    function uninstall()
    {
        $errors = array();
        global $ost;
        $ost->alertAdmin('Plugin: Closer has been uninstalled', "Old open tickets will remain active.", true);
        
        parent::uninstall($errors);
    }

    /**
     * Plugins seem to want this.
     */
    public function getForm()
    {
        return array();
    }
}