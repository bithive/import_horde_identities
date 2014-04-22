<?php
/**
 * Import horde identities
 *
 * Populates a new user's identities from their Horde account.
 *
 * Skips users who already have > 1 Roundcube identities.
 *
 * The existing default identity is updated first, then any additional
 * identities are imported.
 *
 * You must configure your Horde database credentials in main.inc.php:
 *
 *  $rcmail_config['horde_dsn']  = 'pgsql:host=db.example.com;dbname=horde';
 *  $rcmail_config['horde_user'] = 'horde';
 *  $rcmail_config['horde_pass'] = 'password';
 *
 * See also: https://github.com/bithive/import_horde_contacts
 *
 * @version 1.0
 * @author Jason Meinzer
 *
 */
class import_horde_identities extends rcube_plugin {
    public $task = 'login';

    private $horde_identities = array();

    function init() {
        $this->add_hook('login_after', array($this, 'fetch_identity_objects'));
    }

    function fetch_identity_objects() {
        $this->rc = rcmail::get_instance();
        $rc_identities = $this->rc->user->list_identities();
        $this->load_config();

        // exit early if user already has extra identities
        // if they only have one identity it will get synced on each login
        // until this plugin is disabled
        if(sizeof($rc_identities) > 1) return true;

        $uid = explode('@', $this->rc->user->get_username());
        $uid = $uid[0];

        $db_dsn  = $this->rc->config->get('horde_dsn');
        $db_user = $this->rc->config->get('horde_user');
        $db_pass = $this->rc->config->get('horde_pass');

        try {
            $db = new PDO($db_dsn, $db_user, $db_pass);
        } catch(PDOException $e) {
            return false;
        }

        $sth = $db->prepare("select pref_value from horde_prefs where pref_uid = :uid and pref_name = 'identities' limit 1");
        $sth->bindParam(':uid', $uid);
        $sth->execute();

        $result = $sth->fetch(PDO::FETCH_ASSOC);
        $this->horde_identities = unserialize($result['pref_value']);

        $default_record = $this->record_from_default_horde_identity();
        $this->rc->user->update_identity($rc_identities[0]['identity_id'], $default_record);

        $count = 0;
        foreach($this->horde_identities as $horde_identity) {
            $record = $this->record_from_horde_identity($horde_identity);
            $this->rc->user->insert_identity($record);
            $count++;
        }

        write_log('import_horde_identities', "Imported $count Horde identities for $uid");
        return true;
    }

    function record_from_default_horde_identity() {
        return $this->record_from_horde_identity($this->default_horde_identity());
    }

    function default_horde_identity() {
        foreach($this->horde_identities as $index => $horde_identity) {
            if($horde_identity['default_identity']) {
                unset($this->horde_identities[$index]);
                return $horde_identity;
            }
        }

        foreach($this->horde_identities as $index => $horde_identity) {
            if($horde_identity['id'] == 'Default') {
                unset($this->horde_identities[$index]);
                return $horde_identity;
            }
        }

        $horde_identity = $this->horde_identities[0];
        unset($this->horde_identities[0]);
        return $horde_identity;
    }

    function record_from_horde_identity($horde_identity) {
        return array(
            'name'      => $horde_identity['fullname'],
            'email'     => $horde_identity['from_addr'],
            'reply-to'  => empty($horde_identity['replyto_addr']) ? $horde_identity['from_addr'] : $horde_identity['replyto_addr'],
            'signature' => $horde_identity['signature']
        );
    }
}
?>
