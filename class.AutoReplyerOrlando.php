<?php
/**
 * @file class.AutoReplyerOrlando.php
 * @  requires osTicket 1.17+ & PHP8.0+
 * @  multi-instance: yes
 *
 * @author Orlando <orlando@example.com>
 * @see https://github.com/orlando/plugin-autoreplyer
 */

foreach ([
    'canned',
    'format',
    'list',
    'orm',
    'misc',
    'plugin',
    'ticket',
    'signal',
    'staff'
] as $c) {
    require_once INCLUDE_DIR . "class.$c.php";
}

require_once 'config.php';

/**
 * The goal of this Plugin is to automatically send replies to tickets under certain conditions.
 * Could be based on ticket status, age, or other criteria.
 */
class AutoReplyerOrlandoPlugin extends Plugin {

    var $config_class = 'AutoReplyerOrlandoPluginConfig';

    /**
     * Set to TRUE to enable extra logging.
     *
     * @var boolean
     */
    const DEBUG = FALSE;

    /**
     * Keeps all log entries for each run
     * for output to syslog
     *
     * @var array
     */
    private $LOG = array();

    /**
     * The name that appears in threads as: AutoReplyer Plugin.
     *
     * @var string
     */
    const PLUGIN_NAME = 'AutoReplyer Plugin';

    /**
     * Hook the bootstrap process Run on every instantiation, so needs to be
     * concise.
     *
     * {@inheritdoc}
     *
     * @see Plugin::bootstrap()
     */
    public function bootstrap() {
        // ---------------------------------------------------------------------
        // Fetch the config
        // ---------------------------------------------------------------------
        $config = $this->config;
        $instance = $this->config->instance;

        // Listen for cron Signal, which only happens at the end of class.cron.php:
        Signal::connect('cron', function ($ignored, $data) use (&$config, $instance) {
            // Cron signal handling
            $this->auto_reply_tickets($config);
        });
    }

    /**
     * This method handles auto-replying to tickets based on certain criteria.
     */
    private function auto_reply_tickets(&$config) {
        global $ost;
        if ($this->is_time_to_run($config)) {
            try {
                $open_ticket_ids = $this->find_ticket_ids($config);
                if (self::DEBUG) {
                    $this->LOG[] = count($open_ticket_ids) . " open tickets.";
                }

                // Bail if there is no work to do
                if (!count($open_ticket_ids)) {
                    return true;
                }

                // Fetch the response from the Setting config:
                $auto_reply_message = $config->get('auto-reply-message');
                $robot = $config->get('robot-account');
                $robot = ($robot > 0) ? Staff::lookup($robot) : null;

                // Go through each ticket ID and send an auto-reply
                foreach ($open_ticket_ids as $ticket_id) {

                    // Fetch ticket as an Object
                    $ticket = Ticket::lookup($ticket_id);
                    if (!$ticket instanceof Ticket) {
                        $this->LOG[] = "Ticket $ticket_id was not instantiable. :-(";
                        continue;
                    }

                    // Send the auto-reply message
                    $this->post_reply($ticket, $auto_reply_message, $robot);
                }

                $this->print2log();
                
            } catch (Exception $e) {
                $this->LOG[] = "Exception encountered, we'll soldier on, but something is broken!";
                $this->LOG[] = $e->getMessage();
                if (self::DEBUG) {
                    $this->LOG[] = '<pre>' . print_r($e->getTrace(), 2) . '</pre>';
                }
                $this->print2log();
            }
        }
    }

    /**
     * Calculates when it's time to run the plugin based on the config.
     *
     * @param PluginConfig $config
     * @return boolean
     */
    private function is_time_to_run(PluginConfig &$config) {
        // We can store arbitrary things in the config, like when we ran this last:
        $last_run = $config->get('last-run');
        $now = Misc::dbtime(); // Never assume about time.. 
        $config->set('last-run', $now);

        // Assume a frequency of "Every Cron" means it is always overdue
        $next_run = 0;
        $fr = ($config->get('frequency') > 0) ? $config->get('frequency') : 0;
        if ($freq_in_config = (int) $fr) {
            $next_run = $last_run + ($freq_in_config * 3600); // Frequency in hours
        }

        // Check if it's time to run
        if (self::DEBUG || !$next_run || $now > $next_run) {
            return true;
        }
        return false;
    }

    /**
     * This is the part that fetches ticket IDs based on the configuration criteria.
     *
     * @param PluginConfig $config
     * @return array of ticket IDs
     * @throws Exception
     */
    private function find_ticket_ids(PluginConfig &$config) {
        global $ost;
        $from_status = (int) $config->get('from-status');
        if (!$from_status) {
            throw new \Exception("Invalid parameter (int) from_status needs to be > 0");
        }

        $max = (int) $config->get('max-replies');
        if ($max < 1) {
            throw new \Exception("Invalid parameter (int) max-replies needs to be > 0");
        }

        // Query to find open tickets that need an auto-reply
        $sql = sprintf(
            "SELECT ticket_id 
            FROM %s WHERE status_id=%d
            ORDER BY ticket_id ASC
            LIMIT %d", TICKET_TABLE, $from_status, $max);

        if (self::DEBUG) {
            $this->LOG[] = "Looking for tickets with query: $sql";
        }

        $r = db_query($sql);
        $ids = array();
        while ($i = db_fetch_array($r, MYSQLI_ASSOC)) {
            $ids[] = $i['ticket_id'];
        }

        return $ids;
    }

    /**
     * Sends a reply to the ticket owner
     *
     * @param Ticket $ticket
     * @param string $auto_reply_message
     * @param Staff|null $robot
     */
    function post_reply(Ticket $ticket, $auto_reply_message, Staff $robot = null) {
        global $ost, $thisstaff;

        if ($robot) {
            $assignee = $robot;
        } else {
            $assignee = $ticket->getAssignee();
            if (!$assignee instanceof Staff) {
                // No assignee, log error
                $ticket->logNote(__('AutoReplyer Error'), __('No assigned agent and no robot account specified.'), self::PLUGIN_NAME, FALSE);
                return;
            }
        }

        // Replace any ticket variables in the auto-reply message
        $variables = ['recipient' => $ticket->getOwner()];

        $custom_reply = $ticket->replaceVars($auto_reply_message, $variables);

        // Send the reply
        $vars = ['response' => $custom_reply];
        $errors = [];

        if (!$sent = $ticket->postReply($vars, $errors, TRUE, FALSE)) {
            $ticket->LogNote(__('Error Notification'), __('Failed to post reply to the ticket creator.'), self::PLUGIN_NAME, FALSE);
        }
    }

    /**
     * Outputs all log entries to the syslog
     *
     */
    private function print2log() {
        global $ost;
        if (empty($this->LOG)) {
            return false;
        }
        $msg = '';
        foreach ($this->LOG as $key => $value) {
            $msg .= $value . '<br>';
        }
        $ost->logWarning(self::PLUGIN_NAME, $msg, false);
    }

    /**
     * Required stub.
     *
     * {@inheritdoc}
     *
     * @see Plugin::uninstall()
     */
    function uninstall(&$errors) {
        $errors = array();
        global $ost;
        $ost->alertAdmin(self::PLUGIN_NAME . ' has been uninstalled', "You wanted that right?", true);

        parent::uninstall($errors);
    }

    /**
     * Plugins seem to want this.
     */
    public function getForm() {
        return array();
    }
}
?>
