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
        
        /*Pathable method for adding user to any activity requires user id and activity id, 
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
        /*
         * DeleteUser method of pathable client accepts user id as parameter, user id acquired by SearchUser method of pathable.
         * SearchUser method of pathable client accepts email as parameter, user email is acquired from "Users" table.
         */
        $users = TableRegistry::get('Users');
        $user = $users->get($deleteRegister->user_id);
        $pathableUser = $this->client->SearchUser([
            'query' => $user->email
        ]);
        // Check if the egprn user also exists in pathable
        if ($pathableUser['total_entries'] == 0) {
            // log notice that the user found in pathable
            dd('delete this "dd()" and log to database PathableListener.php - 159');
        } else {
            $this->client->DeleteUser([
                'id' => $pathableUser['results'][0]['id']
            ]);
            // log notice that the user has been deleted
        }
    }

    public function createMeeting($event, $meetingCreate)
    {
        $this->getOrCreateActivity($meetingCreate['id']);
    }
// Waiting further data for implementation
//     public function editMeeting($event, $meetingEdit)
//     {
//         $results = $this->client->SearchMeeting([
//             'with' => [
//                 'external_id' => $meetingEdit->id
//             ]
//         ]);

//         $resultCount = count($results['results']);

//         if ($resultCount == 0) {
//             // Log::notice('Event does not exist in pathable, creating new event instead');
//             $this->client->CreateMeeting([
//                 'name' => $meetingEdit->title,
//                 'external_id' => $meetingEdit->id,
//                 'date' => $meetingEdit->start->i18nFormat('yyyy-MM-dd'),
//                 'start_time' => $meetingEdit->start->i18nFormat('HH:mm'),
//                 'end_time' => $meetingEdit->end->i18nFormat('HH:mm')
//             ]);
//             // Log::notice('New updated event created.');
//         } else if ($resultCount > 1) {
//             // Log::info('Could not update event, multiple events exist in pathable, deleting conflicted events for system integrity.');
//             foreach ($results['results'] as $value) {
//                 $this->client->DeleteMeeting([
//                     'id' => $value['id']
//                 ]);
//             }
//             // Log::info('Conflicted events deleted.');

