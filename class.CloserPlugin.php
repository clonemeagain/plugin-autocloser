<?php
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once (INCLUDE_DIR . 'class.signal.php');
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
    const DEBUG = FALSE;

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
        Signal::connect('cron', function () {
            $this->logans_run_mode();
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
            
            // Find any old tickets that we might need to work on:
            $open_ticket_ids = $this->findOldTicketIds($config);
            if (self::DEBUG)
                error_log("CloserPlugin found " . count($open_ticket_ids));
            
            // Bail if there's no work to do
            if (! count($open_ticket_ids))
                return;
            
            // Fetch the ticket status that indicates the ticket is closed,
            // or, whatever the admin specified:
            // It's 3 on mine.. but other languages exist.. so, it might not be
            // the word "closed" in another language. Use the ID instead:
            $closed_status = TicketStatus::lookup($config->get('closed-status'));
            $closed_time = SqlFunction::NOW();
            $admin_note = $config->get('admin-note');
            $admin_reply = $config->get('admin-reply');
            
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
                        if (strlen($admin_note)) {
                            $ticket->getThread()->addNote(array(
                                'note' => $admin_note // Posts Note as SYSTEM, no ticket vars, no email alert
                            ), $errors);
                        }
                        
                        // Post Reply to the user, telling them the ticket is closed, relates to issue #2
                        if (strlen($admin_reply)) {
                            // Replace any ticket variables in the message:
                            $custom_reply = $ticket->replaceVars($admin_reply, array(
                                'recipient' => $ticket->getOwner() // send as the assigned staff.. sneaky
                            ));
                            // Send the alert. TRUE flag indicates send the email alert..
                            $ticket->postReply(array(
                                'response' => $custom_reply
                            ), $errors, TRUE);
                        }
                        
                        if (self::DEBUG)
                            error_log("Closing ticket {$ticket_id}::{$ticket->getSubject()}");
                        
                        // This is the part that actually "Closes" the tickets
                        //
                        // Well, depending on the admin settings I mean.
                        //
                        // Could use $ticket->setStatus($closed_status) function
                        // however, this gives us control over _how_ it is closed.
                        // preventing accidentally making any logged-in staff
                        // associated with the closure, which is an issue with AutoCron
                        
                        // Start by setting the last update and closed timestamps to now
                        $ticket->closed = $ticket->lastupdate = $closed_time;
                        
                        // Remove any duedate or overdue flags
                        $ticket->duedate = null;
                        $ticket->clearOverdue(FALSE); // flag prevents saving, we'll do that
                                                      
                        // Post an Event with the current timestamp. Could be confusing if a non-closed end-status selected.. hmm.
                        $ticket->logEvent('closed', array(
                            'status' => array(
                                $closed_status->getId(),
                                $closed_status->getName()
                            )
                        ));
                        // Actually apply the "TicketStatus" to the Ticket.
                        $ticket->status = $closed_status;
                        
                        // Save it, flag prevents it refetching the ticket data straight away (inefficient)
                        $ticket->save(FALSE);
                    } else {
                        error_log("Unable to close ticket {$ticket->getSubject()}, check it manually: id# {$ticket_id}");
                    }
                }
            }
        }
    }

    /**
     * Retrieves an array of ticket_id's from the database
     *
     * Filtered to only show those that are still open for more than $age_days, oldest first.
     *
     * Could be made static so other classes can find old tickets..
     *
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
    private function findOldTicketIds($config)
    {
        // fetch config for finder:
        $age_days = (int) $config->get('purge-age');
        $max = (int) $config->get('purge-num');
        $onlyAnswered = (bool) $config->get('close-only-answered');
        $onlyOverdue = (bool) $config->get('close-only-overdue');
        $from_status = (int) $config->get('from-status');
        
        $whereFilter = '';
        
        if ($onlyAnswered) {
            $whereFilter .= ' AND isanswered=1';
        }
        
        if ($onlyOverdue) {
            $whereFilter .= ' AND isoverdue=1';
        }
        
        if (! $age_days || ! is_numeric($age_days))
            throw new Exception("No max age specified, or [$age_days] can't be used as a number.");
        
        // Ticket query, note MySQL is doing all the date maths:
        // Sidebar: Why haven't we moved to PDO yet?
        $sql = sprintf('
            SELECT ticket_id
            FROM %s
            WHERE status_id = %d AND lastupdate > DATE_SUB(NOW(), INTERVAL %d DAY)
            %s
            ORDER BY ticket_id ASC
            LIMIT %d', TICKET_TABLE, $from_status, $age_days, $whereFilter, $max);
        
        if (self::DEBUG)
            error_log("Looking for old tickets with query: $sql");
        
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