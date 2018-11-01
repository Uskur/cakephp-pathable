<?php
namespace Uskur\CakePHPPathable\Event;

use Cake\Core\Configure;
use Cake\Event\EventListenerInterface;
use Cake\ORM\TableRegistry;
use Uskur\PathableClient\PathableClient;
use Cake\Log\Log;

/**
 *
 * @property \Uskur\PathableClient\PathableClient $client
 * @author burak
 *        
 */
class PathableListener implements EventListenerInterface
{

    public $client = null;

    public function implementedEvents()
    {
        $this->initClient();
        return [
            'Model.Register.newRegistration' => 'newRegistration',
            'Model.Activity.createMeeting' => 'createMeeting',
            'Model.Activity.deleteMeeting' => 'deleteMeeting',
            'Users.Component.UsersAuth.afterLogin' => 'userLogin'
        ];
    }

    public function newRegistration($event, $register)
    {
        $pathableUser = $this->client->CreateUser([
            'first_name' => $register->user->first_name,
            'last_name' => $register->user->last_name,
            'credentials' => $register->user->title,
            'event_external_id' => $register->id,
            'master_external_id' => $register->user->id,
            'email' => $register->user->email,
            'allowed_mails' => '',
            'allowed_sms' => '',
            'bio' => '',
            'enabled_for_email' => false,
            'enabled_for_sms' => false,
            'evaluator_id' => ''
        ]);
//         $match = [
//             'question_id' => 'pathable_question_id'
        
//         ];
//         $countries = [];
//         foreach ($register->user->user_groups as $userGroup) {
//             if (in_array($userGroup->id, Configure::read('Site.user_category_groups'))) {
//                 $countries[] = $userGroup->name;
//             }
//         }
//         //dd($pathableUser);
//         $this->client->AnswerQuestion([
//             'question_id' => '4938',
//             'user_id' => $pathableUser['id'],
//             'answer' => implode(', ', $countries)
//         ]);
    }
    public function createMeeting($event, $meetingCreate)
    {
        $this->client->CreateMeeting([
            'name' => $meetingCreate->title,
            'external_id' => $meetingCreate->id,
            'date' => $meetingCreate->start->i18nFormat('yyyy-MM-dd'),
            'start_time' => $meetingCreate->start->i18nFormat('HH:mm'),
            'end_time' => $meetingCreate->end->i18nFormat('HH:mm')
        ]);
    }
    public function DeleteMeeting($event, $meetingDelete)
    {
        $results = $this->client->SearchMeeting([
            'with' => ['external_id'=>$meetingDelete->id]
        ]);
        
        $resultCount = count($results['results']);
        
        if($resultCount == 0){
            Log::notice('Event does not exist in pathable');
        }
        else if($resultCount > 1){
            Log::notice('Multiple events exist in pathable');
            foreach($results['results'] as $value){
                $this->client->DeleteMeeting([
                    'id' => $value['id']
                ]); 
            }  
        }
        else{
            Log::info('Successfully deleted in pathable');
            $this->client->DeleteMeeting([
                'id' => $results['results'][0]['id']
            ]);
        }
    }
    
    public function userLogin($event, $user)
    {
        $Event = TableRegistry::get('Events');
        $currentEvent = $Event->getCurrentEvent();
        
        $Users = TableRegistry::get('Users');
        $user = $Users->get($user['id'],['contain'=>['Registers'=>['conditions'=>['Registers.event_id'=>$currentEvent->id,'Registers.registration_date IS NOT NULL']]]]);
        if(!empty($user->registers)) {
            //$pathableUser = $this->client->getCommand('GetUser',['id'=>'a0e23315-aee8-4b56-9c59-cb316e793576']);
            //$pathableUser = $this->client->getCommand('SearchUser',['with'=>['master_external_id'=>'a0e23315-aee8-4b56-9c59-cb316e793576']]);
            //$pathableUser = $this->client->getCommand('SearchUser',['with'=>['master_external_id'=>$user->id]]);
            $pathableUser = $this->client->getCommand('SearchUser');
            //$pathableUser = $this->client->getCommand('SearchUser',['query'=>$user->email]);
            pr($pathableUser);
            $pathableUser = $this->client->execute($pathableUser);
        }
        //dd($pathableUser);
    }

    private function initClient()
    {
        $this->client = PathableClient::create([
            'community_id' => Configure::read('Pathable.community_id'),
            'api_token' => Configure::read('Pathable.api_token'),
            'auth_token' => Configure::read('Pathable.auth_token'),
        ]);
    }
    
}