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
        
        // I'm not 100% sure that closed status has id 3 for everyone.
        // Let's just get all available Statuses and show a selectbox:
        static $statuses = array();
        // Doesn't appear to be a TicketStatus list that I want to use..
        if (! $statuses) {
            foreach (TicketStatus::objects()->values_flat('id', 'name') as $s) {
                list ($id, $name) = $s;
                $statuses[$id] = $name;
            }
        }
        
        $global_settings = array(
            'global' => new SectionBreakField(array(
                'label' => $__('Global Config')
            )),
            'purge-age' => new TextboxField(array(
                'default' => '999',
                'label' => $__('Max open Ticket age in days'),
                'hint' => $__('Tickets with no updates in this many days will match and have their status changed.'),
                'size' => 5,
                'length' => 4
            )),
            'close-only-answered' => new BooleanField(array(
                'default' => TRUE,
                'label' => $__('Only close tickets with an Agent Response'),
                'hint' => ''
            )),
            'close-only-overdue' => new BooleanField(array(
                'default' => FALSE,
                'label' => $__('Only close tickets past expiry date'),
                'hint' => $__('Default ignores expiry')
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
                    '168' => $__('Every Week'), // This is how much banked Annual Leave I have in my day-job.. noice
                    '730' => $__('Every Month'),
                    '8760' => $__('Every Year')
                ),
                'default' => '2',
                'hint' => $__("How often should we check for old tickets?")
            )),
            'use_autocron' => new BooleanField(array(
                'label' => $__('Use Autocron'),
                'default' => 0,
                'hint' => $__('If you only have auto-cron, you will want this on.')
            )),
            'purge-num' => new TextboxField(array(
                'label' => $__('Tickets to close per run'),
                'hint' => $__("How many old tickets should we close each time? (small for auto-cron)"),
                'default' => 20
            )),
            'groups' => new TextboxField(array(
                'label' => $__('Group Number'),
                'hint' => $__('Specify how many groups to make, save twice to apply'),
                'default' => 1
            ))
        
        );
        
        // Configure groups to associate a status change with a canned response notification:
        // How many groups are there?
        // We have to devolve the ConfigItem's value:
        $groups = $this->config['groups']->ht['value'] ?: 1;
        
        if (! $groups) {
            $global_settings['error'] = new SectionBreakField(array(
                'label' => $__('Add groups to associate statuses and canned responses')
            ));
            return $global_settings;
        }
        
        // Get all the canned responses to use as selections:
        $responses = Canned::getCannedResponses();
        
        // Build an array of group configurations:
        $canned_to_status_groups = array();
        for ($i = 1; $i <= $groups; $i ++) {
            $gn = $this->get('group-name' . $i);
            $gn = $gn ? ': ' . $gn : '';
            $canned_to_status_groups[] = array(
                'group' . $i => new SectionBreakField(array(
                    'label' => $__('Group ' . $i . $gn)
                )),
                'group-name' . $i => new TextboxField(array(
                    'label' => 'Groupname',
                    'hint' => $__('Used to identify this group on this page only')
                
                )),
                'from-status' . $i => new ChoiceField(array(
                    'label' => $__('From Status'),
                    'choices' => $statuses,
                    'default' => 1,
                    'hint' => $__('When we "close" the ticket, what are we changing the status from? Default is "Open"')
                )),
                'closed-status' . $i => new ChoiceField(array(
                    'label' => $__('To Status'),
                    'choices' => $statuses,
                    'default' => 3,
                    'hint' => $__('When we "close" the ticket, what are we changing the status to? Default is "Closed"')
                )),
                
                'admin-note' . $i => new TextboxField(array(
                    'label' => $__('Auto-Note'),
                    'hint' => $__('Create\'s an admin note just before closing.'),
                    'default' => 'Auto-closed for being open too long with no updates.'
                )),
                'admin-reply' . $i => new ChoiceField(array(
                    'label' => $__('Auto-Reply Canned Response'),
                    'hint' => $__('Select a canned response to use as a reply just before closing (can use Variables, set this to add another)'),
                    'choices' => $responses
                ))
            
            );
        }
        
        // Merge all the group configurations after the global settings array and return as our config Options Array:
        return array_merge($global_settings, ...$canned_to_status_groups);
    }

/**
 * Customize our form..
 * a bit.
 *
 * function getForm()
 * {
 * if (! isset($this->form)) {
 * $options = array(
 * 'title' => "Auto-Closer Plugin Configuration",
 * 'instructions' => 'Testing porpoises only!',
 * 'template' => dirname(__FILE__) . '/form.tmpl.php'
 * );
 * $this->form = new SimpleForm($this->getOptions(), null, $options);
 * if ($_SERVER['REQUEST_METHOD'] != 'POST')
 * $this->form->data($this->getInfo());
 * }
 * return $this->form;
 * }
 */
}