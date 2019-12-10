<?php
use Cake\Utility\Xml;
$xml = new SimpleXMLElement("<users/>");
foreach ($people as $person) {
    $row = $xml->addChild('user');
    $row->addChild('external_id', $person->user->id);
    $row->addChild('primary_email', $person->user->email);
    $row->addChild('first_name', $person->user->first_name);
    $row->addChild('last_name', $person->user->last_name);
    $row->addChild('credentials', $person->user->title);
    $row->addChild('visible', true);
}
echo $xml->asXML();
?>
