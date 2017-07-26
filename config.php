<?php
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.message.php';

class CloserConfig extends PluginConfig
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

    function pre_save($config, &$errors)
    {
        // validate expressions
        if (! $config['purge-num']) {
            $config['purge-num'] = 20;
        }
        if (! $config['purge-age']) {
            $config['purge-age'] = 999;
        }
        Messages::success("Plugin closer configured.");
        return TRUE;
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
        return array(
            'purge-age' => new TextboxField(array(
                'default' => '999',
                'label' => $__('Max amount of non-activity for a ticket.'),
                'hint' => $__('Leave tickets open without encouragement for 999 days or 2.7 years'),
                'size' => 5,
                'length' => 3
            )),
            'purge-frequency' => new ChoiceField(array(
                'label' => $__('Purge Frequency'),
                'choices' => array(
                    '0' => $__('Every Cron'),
                    '1' => $__('Every Hour'),
                    '2' => $__('2 Hours'),
                    '6' => $__('6 Hours'),
                    '12' => $__('12 Hours'),
                    '24' => $__('1 Day'),
                    '36' => $__('36 Hours'),
                    '48' => $__('2 Days'),
                    '72' => $__('72 Hours'),
                    '168' => $__('1 Week')
                ),
                'default' => '2',
                'hint' => $__("How often should we check and close old tickets? (use zero to indicate EVERY cron run)")
            )),
            'purge-num' => new TextboxField(array(
                'label' => $__('Purge Number'),
                'hint' => $__("How many tickets should we close each time?"),
                'default' => 20
            ))
        );
    }
}