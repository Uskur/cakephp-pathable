<?php
namespace Uskur\CakePHPPathable\Event;

use Cake\Core\Configure;
use Cake\Event\EventListenerInterface;
use Cake\ORM\TableRegistry;
use Uskur\PathableClient\PathableClient;
use Cake\Log\Log;
use App\Model\Table\UsersTable;
use Cake\Controller\Component\AuthComponent;
use Cake\Routing\Router;
use Cake\Core\Exception\Exception;

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
            'Model.Register.deleteRegistration' => 'deleteRegistration',
            'Model.Activity.createMeeting' => 'createMeeting',
            'Model.Activity.editMeeting' => 'editMeeting',
            'Model.Activity.deleteMeeting' => 'deleteMeeting',
            'Users.Component.UsersAuth.afterLogin' => 'userLogin'
        ];
    }

    public function newRegistration($event, $register)
    {

        /**
         * Pathable method for adding user to any activity requires user id and activity id,
         * related custom functions are called here in order to get required ids of activities and user
         */
        $userId = $this->getOrCreateUser($register->user->email);

        foreach ($register['activities'] as $activities) {
            $activityId = $this->getOrCreateActivity($activities['id']);
            $this->client->AddaUserToMeeting([
                'group_id' => $activityId,
                'user_id' => $userId
            ]);
        }
    }

    public function deleteRegistration($event, $deleteRegister)
    {
        /**
         * DeleteUser method of pathable client accepts user id as parameter, user id acquired by SearchUser method of pathable.
         * SearchUser method of pathable client accepts email as parameter, user email is acquired from "Users" table.
         */
        $users = TableRegistry::get('Users');
        $user = $users->get($deleteRegister->user_id);
        $pathableUser = $this->client->SearchUser([
            'query' => $user->email
        ]);

        if ($pathableUser['total_entries'] == 0) {
            Log::notice('The user not found in pathable');
        } else {
            $this->client->DeleteUser([
                'id' => $pathableUser['results'][0]['id']
            ]);
            Log::notice('The user has been deleted');
        }
    }

    public function createMeeting($event, $meetingCreate)
    {
        $this->getOrCreateActivity($meetingCreate['id']);
    }

    public function editMeeting($event, $meetingEdit)
    {
        $results = $this->client->SearchMeeting([
            'with' => [
                'external_id' => $meetingEdit->id
            ]
        ]);

        $resultCount = count($results['results']);

        if ($resultCount == 0) {
            Log::notice('Activity does not exist in pathable, creating new activity instead');
            $this->getOrCreateActivity($meetingEdit->id);
            Log::notice('New updated activity created');
        } else if ($resultCount > 1) {
            Log::notice('Multiple activities with same external id detected');
            throw new Exception();
        } else {
            // optimal case
            $Activities = TableRegistry::get('Activities');
            $activitiesQuery = $Activities->find('all', [
                'conditions' => [
                    'id' => "$meetingEdit->id"
                ]
            ]);

            $this->client->EditMeeting([
                'id' => $results['results'][0]['id'],
                'name' => $activitiesQuery->first()->title,
                'date' => $activitiesQuery->first()->start->i18nFormat('yyyy-MM-dd'),
                'start_time' => $activitiesQuery->first()->start->i18nFormat('HH:mm'),
                'end_time' => $activitiesQuery->first()->end->i18nFormat('HH:mm')
            ]);
        }
    }

    /**
     * This function deleting an activity from Pathable when the activity is deleted in egprn. If Pathable has multiple events
     * with the same external id, they will all be deleted and the new activity will be created for system integrity.
     */
    public function deleteMeeting($event, $meetingDelete)
    {
        $results = $this->client->SearchMeeting([
            'with' => [
                'external_id' => $meetingDelete->id
            ]
        ]);

        $resultCount = count($results['results']);

        if ($resultCount == 0) {
            Log::notice('Event does not exist in pathable, nothing found to delete');
        } else if ($resultCount > 1) {
            Log::notice('Multiple events exist in pathable');
            foreach ($results['results'] as $value) {
                $this->client->DeleteMeeting([
                    'id' => $value['id']
                ]);
            }
        } else {
            $this->client->DeleteMeeting([
                'id' => $results['results'][0]['id']
            ]);
            Log::info('Successfully deleted in pathable');
        }
    }

    /**
     * This function gets session information from Pathable for simultaneous login. Since Pathable accepts email only for authentication token,
     * we require table instances of Events and Users and current event information of egprn in order to get current login user email.
     */
    public function userLogin($event, $user)
    {
        // Table instances in egprn to get user email
        $Event = TableRegistry::get('Events');
        $Users = TableRegistry::get('Users');
        $currentEvent = $Event->getCurrentEvent();

        // Get user email from current event in egprn
        $user = $Users->get($user['id'], [
            'contain' => [
                'Registers' => [
                    'Activities.Events',
                    'conditions' => [
                        'Registers.event_id' => $currentEvent->id,
                        'Registers.registration_date IS NOT NULL'
                    ]
                ]
            ]
        ]);

        $userId = $this->getOrCreateUser($user->email);

        /* For system integrity, get user's registered activities from egprn, then;
         * if activity does not exist in pathable, creates activity, then registers user to the activity,
         * else, registers user to the activity 
         */
        foreach ($user['registers'][0]['activities'] as $activities) {
            $activityId = $this->getOrCreateActivity($activities['id']);
            $this->client->AddaUserToMeeting([
                'group_id' => $activityId,
                'user_id' => $userId
            ]);
        }

        // Pathable session information of user
        $Session = $this->client->GetSessionbyEmail([
            'primary_email' => $user->email
        ]);
        $authenticationUrl = $Session['authentication_url'];

        $dest = Router::url($event->subject()->Auth->redirectUrl(), true);
        return $event->subject()->redirect("$authenticationUrl&dest=$dest");
    }

    /**
     * This function checks if the user exist in pathable to get its id, also handles the case of user not found in pathable;
     */
    public function getOrCreateUser($userEmail)
    {
        $pathableUser = $this->client->SearchUser([
            'query' => $userEmail
        ]);
        Log::notice('User not found in pathable');
        if ($pathableUser['total_entries'] == 0) {
            // get egprn user information to create new user in pathable
            $Users = TableRegistry::get('Users');
            $usersQuery = $Users->find('all', [
                'conditions' => [
                    'email' => $userEmail
                ]
            ]);
            $this->client->CreateUser([
                'first_name' => $usersQuery->first()->first_name,
                'last_name' => $usersQuery->first()->last_name,
                'primary_email' => $usersQuery->first()->email,
                'credentials' => $usersQuery->first()->title,
                'event_external_id' => '',
                'master_external_id' => $usersQuery->first()->id,
                'allowed_mails' => '',
                'allowed_sms' => '',
                'bio' => '',
                'enabled_for_email' => false,
                'enabled_for_sms' => false,
                'evaluator_id' => ''
            ]);
            Log::info('New user created in pathable');

            $pathableUser = $this->client->SearchUser([
                'query' => $userEmail
            ]);
            return $pathableUser['results'][0]['id'];
        } 
        // Optimal case
        else {
            $pathableUser = $this->client->SearchUser([
                'query' => $userEmail
            ]);
            return $pathableUser['results'][0]['id'];
        }
    }
    /**
     * This function requires egprn id or pathable external id as (id of the egprn is external_id of pathable) parameter, 
     * checking pathable for corresponding activity and returns its id, it also handles the cases;
     * activity not found in pathable,
     * multiple activities with same "external id"  has found
     * 
     * Note that the existance of an activity on egprn is validated logically by sending egprn id as parameter to this function
     */
    public function getOrCreateActivity($id)
    {
        $results = $this->client->SearchMeeting([
            'with' => [
                'external_id' => $id
            ]
        ]);
        
        //Check for activity
        $resultCount = count($results['results']);

        if ($resultCount == 0) {
            Log::notice('Activity does not exist in pathable');

            // Create instance of Activities to get activity information
            $Activities = TableRegistry::get('Activities');
            $activitiesQuery = $Activities->find('all', [
                'conditions' => [
                    'id' => "$id"
                ]
            ]);
            
            // Create activity
            $this->client->CreateMeeting([
                'name' => $activitiesQuery->first()->title,
                'external_id' => $activitiesQuery->first()->id,
                'date' => $activitiesQuery->first()->start->i18nFormat('yyyy-MM-dd'),
                'start_time' => $activitiesQuery->first()->start->i18nFormat('HH:mm'),
                'end_time' => $activitiesQuery->first()->end->i18nFormat('HH:mm')
            ]);
            Log::info('New activity created in pathable');

            // Search again for activity to get its pathable id
            $results = $this->client->SearchMeeting([
                'with' => [
                    'external_id' => $id
                ]
            ]);
            return ($results['results'][0]['id']);
            
        } else if ($resultCount > 1) {
            Log::notice('Multiple activities conflicts in pathable, deleting all for system integrity');
            foreach ($results['results'] as $value) {
                $this->client->DeleteMeeting([
                    'id' => $value['id']
                ]);
            }
            Log::notice('Conflicting activities has been cleared from pathable');

            // Get egprn activity information in order to create new one in pathable
            $Activities = TableRegistry::get('Activities');
            $activitiesQuery = $Activities->find('all', [
                'conditions' => [
                    'id' => "$id"
                ]
            ]);
            
            // Create activity
            $this->client->CreateMeeting([
                'name' => $activitiesQuery->first()->title,
                'external_id' => $activitiesQuery->first()->id,
                'date' => $activitiesQuery->first()->start->i18nFormat('yyyy-MM-dd'),
                'start_time' => $activitiesQuery->first()->start->i18nFormat('HH:mm'),
                'end_time' => $activitiesQuery->first()->end->i18nFormat('HH:mm')
            ]);
            Log::notice('New activity created in pathable');
            
            // Search again for activity to get its pathable id
            $results = $this->client->SearchMeeting([
                'with' => [
                    'external_id' => $id
                ]
            ]);
            return ($results['results'][0]['id']);

            // Optimal case
        } else {
            return ($results['results'][0]['id']);
        }
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