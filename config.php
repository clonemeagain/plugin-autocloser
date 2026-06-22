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
        if (   isset($config['purge-num'])
            && (!is_numeric($config['purge-num']) || (int) $config['purge-num'] < 1)
           ) {
            $errors['err'] = $__('Only a positive numeric value is valid for Purge Number.');
            return false;
        }
	
        if (   ($config['calculate-date'] ?? null) !== 'd'
            && (   !isset($config['purge-age'])
                || !is_numeric($config['purge-age'])
                || (int) $config['purge-age'] < 1
               )
           ) {
            $errors['err'] = $__('Max Ticket age only supports positive numeric values.');
            return false;
        }

        // force from-status != to-status
        $toStatus = (int) (($config['to-status'] ?? 3) ?: 3);  // default = 3
        $fromStatus = ($config['from-status'] ?? 1) ?: 1;      // default = 1
        if(!is_array($fromStatus))
            $fromStatus = [$fromStatus];
        $fromStatusIds = array_filter(array_map('intval', array_keys($fromStatus)));
        if (in_array($toStatus, $fromStatusIds, true)) {
            $errors['err'] = $__('The target status must not be included in the status filter list');
            return false;
        }

        $robotAccount = intval($config['robot-account'] ?? 0);
        $adminReply = intval($config['admin-reply'] ?? 0);
        if (!$robotAccount && $adminReply > 0) {
            $errors['err'] = $__('Please choose a robot-account.');
            return false;            	
        }

        return true;
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
        $staff = $statuses = [];

        // Doesn't appear to be a TicketStatus list that I want to use..
        foreach (TicketStatus::objects()->values_flat('id', 'name') as $s) {
            list ($id, $name) = $s;
            $statuses[$id] = $name;
        }
        // Build array of Agents
        $staff[-2] = $__('SYSTEM');
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

        $dayOfMonth = [1 => $__('First Day of Month')];
        for ($i=2; $i <= 27; $i++)
            $dayOfMonth[$i] = sprintf($__('Day %s of Month'), $i);
        $dayOfMonth[28] = $__('Last Day of Month');

        // Build array for hours 0-23 in steps of 5 minutes
        $executionTimes = [];
        for ($i = 0; $i <= 23; $i++) {
            for ($min = 0; $min < 60; $min+=5) {
                $time_key = $i * 60 + $min;
                $executionTimes[$time_key] = sprintf('%02d:%02d', $i, $min);
            }
        }

        // Build a group configuration:
        $config_group = [];

        $config_group[] = [
            'time' => new SectionBreakField(
                    [
                'label' => $__('Time of Execution')
                    ]),
            'calculate-date' => new ChoiceField(
                    [
                'label' => $__('Calculate from date'),
                'choices' => [
                    'c'=>__('Create Date'),
                    'u'=>__('Last Update'),
                    'm'=>__('Last Message'),
                    'r'=>__('Last Response'),
                    'd'=>$__('Day X of Month')
                ],
                'default' => 'u',
                'hint' => $__('From which date should the calculation begin?')
                    ]),
            'purge-age' => new TextboxField(
                    [
                'default' => '999',
                'label' => $__('Max Ticket age in days'),
                'hint' => sprintf('%s (%s)',
                                  $__('Tickets whose date is before the specified days will match and have their status changed.'),
                                  $__('Not used, if „Calculate from date“ is set to „Day X of Month“')
                          ),
                'size' => 5,
                'length' => 4
                    ]),
            'day-of-month' => new ChoiceField(
                    [
                'label' => $__('Day of Month'),
                'choices' => $dayOfMonth,
                'default' => 1,
                'hint' => $__('Only used, if „Calculate from date“ is set to „Day X of Month“')
                    ]),
            'time-of-day' => new ChoiceField(
                    [
                'label' => $__('Time of Selected Day'),
                'choices' => $executionTimes,
                'default' => 60,
                'hint' => $__('Only used, if „Calculate from date“ is set to „Day X of Month“')
                    ]),
            'filter' => new SectionBreakField(
                    [
                'label' => $__('Filter Config')
                    ]),
            'close-only-answered' => new BooleanField(
                    [
                'default' => TRUE,
                'label' => $__('Only change tickets with an Agent Response'),
                'hint' => $__('Checks the answered flag')
                    ]),
            'close-only-overdue' => new BooleanField(
                    [
                'default' => FALSE,
                'label' => $__('Only change tickets past expiry date'),
                'hint' => $__('Default ignores expiry')
                          .' ('.$__('Checks, if the overdue flag is set or the due date is in the past').')'
                    ]),
            'help-topic-selector' => new ChoiceField(
                    [
                'label' => sprintf($__('Consider %s'), __('Help Topics')),
                'choices' => ['p'=>$__('process'),'i'=>$__('ignore')],
                'default' => 'p',
                'hint' => $__(
                        'Should tickets with these help topics be processed or ignored?')
                    ]),
            'help-topics' => new ChoiceField(
                    [
                'id' => 'help-topics',
                'label' => __('Help Topics'),
                'choices' => (function() {
                    if (class_exists('Topic') && method_exists('Topic', 'getHelpTopics')) {
                        try {
                            $topics = Topic::getHelpTopics();
                            return $topics ?: [];
                        } catch (Throwable $e) {
                            return [];
                        }
                    }
                    return [];
                })(),
                'configuration' => ['multiselect' => true],
                'default' => [],
                'hint' => $__('Only tickets with one of these help topics will be processed or ignored. Leave empty to disable this filter.')
                    ]),
            'department-selector' => new ChoiceField(
                    [
                'label' => sprintf($__('Consider %s'), __('Departments')),
                'choices' => ['p'=>$__('process'),'i'=>$__('ignore')],
                'default' => 'p',
                'hint' => $__(
                        'Should tickets from these departments be processed or ignored?')
                    ]),
            'departments' => new ChoiceField(
                    [
                'id' => 'departments',
                'label' => __('Departments'),
                'choices' => (function() {
                    if (class_exists('Dept') && method_exists('Dept', 'getDepartments')) {
                        try {
                            $depts = Dept::getDepartments(null, true, true);
                            return $depts ?: [];
                        } catch (Throwable $e) {
                            return [];
                        }
                    }
                    return [];
                })(),
                'configuration' => ['multiselect' => true],
                'default' => [],
                'hint' => $__('Only tickets from one of these departments will be processed or ignored. Leave empty to disable this filter.')
                    ]),
            'from-status' => new ChoiceField(
                    [
                'label' => $__('From Status'),
                'choices' => $statuses,
                'configuration' => ['multiselect' => true],
                'default' => 1, // 1 == open
                'hint' => $__(
                        'When we change the ticket, what are we changing the status from? Default is "Open"')
                    ]),
            'actions' => new SectionBreakField(
                    [
                'label' => $__('Actions Config')
                    ]),
            'to-status' => new ChoiceField(
                    [
                'label' => $__('To Status'),
                'choices' => $statuses,
                'default' => 3, // 3 == closed.
                'hint' => $__(
                        'When we change the ticket, what are we changing the status to? Default is "Closed"')
                    ]),
            'admin-note' => new TextareaField(
                    [
                'label' => $__('Auto-Note'),
                'hint' => $__('Create\'s an admin note just before closing.'),
                'default' => $__('Auto-closed for being open too long with no updates.'),
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
                'choices' => $responses,
                'default' => -1,
                    ]),
        'debug' => new SectionBreakField(
                [
            'label' => $__('Debug mode')
                ]),
            'debug-mode-enabled' => new BooleanField(
                    [
                'default' => FALSE,
                'label' => $__('Enable debug mode'),
                'hint' => $__('Enable debug mode to get information about this instance into the syslog on every run.')
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
