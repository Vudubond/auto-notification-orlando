<?php

class AutoReplyerOrlando extends Plugin {
    var $config = array();

    public function __construct() {
        $this->config = $this->getConfig();
    }

    public function onCronHourly() {
        // Get plugin settings
        $status = $this->config['status'];
        $frequency = $this->config['frequency'];
        $canned_response = $this->config['canned_response'];

        // Calculate time threshold
        $time_threshold = time() - ($frequency * 3600);  // Convert hours to seconds

        // Query tickets in the specified status and check last reply time
        $sql = 'SELECT ticket_id, last_message FROM ' . TICKET_TABLE . ' WHERE status = ? AND last_message < ?';
        $tickets = db_query($sql, array($status, $time_threshold));

        while ($ticket = db_fetch_array($tickets)) {
            $ticket_id = $ticket['ticket_id'];
            $last_message = strtotime($ticket['last_message']);

            // Check if no reply within the frequency time
            if ($last_message < $time_threshold) {
                $this->sendReminder($ticket_id, $canned_response);
            }
        }
    }

    private function sendReminder($ticket_id, $message) {
        // Fetch ticket and user details
        $ticket = Ticket::lookup($ticket_id);
        if (!$ticket) return;

        $user = $ticket->getUser();
        if (!$user) return;

        // Send reminder as an email to the client
        $email = $user->getEmail();
        $subject = "Reminder: Your ticket #{$ticket_id} is awaiting your reply";
        $body = $message;

        // Send email
        EmailTemplate::sendTemplate('auto_replyer_orlando', $email, $subject, $body);

        // Add an internal note to the ticket
        $ticket->addNote('Reminder: ' . $message, true);
    }
}

return new AutoReplyerOrlando();
