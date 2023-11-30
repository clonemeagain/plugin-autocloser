<?php
/**
 * @file config.php :: 
 * @  requires osTicket 1.17+ & PHP8.0+
 * @  multi-instance: yes
 *
 * @author Grizly <clonemeagain@gmail.com>
 * @see https://github.com/clonemeagain/plugin-autocloser
 * @fork by Cartmega <www.cartmega.com>
 * @see https://github.com/Cartmega/plugin-autocloser 
 */
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.message.php';

class CloserPluginConfig extends PluginConfig {

    // Provide compatibility function for versions of osTicket prior to
    // translation support (v1.9.4)
    function translate() {
        if (!method_exists('Plugin', 'translate')) {
            return [
                function ($x) {
                    return $x;
                },
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
            ];
        }
        return Plugin::translate('closer');
    }

    function pre_save(&$config, &$errors) {
        list ($__, $_N) = self::translate();

        // Validate the free-text fields of numerical configurations are in fact numerical..
        if (isset($config['purge-num']) &&
	        !is_numeric($config['purge-num'])) {
	            $errors['err'] = $__('Only a numeric value is valid for Purge Number.');
	            return FALSE;
	        }
	
            if (isset($config['purge-age']) &&
                    !is_numeric($config['purge-age'])) {
                $errors['err'] = $__(
                        'Max Ticket age only supports numeric values.');
                return FALSE;
            }
            if (!(isset($config['robot-account'])) || ($config[('robot-account')]==0)) {
            	//echo '<pre>'.print_r('robot-account'.' is not set "'.$config[('robot-account')].'"',2).'</pre>';
                $errors['err'] = $__('Please choose a robot-account.');
                return FALSE;            	
            }
            if (!(isset($config['admin-reply'])) || ($config[('admin-reply')]==0)) {
            	//echo '<pre>'.print_r('admin-reply'.' is not set "'.$config[('admin-reply')].'"',2).'</pre>';
                $errors['err'] = $__('Please choose an admin-reply.');
                return FALSE;            	
            }

        return TRUE;
    }

    /**
     * Build an Admin settings page.
     *
     * {@inheritdoc}
     *
     * @see PluginConfig::getOptions()
     */
    function getOptions() {
        list ($__, $_N) = self::translate();

        // I'm not 100% sure that closed status has id 3 for everyone.
        // Let's just get all available Statuses and show a selectbox:
        $responses = $staff = $statuses = [];

        // Doesn't appear to be a TicketStatus list that I want to use..
        foreach (TicketStatus::objects()->values_flat('id', 'name') as $s) {
            list ($id, $name) = $s;
            $statuses[$id] = $name;
        }
        // Build array of Agents
        $staff[-1] = $__('ONLY Send as Ticket\'s Assigned Agent');
        foreach (Staff::objects() as $s) {
            $staff[$s->getId()] = (string) $s->getName();
        }

        $global_settings = [
            'global' => new SectionBreakField(
                    [
                'label' => $__('Global Config')
                    ]),
            'frequency' => new ChoiceField(
                    [
                'label' => $__('Check Frequency'),
                'choices' => [
                    '-1' => $__('Every Cron'),
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
                ],
                'default' => '2',
                'hint' => $__("How often should we run?")
                    ]),
            'use_autocron' => new BooleanField(
                    [
                'label' => $__('Use Autocron'),
                'default' => 0,
                'hint' => $__('If you only have auto-cron, you will want this on.')
                    ]),
            'purge-num' => new TextboxField(
                    [
                'label' => $__('Tickets to process per run'),
                'hint' => $__(
                        "How many tickets should we change each time? (small for auto-cron)"),
                'default' => 20
                    ]),
        ];

        // Configure group to associate a status change with a canned response notification:
        // Get all the canned responses to use as selections:
        $responses = Canned::getCannedResponses();
        $responses['-1'] = $__('Send no Reply');
        ksort($responses);

        // Build a group configuration:
        $config_group = [];

            $config_group[] = [
                'purge-age' => new TextboxField(
                        [
                    'default' => '999',
                    'label' => $__('Max Ticket age in days'),
                    'hint' => $__(
                            'Tickets with no updates in this many days will match and have their status changed.'),
                    'size' => 5,
                    'length' => 4
                        ]),
                'close-only-answered' => new BooleanField(
                        [
                    'default' => TRUE,
                    'label' => $__('Only change tickets with an Agent Response'),
                    'hint' => ''
                        ]),
                'close-only-overdue' => new BooleanField(
                        [
                    'default' => FALSE,
                    'label' => $__('Only change tickets past expiry date'),
                    'hint' => $__('Default ignores expiry')
                        ]),
                'from-status' => new ChoiceField(
                        [
                    'label' => $__('From Status'),
                    'choices' => $statuses,
                    'default' => 1,
                    'hint' => $__(
                            'When we change the ticket, what are we changing the status from? Default is "Open"')
                        ]),
                'to-status' => new ChoiceField(
                        [
                    'label' => $__('To Status'),
                    'choices' => $statuses,
                    'default' => 3, // 3 == Open on mine.
                    'hint' => $__(
                            'When we change the ticket, what are we changing the status to? Default is "Closed"')
                        ]),
                'admin-note' => new TextareaField(
                        [
                    'label' => $__('Auto-Note'),
                    'hint' => $__('Create\'s an admin note just before closing.'),
                    'default' => 'Auto-closed for being open too long with no updates.',
                    'configuration' => [
                        'html' => FALSE,
                        'size' => 40,
                        'length' => 256
                    ]
                        ]),
                'robot-account' => new ChoiceField(
                        [
                    'label' => $__('Robot Account'),
                    'choices' => $staff,
                    'default' => 0,
                    'hint' => $__(
                            'Select account for sending replies, account can be locked, still works.')
                        ]),
                'admin-reply' => new ChoiceField(
                        [
                    'label' => $__('Auto-Reply Canned Response'),
                    'hint' => $__(
                            'Select a canned response to use as a reply just before closing (can use Variables), configure in /scp/canned.php'),
                    'choices' => $responses
                        ])
            ];

        if (version_compare(PHP_VERSION, '5.6.0') >= 0) {
            // Merge all the group configurations into the global settings array and return as the config
            return array_merge($global_settings, ...$config_group);
        }


        // Support pre 5.6... oi vey
        $settings = $global_settings;
            foreach ($config_group as $setting) {
                $settings[] = $setting;
            }
        return $settings;
    }

}
