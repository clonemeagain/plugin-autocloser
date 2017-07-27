<?php
require_once (INCLUDE_DIR . 'class.format.php');
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
        Signal::connect('cron', function () {
            // Look for old open tickets, close em.
            $this->logans_run_mode();
        });
    }

    /**
     * Closes old tickets..
     *
     * = Welcome to the 23rd Century.
     * The perfect world of total pleasure.
     *
     * ... there's just one catch.
     */
    private function logans_run_mode()
    {
        $config = $this->getConfig();
        
        if (self::DEBUG)
            print_r($config);
        
        // We can store arbitrary things in the config, like, when we ran this last:
        $last_run = $config->get('last-run');
        $now = time(); // Assume server timezone doesn't change enough to break this
        $config->set('last-run', $now);
        
        // Find purge frequency in a comparable format, seconds:
        if ($freq_in_config = (int) $config->get('purge-frequency')) {
            // Calculate when we want to run next:
            $next_run = $last_run + ($freq_in_config * 60 * 60);
        } else {
            $next_run = 0; // assume $freq of "Every Cron" means it is always overdue for a run.
        }
        
        // Must be time to run the checker:
        if (self::DEBUG || ! $next_run || $now > $next_run) {
            // Find any old tickets that we might need to work on:
            $open_ticket_ids = $this->findOldTickets($config->get('purge-age'), $config->get('purge-num'));
            if (self::DEBUG)
                error_log("CloserPlugin found " . count($open_ticket_ids));
            
            // Bail if there's no work to do:
            if (! count($open_ticket_ids))
                return;
            
            // Fetch the ticket status that indicates the ticket is closed,
            // or, whatever the admin specified:
            // It's 3 on mine.. but other languages exist.. so, it might not be
            // the word "closed" in another language. Use the ID instead:
            $closed_status = TicketStatus::lookup($config->get('closed-status'));
            $closed_time = SqlFunction::NOW(); // cached for multiple uses
            $admin_note = $config->get('admin-note');
            
            // Go through the old tickets, close em:
            foreach ($open_ticket_ids as $ticket_id) {
                // Fetch the ticket as an Object, let's us call ->save() on it when we're done.
                $ticket = Ticket::lookup($ticket_id);
                if ($ticket instanceof Ticket) {
                    // Some tickets aren't closeable.. either because of open tasks, or missing fields.
                    if ($ticket->isCloseable()) {
                        
                        // Post message to thread indicating it was closed because it hasn't been updated in X days.
                        if (strlen($admin_note)) {
                            $ticket->getThread()->addNote(array(
                                // TODO: we could even supply a template to reply to the User.. if required.
                                'note' => $admin_note
                            ));
                        }
                        if (self::DEBUG) {
                            error_log("Closing ticket {$ticket_id}::{$ticket->getSubject()}");
                        }
                        
                        // Could use $ticket->setStatus($closed_status) function
                        // however, this gives us control over _how_ it is closed.
                        // preventing accidentally making any logged-in staff
                        // associated with the closure, which is an issue with AutoCron
                        $ticket->closed = $ticket->lastupdate = $closed_time;
                        $ticket->duedate = null;
                        $ticket->clearOverdue(FALSE);
                        $ticket->logEvent('closed', array(
                            'status' => array(
                                $closed_status->getId(),
                                $closed_status->getName()
                            )
                        ));
                        $ticket->status = $closed_status;
                        $ticket->save();
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
     * @return array of integers that are Ticket::lookup compatible ID's of Open Tickets
     * @throws Exception so you have something interesting to read in your cron logs..
     */
    private function findOldTickets($age_days, $max = 20)
    {
        // Again, we're not 100% sure what status-id each install
        // will use as the default or "Open" ticket status.
        // So, we'll attempt to use what the system uses when creating a ticket,
        // and just kinda hope it works.
        global $cfg;
        if (! $cfg instanceof OsticketConfig)
            throw new Exception("Unable to use cfg as it isn't an OsticketConfig object.");
        
        $open_status = $cfg->getDefaultTicketStatusId();
        // todo: Do we verify this $open_status exists? Or just use it.. shit.
        
        if (! $age_days)
            throw new Exception("No max age specified.");
        
        // Ticket query, note MySQL is doing all the date maths:
        // Sidebar: Why haven't we moved to PDO yet?
        $sql = sprintf('
            SELECT ticket_id
            FROM %s
            WHERE status_id = %d AND lastupdate > DATE_SUB(NOW(), INTERVAL %d DAY)
            ORDER BY ticket_id ASC
            LIMIT %d', TICKET_TABLE, $open_status, $age_days, $max);
        
        if (self::DEBUG)
            error_log("Running query: $sql");
        
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
        // Do we send an email to the admin telling him about the space used by the archive?
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