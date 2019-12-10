<?php

use Cake\Utility\Xml;

$xml = new SimpleXMLElement("<meetings/>");
foreach ($agenda as $activity) {
    $row = $xml->addChild('meeting');
    $row->addChild('external_id', $activity->id);
    $row->addChild('name', htmlspecialchars($activity->title));
    $row->addChild('starts_at', $activity->start->i18nFormat('M/d/yyyy hh:mm a'));
    $row->addChild('ends_at', $activity->end->i18nFormat('M/d/yyyy hh:mm a'));
    $row->addChild('blurb', htmlspecialchars($activity->description));
    $attendees = [];
    foreach ($activity->registers as $register) {
        $attendees[] = $register->user->email;
    }
    if (!empty($attendees)) {
        $row->addChild('accepted_attendees_emails', implode(',', $attendees));
    }
    $row->addChild('private', false);
}
echo $xml->asXML();
?>
