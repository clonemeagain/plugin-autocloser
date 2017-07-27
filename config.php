<?php
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.message.php';

class CloserPluginConfig extends PluginConfig
{

    // Provide compatibility function for versions of osTicket prior to
    // translation support (v1.9.4)
    function translate()
    {
        if (! method_exists('Plugin', 'translate')) {
            return array(
                function ($x) {
                    return $x;
                },
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
            );
        }
        return Plugin::translate('closer');
    }

    /**
     * Build an Admin settings page.
     *
     * {@inheritdoc}
     *
     * @see PluginConfig::getOptions()
     */
    function getOptions()
    {
        list ($__, $_N) = self::translate();
        
        // I'm not 100% that closed status id=3 is the same for everyone.
        // I'll create a select-box so the admin can pick what status to change them to, from the available
        // statuses. status's.. statusii? stati? statūs? (no, we don't speak Latin), statuses will do.
        $res = db_query("SELECT id,name FROM " . TICKET_STATUS_TABLE);
        $statuses = array();
        while ($row = db_fetch_array($res, MYSQLI_ASSOC)) {
            $statuses[$row['id']] = $row['name'];
        }
        
        return array(
            'purge-age' => new TextboxField(array(
                'default' => '999',
                'label' => $__('Max open Ticket age in days'),
                'hint' => $__('Tickets with no updates in this many days will match and have their status changed.'),
                'size' => 5,
                'length' => 4 // 4 digits allows for 27 years (9999 days)
            )),
            'closed-status' => new ChoiceField(array(
                'label' => $__('Set Status'),
                'choices' => $statuses,
                'default' => 3,
                'hint' => $__('When we "close" the ticket, what are we changing the status to? Default is "Closed"')
            )),
            'purge-frequency' => new ChoiceField(array(
                'label' => $__('Check Frequency'),
                'choices' => array(
                    '0' => $__('Every Cron'),
                    '1' => $__('Every Hour'),
                    '2' => $__('Every 2 Hours'),
                    '6' => $__('Every 6 Hours'),
                    '12' => $__('Every 12 Hours'),
                    '24' => $__('Every 1 Day'),
                    '36' => $__('Every 36 Hours'),
                    '48' => $__('Every 2 Days'),
                    '72' => $__('Every 72 Hours'),
                    '168' => $__('Every Week'),
                    '730' => $__('Every Month'),
                    '8760' => $__('Every Year')
                ),
                'default' => '2',
                'hint' => $__("How often should we check for old tickets?")
            )),
            'purge-num' => new TextboxField(array(
                'label' => $__('Tickets to close per run'),
                'hint' => $__("How many old tickets should we close each time? (small for auto-cron)"),
                'default' => 20
            )),
            'admin-note' => new TextareaField(array(
                'label' => $__('Auto-Note'),
                'hint' => $__('Create\'s an admin note just before closing.'),
                'default' => 'Auto-closed for being open too long with no updates.',
                'configuration' => array(
                    'html' => TRUE,
                    'size' => 40,
                    'length' => 256
                )
            ))
        
        );
    }
}