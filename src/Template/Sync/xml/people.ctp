<?php

use Cake\Routing\Router;
use Cake\Utility\Xml;

$xml = new SimpleXMLElement("<users/>");
foreach ($people as $person) {
    $row = $xml->addChild('user');
    $row->addChild('external_id', $person->user->id);
    $row->addChild('primary_email', $person->user->email);
    $row->addChild('first_name', htmlspecialchars($person->user->first_name));
    $row->addChild('last_name', htmlspecialchars($person->user->last_name));
    $row->addChild('credentials', $person->user->title);
    $row->addChild('visible', true);
    if (!empty($person->user->institute)) {
        $row->addChild('organization_name', htmlspecialchars($person->user->institute));
    }
    if (!empty($person->user->avatar)) {
        $row->addChild('photo_url', Router::url([
            'prefix' => false,
            'plugin' => 'Uskur/Attachments',
            'controller' => 'Attachments',
            'action' => 'image',
            $person->user->avatar
        ]));
    }
}
echo $xml->asXML();
?>
