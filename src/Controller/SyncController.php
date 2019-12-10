<?php

namespace Uskur\CakePHPPathable\Controller;

use Cake\Event\Event;
use Uskur\CakePHPPathable\Controller\AppController;
use Cake\Core\Configure;
use Cake\I18n\Time;
use Uskur\EmailManager\Mailer\TemplatedMailer;
use Uskur\PathableClient\PathableClient;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;
use Uskur\CakePHPPathable\Utility\PathableUtility;

/**
 * Sync Controller
 *
 * @property \App\Model\Table\ArticlesTable $Articles
 */
class SyncController extends AppController
{

    /**
     * Index method
     *
     * @return \Cake\Network\Response|null
     */
    public function index()
    {
        $matches = [];
        $PathableUtility = new PathableUtility();

        $usersToDelete = $PathableUtility->syncAll();
        $this->set('usersToDelete', $usersToDelete);
    }

    public function people($eventId)
    {
        $Registers = TableRegistry::getTableLocator()->get('Registers');
        $people = $Registers->find()->where([
            'event_id' => $eventId,
            'registration_date IS NOT NULL'
        ])->contain(['Users']);
        $this->set('people', $people);
        $this->set('_serialize', false);
    }

    public function agenda($eventId)
    {
        $Activities = TableRegistry::getTableLocator()->get('Activities');
        $agenda = $Activities->find()->where(['event_id' => $eventId])->contain([
            'Registers' => [
                'conditions' => [
                    'Registers.registration_date IS NOT NULL'
                ]
            ],
            'Registers.Users'
        ]);
        $this->set('agenda', $agenda);
        $this->set('_serialize', false);
    }

    public function beforeFilter(Event $event)
    {
        $this->Auth->allow('people');
        parent::beforeFilter($event);
    }


}
