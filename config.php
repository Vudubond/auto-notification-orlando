<?php

return array(
    'name'        => 'Auto Replyer Orlando',
    'version'     => '1.0.0',
    'author'      => 'Your Name',
    'description' => 'Sends automatic reminders to clients based on ticket status and inactivity.',
    'settings'    => array(
        'status' => array(
            'type'     => 'select',
            'label'    => 'Monitor Status',
            'help'     => 'The status of tickets to monitor for inactivity.',
            'options'  => array(
                'open'      => 'Open',
                'pending'   => 'Pending',
                'awaiting'  => 'Awaiting Reply',
                'on-hold'   => 'On Hold'
            ),
            'default'  => 'open',
        ),
        'frequency' => array(
            'type'     => 'number',
            'label'    => 'Reminder Frequency (hours)',
            'help'     => 'How often should reminders be sent if no reply is received?',
            'default'  => 48, // Reminder every 48 hours
        ),
        'canned_response' => array(
            'type'     => 'textarea',
            'label'    => 'Canned Response',
            'help'     => 'The response that will be sent to the client when no reply is received.',
            'default'  => 'Hello, it looks like we haven’t received your reply. Please respond so we can assist you further.',
        ),
    ),
);
