<?php
namespace Uskur\CakePHPPathable\Controller;

use Uskur\CakePHPPathable\Controller\AppController;
use Cake\Core\Configure;
use Cake\I18n\Time;
use Uskur\EmailManager\Mailer\TemplatedMailer;
use Uskur\PathableClient\PathableClient;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;

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
        $this->initClient();
        
        // Create instance of Activities to get activity information
        $Activities = TableRegistry::get('Activities');
        $currentEvent = $Activities->Events->getCurrentEvent();
        $activities = $Activities->find('all')->where(['event_id'=>$currentEvent->id]);
        foreach($activities as $activity) {
            $results = $this->client->SearchMeeting([
                'with' => [
                    'external_id' => $activity->id
                ]
            ]);
            
            // Check for activity
            $resultCount = count($results['results']);
            
            if ($resultCount == 1) {
                $matches[$activity->id] = $results['results'][0]['id'];
                continue;
            }
            if ($resultCount > 1) {
                Log::notice('Multiple activities conflicts in pathable, deleting all for system integrity', $results);
                foreach ($results['results'] as $value) {
                    $this->client->DeleteMeeting([
                        'id' => $value['id']
                    ]);
                }
            }
            Log::notice('Activity does not exist in pathable', $id);
            
            try {
                $pathableMeeting = $this->client->CreateMeeting([
                    'name' => $activity->title,
                    'external_id' => $activity->id,
                    'date' => $activity->start->i18nFormat('yyyy-MM-dd'),
                    'start_time' => $activity->start->i18nFormat('HH:mm'),
                    'end_time' => $activity->end->i18nFormat('HH:mm')
                ]);
                
                Log::info('New activity created in pathable', $activity->title);
                
            } catch (Exception $e) {
                Log::info('New meeting could not be created in pathable', $activity);
            }
        }
        $this->set('matches',$matches);

        
        $Events = TableRegistry::get('Events');
        $currentEvent = $Events->getCurrentEvent();
        $registers = $Events->Registers->find('all')->where(['event_id'=>$currentEvent->id])->contain(['Users.UserGroups','Activities']);
        
        foreach($registers as $register) {
            try {
                $pathableUser = $this->client->SearchUser([
                    'query' => $registers->user->email
                ]);
            } catch (Exception $e) {
                Log::error('User could not be found in pathable', $userEmail);
            }
        
            // pathable user existance check requires 2 step check, deleted user's information can still return,
            // in that case, visible must be equal to zero
            if ($pathableUser['results'] == null || $pathableUser['results'][0]['visible'] === 0) {
                return false;
            }
            
            $pathableUser = $this->client->CreateUser([
                'first_name' => $register->user->first_name,
                'last_name' => $register->user->last_name,
                'primary_email' => $register->user->email,
                'credentials' => $register->user->title,
                'event_external_id' => $register->id,
                'master_external_id' => $register->user->id,
                'allowed_mails' => '',
                'allowed_sms' => '',
                'bio' => '',
                'enabled_for_email' => false,
                'enabled_for_sms' => false,
                'evaluator_id' => ''
            ]);
            
            // if user change answer in pathable, answers will be desynch with egprn (there is no check in our code)
            $userInformation1 = Hash::extract($register->user->user_groups, '{n}.name');
            $userAnswer1 = implode($userInformation1, ' ,');
            $this->client->AnswerQuestion([
                'question_id' => '4938',
                'user_id' => $pathableUser['id'],
                'answer' => $userAnswer1
            ]);
            
            $userAnswer2 = $user->responses[2]->response;
            $this->client->AnswerQuestion([
                'question_id' => '4940',
                'user_id' => $pathableUser['id'],
                'answer' => $userAnswer2
            ]);
            
            foreach ($register->activities as $activity) {
                $this->client->AddaUserToMeeting([
                    'group_id' => $matches[$activity->id],
                    'user_id' => $pathableUser['id']
                ]);
            }

        }
        return $pathableUser;
    }
    
    private function initClient()
    {
        $this->client = PathableClient::create([
            'community_id' => Configure::read('Pathable.community_id'),
            'api_token' => Configure::read('Pathable.api_token'),
            'auth_token' => Configure::read('Pathable.auth_token')
        ]);
    }


}
