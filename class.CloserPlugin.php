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

    /**
     * Which config class to load
     *
     * @var string
     */
    var $config_class = 'CloserPluginConfig';

    /**
     * Set to TRUE to enable webserver logging, and extra logging.
     *
     * @var boolean
     */
    const DEBUG = TRUE;

    /**
     * Hook the bootstrap process, wait for tickets to be created.
     *
     * Run on every instantiation, so needs to be concise.
     *
     * {@inheritdoc}
     *
     * @see Plugin::bootstrap()
     */
    public function bootstrap()
    {
        if (self::DUMPWHOLETHING) {
            $this->log("Bootstrappin Closer..");
        }
        // Listen for cron complete calls Signal::send('cron', null, $data) at end of class.cron.php:
        Signal::connect('cron', function ($ignored, $ignored) {
            if (self::DEBUG) {
                $this->log("Received cron signal");
            }
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
        
        // Instead of storing "next-run", we store "last-run", then compare frequency, in case frequency changes.
        $last_run = $config->get('last-run');
        $now = time(); // Assume server timezone doesn't change enough to break this
                       
        // Find purge frequency in a comparable format, seconds:
        $freq_in_seconds = (int) $config->get('purge-frequency') * 60 * 60;
        
        // Calculate when we want to run next:
        $next_run = $last_run + $freq_in_seconds;
        
        // Compare intention with reality:
        if (self::DEBUG || ! $next_run || $now > $next_run) {
            $config->set('last-run', $now);
            
            // Fetch the rest of the admin settings, now that we're actually going through with this:
            $age_days = (int) $config->get('purge-age');
            $max_purge = (int) $config->get('purge-num');
            
            foreach ($this->findOldTickets($age_days, $max_purge) as $ticket_id) {
                // Fetch the ticket
                $t = Ticket::lookup($ticket_id);
                if ($t instanceof Ticket) {
                    // Post message to thread indicating it was closed because it hasn't been updated in X days.
                    if ($msg = $config->get('admin-message')) {
                        $t->getThread()->addNote(array(
                            'note' => $msg
                        ));
                    }
                    if (self::DEBUG) {
                        error_log("Closing ticket {$t->getId()}::{$t->getSubject()}");
                    }
                    // get out of here!
                    $t->close();
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
     * @return array
     * @param int $age_days
     *            admin configuration max-age for an un-updated ticket.
     * @param int $max_purge
     *            how many are we marking as closed each cron run?
     */
    private function findOldTickets($age_days, $max_purge = 20)
    {
        if (! $age_days) {
            return array();
        }
        
        $ids = array();
        $r = db_query('SELECT ticket_id FROM ' . TICKET_TABLE . ' WHERE lastupdate > DATE_SUB(NOW(), INTERVAL ' . $age_days . ' DAY) ORDER BY ticket_id ASC LIMIT ' . $max_purge);
        while ($i = db_fetch_array($r)) {
            $ids[] = $i['ticket_id'];
        }
        if (self::DEBUG)
            error_log("Deleting " . count($ids));
        
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