//             // Log::info('Creating new updated event.');
//             $this->client->CreateMeeting([
//                 'name' => $meetingEdit->title,
//                 'external_id' => $meetingEdit->id,
//                 'date' => $meetingEdit->start->i18nFormat('yyyy-MM-dd'),
//                 'start_time' => $meetingEdit->start->i18nFormat('HH:mm'),
//                 'end_time' => $meetingEdit->end->i18nFormat('HH:mm')
//             ]);
//             // Log::info('New event created.');
//         } // edit meeting does not exist in pathable,this part to be considered
//         else {
//             $this->client->CreateMeeting([
//                 'name' => $meetingEdit->title,
//                 // 'external_id' => $meetingEdit->id,
//                 'date' => $meetingEdit->start->i18nFormat('yyyy-MM-dd'),
//                 'start_time' => $meetingEdit->start->i18nFormat('HH:mm'),
//                 'end_time' => $meetingEdit->end->i18nFormat('HH:mm')
//             ]);
//         }
//     }

    public function deleteMeeting($event, $meetingDelete)
    {
        $results = $this->client->SearchMeeting([
            'with' => [
                'external_id' => $meetingDelete->id
            ]
        ]);

        $resultCount = count($results['results']);

        if ($resultCount == 0) {
            // Log::notice('Event does not exist in pathable');
        } else if ($resultCount > 1) {
            foreach ($results['results'] as $value) {
                $this->client->DeleteMeeting([
                    'id' => $value['id']
                ]);
            }
            // Log::notice('Multiple events exist in pathable');
        } else {
            $this->client->DeleteMeeting([
                'id' => $results['results'][0]['id']
            ]);
            // Log::info('Successfully deleted in pathable');
        }
    }

    public function userLogin($event, $user)
    {
        $Event = TableRegistry::get('Events');
        $Users = TableRegistry::get('Users');
        $currentEvent = $Event->getCurrentEvent();

        // Get login users' registered event informations
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
        
        foreach ($user['registers'][0]['activities'] as $activities) {
            $activityId = $this->getOrCreateActivity($activities['id']);
            $this->client->AddaUserToMeeting([
                'group_id' => $activityId,
                'user_id' => $userId
            ]);
        }
        
        //Pathable session information of user
        $Session = $this->client->GetSessionbyEmail([
            'primary_email' => $user->email
        ]);
        $authenticationUrl = $Session['authentication_url'];
        
        $dest = Router::url($event->subject()->Auth->redirectUrl(),true);
        return $event->subject()->redirect("$authenticationUrl&dest=$dest");

    }

    // This function checks if the user exist in pathable to get its id, also handles the case of user not found in pathable;
    public function getOrCreateUser($userEmail)
    {
        // Search for user in pathable to get the user pathable id
        $pathableUser = $this->client->SearchUser([
            'query' => $userEmail
        ]);
        // if no users found
        // log notice that user not found
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
            // log notice related new user created

            // Search again for the user in pathable to return the new pathable user id
            $pathableUser = $this->client->SearchUser([
                'query' => $userEmail
            ]);
            return $pathableUser['results'][0]['id'];
        } // optimal case where the user is found and id returned (multiple users not allowed in pathable)
        else {

            $pathableUser = $this->client->SearchUser([
                'query' => $userEmail
            ]);
            return $pathableUser['results'][0]['id'];
        }
    }

    /*
     * This function checks pathable for corresponding activity and returns its pathable id, it also handles the cases;
     * activity/activities not found in pathable,
     * multiple activities with same "external_id" (id of the egprn is external_id of pathable) has found
     *
     * Note that the existance of activity of egprn is validated logically by sending egprn id as parameter to this function
     */
    public function getOrCreateActivity($id)
    {
        // check if the activity exists in pathable
        $results = $this->client->SearchMeeting([
            'with' => [
                'external_id' => $id
            ]
        ]);

        $resultCount = count($results['results']);

        if ($resultCount == 0) {
            // Log::notice('Activity does not exist in pathable');
            // Get egprn activity information in order to create new one in pathable
            $Activities = TableRegistry::get('Activities');
            $activitiesQuery = $Activities->find('all', [
                'conditions' => [
                    'id' => "$id"
                ]
            ]);

            // Log::notice('Creating new activity in pathable');
            // create activity
            $this->client->CreateMeeting([
                'name' => $activitiesQuery->first()->title,
                'external_id' => $activitiesQuery->first()->id,
                'date' => $activitiesQuery->first()->start->i18nFormat('yyyy-MM-dd'),
                'start_time' => $activitiesQuery->first()->start->i18nFormat('HH:mm'),
                'end_time' => $activitiesQuery->first()->end->i18nFormat('HH:mm')
            ]);
            // Log::notice('New activity has created in pathable');

            // search again for activity to get its pathable id
            $results = $this->client->SearchMeeting([
                'with' => [
                    'external_id' => $id
                ]
            ]);
            return ($results['results'][0]['id']);
        } else if ($resultCount > 1) {
            // Log::notice('Multiple activities conflicts in pathable, deleting all for system integrity');
            foreach ($results['results'] as $value) {
                $this->client->DeleteMeeting([
                    'id' => $value['id']
                ]);
            }
            // Log::notice('Conflicting activities has been cleared from pathable');

            // Log::notice('Creating new activity in pathable');
            // Get egprn activity information in order to create new one in pathable
            $Activities = TableRegistry::get('Activities');
            $activitiesQuery = $Activities->find('all', [
                'conditions' => [
                    'id' => "$id"
                ]
            ]);

            // create activity
            $this->client->CreateMeeting([
                'name' => $activitiesQuery->first()->title,
                'external_id' => $activitiesQuery->first()->id,
                'date' => $activitiesQuery->first()->start->i18nFormat('yyyy-MM-dd'),
                'start_time' => $activitiesQuery->first()->start->i18nFormat('HH:mm'),
                'end_time' => $activitiesQuery->first()->end->i18nFormat('HH:mm')
            ]);

            // search again for activity to get its pathable id
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