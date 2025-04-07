<?php
/**
 * @file config.php
 * @  requires osTicket 1.15.6+ & PHP8.0+
 * @  multi-instance: yes
 *
 * @author Orlando <youremail@example.com>
 * @see https://github.com/Vudubond/auto-notification-orlando/
 */
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.message.php';

class AutoReplyerOrlandoConfig extends PluginConfig {

    // Translate function for compatibility with older osTicket versions
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
        return Plugin::translate('auto_replyer_orlando');
    }

    function pre_save(&$config, &$errors) {
        list ($__, $_N) = self::translate();

        // Validate numeric configurations for the reminder frequency
        if (isset($config['frequency']) && !is_numeric($config['frequency'])) {
            $errors['err'] = $__('Reminder Frequency should be a numeric value.');
            return FALSE;
        }

        // Ensure 'robot-account' and 'canned-response' are selected
        if (!(isset($config['robot-account'])) || ($config['robot-account'] == 0)) {
            $errors['err'] = $__('Please select a robot account to send the reminder.');
            return FALSE;             
        }
        if (!(isset($config['canned-response'])) || ($config['canned-response'] == 0)) {
            $errors['err'] = $__('Please select a canned response for the auto-reply.');
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

        // Get all available Ticket Statuses to populate the "From Status" dropdown
        $statuses = [];
        foreach (TicketStatus::objects()->values_flat('id', 'name') as $s) {
            list ($id, $name) = $s;
            $statuses[$id] = $name;
        }

        // Get all available Canned Responses for use in auto-reply
        $responses = Canned::getCannedResponses();
        $responses['-1'] = $__('Send no Reply');
        ksort($responses);

        // Build the plugin configuration settings
        return [
            'global' => new SectionBreakField(
                [
                    'label' => $__('Global Config')
                ]
            ),
            'frequency' => new ChoiceField(
                [
                    'label' => $__('Reminder Frequency (hours)'),
                    'choices' => [
                        '1' => $__('Every 1 Hour'),
                        '2' => $__('Every 2 Hours'),
                        '6' => $__('Every 6 Hours'),
                        '12' => $__('Every 12 Hours'),
                        '24' => $__('Every 1 Day'),
                        '48' => $__('Every 2 Days'),
                        '72' => $__('Every 3 Days'),
                        '168' => $__('Every Week'),
                    ],
                    'default' => '24', // Default reminder frequency to 24 hours
                    'hint' => $__('How often should the system send reminders if no reply is received?')
                ]
            ),
            'status' => new ChoiceField(
                [
                    'label' => $__('Monitor Status'),
                    'choices' => $statuses,
                    'default' => '1', // Default status set to "Open"
                    'hint' => $__('The status of tickets to monitor for inactivity.')
                ]
            ),
            'robot-account' => new ChoiceField(
                [
                    'label' => $__('Robot Account'),
                    'choices' => Staff::objects()->values_flat('id', 'name'),
                    'default' => 0,
                    'hint' => $__('Select the account that will send the reminder emails.')
                ]
            ),
            'canned-response' => new ChoiceField(
                [
                    'label' => $__('Canned Response'),
                    'choices' => $responses,
                    'default' => '-1',
                    'hint' => $__('Select the canned response that will be sent to the client.')
                ]
            )
        ];
    }
}
