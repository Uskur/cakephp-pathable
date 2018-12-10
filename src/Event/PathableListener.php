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
use Cake\Utility\Hash;
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
            // @todo "meeting -> activity"
            'Model.Activity.newActivity' => 'newActivity',
            'Model.Activity.editActivity' => 'editActivity',
            'Model.Activity.deleteActivity' => 'deleteActivity',
            'Users.Component.UsersAuth.afterLogin' => 'userLogin'
        ];
    }

    public function newRegistration($event, $register)
    {
        /**
         * Pathable method for adding user to any activity requires user id and activity id,
         * related custom functions are called here in order to get required ids of activities and user
         */
        $this->getOrCreateUser($register->user->email);
    }

    public function deleteRegistration($event, $register)
    {
        /**
         * DeleteUser method of pathable client accepts user id as parameter, user id acquired by SearchUser method of pathable.
         * SearchUser method of pathable client accepts email as parameter, user email is acquired from "Users" table.
         */
        $users = TableRegistry::get('Users');
        $user = $users->get($register->user_id);
        try {
            $pathableUser = $this->client->SearchUser([
                'query' => $user->email
            ]);

            if ($pathableUser['total_entries'] == 0) {
                Log::notice('The user not found in pathable', $user->email);
            } else {
                $this->client->DeleteUser([
                    'id' => $pathableUser['results'][0]['id']
                ]);
                Log::notice('The user has been deleted', $user->email);
            }
        } catch (Exception $e) {
            Log::error('The user meeting registration could not be removed in pathable', $user);
        }
    }

    public function newActivity($event, $activity)
    {
        $this->getOrCreateMeeting($activity['id']);
    }

    public function editActivity($event, $activity)
    {
        try {
            $activityId = $this->getOrCreateMeeting($activity->id);
            $this->client->EditMeeting([
                'id' => $activityId,
                'name' => $activity->title,
                'date' => $activity->start->i18nFormat('yyyy-MM-dd'),
                'start_time' => $activity->start->i18nFormat('HH:mm'),
                'end_time' => $activity->end->i18nFormat('HH:mm')
            ]);
        } catch (Exception $e) {
            Log::error('The meeting could not be edited in pathable', $activity->title);
        }
    }

    /**
     * This function deleting an activity from Pathable when the activity is deleted in egprn.
     * If Pathable has multiple events
     * with the same external id, they will all be deleted and the new activity will be created for system integrity.
     */
    public function deleteActivity($event, $activity)
    {
        try {
            $results = $this->client->SearchMeeting([
                'with' => [
                    'external_id' => $activity->id
                ]
            ]);

            $resultCount = count($results['results']);

            if ($resultCount == 0) {
                Log::notice('Meeting does not exist in pathable, nothing found to delete');
                return;
            }
            foreach ($results['results'] as $value) {
                $this->client->DeleteMeeting([
                    'id' => $value['id']
                ]);
                Log::info('Successfully deleted meeting in pathable', $value['id']);
            }
        } catch (Exception $e) {
            Log::error('The meeting could not be deleted in pathable', $activity->title);
        }
    }

    /**
     * This function gets session information from Pathable for simultaneous login.
     * Since Pathable accepts email only for authentication token,
     * we require table instances of Events and Users and current event information of egprn in order to get current login user email.
     */
    public function userLogin($event, $user)
    {
        $this->getOrCreateUser($user['email']);

        // Pathable session information of user
        // $Session = $this->client->GetSessionbyEmail([
        // 'primary_email' => $user['email']
        // ]);
        // $authenticationUrl = $Session['authentication_url'];
        // $dest = Router::url($event->subject()->Auth->redirectUrl(), true);
        // return $event->subject()->redirect("$authenticationUrl&dest=$dest");
    }

    /**
     * This function checks if the user exist in pathable to get its id, also handles the case of user not found in pathable;
     */
    public function getOrCreateUser($userEmail)
    {
        $pathableUser = $this->client->SearchUser([
            'query' => $userEmail
        ]);

        // pathable user existance check requires 2 step check, deleted user's information can still return,
        // in that case, visible must be equal to zero
        if ($pathableUser['results'] == null || $pathableUser['results'][0]['visible'] == 0) {
            Log::notice('User not found in pathable', $userEmail);
            // Table instances in egprn to get user email
            $Event = TableRegistry::get('Events');
            $currentEvent = $Event->getCurrentEvent();

            // get egprn user information to create new user in pathable
            $Users = TableRegistry::get('Users');
            $user = $Users->find('all', [
                'conditions' => [
                    'email' => $userEmail
                ]
            ])
                ->contain([
                'Responses',
                'UserGroups',
                'Registers' => [
                    'Activities.Events',
                    'conditions' => [
                        'Registers.event_id' => $currentEvent->id,
                        'Registers.registration_date IS NOT NULL'
                    ]
                ]
            ])
                ->first();
            try {
                $pathableUser = $this->client->CreateUser([
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'primary_email' => $user->email,
                    'credentials' => $user->title,
                    'event_external_id' => '',
                    'master_external_id' => $user->id,
                    'allowed_mails' => '',
                    'allowed_sms' => '',
                    'bio' => '',
                    'enabled_for_email' => false,
                    'enabled_for_sms' => false,
                    'evaluator_id' => ''
                ]);

                // @todo Registers profile questions will be added
                // if user change answer in pathable, answeres will be desynch with egprn (there is no check in our code)
                $userInformation1 = Hash::extract($user->user_groups, '{n}.name');
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
            } catch (Exception $e) {
                Log::error('New user could not be created in pathable', $userEmail);
            }
            Log::info('New user created in pathable', $userEmail);
            if (! empty($user->registers)) {
                /*
                 * For system integrity, get user's registered activities from egprn, then;
                 * if activity does not exist in pathable, creates activity, then registers user to the activity,
                 * else, registers user to the activity
                 */
                foreach ($user->registers[0]->activities as $activity) {
                    $meetingId = $this->getOrCreateMeeting($activity->id);
                    $this->client->AddaUserToMeeting([
                        'group_id' => $meetingId,
                        'user_id' => $pathableUser['id']
                    ]);
                }
            }
        }
        // dd($pathableUser['results']);
        return $pathableUser['results'][0]['id'];
    }

    /**
     * This function requires egprn id or pathable external id as (id of the egprn is external_id of pathable) parameter,
     * checking pathable for corresponding activity and returns its id, it also handles the cases;
     * activity not found in pathable,
     * multiple activities with same "external id" has found
     *
     * Note that the existance of an activity on egprn is validated logically by sending egprn id as parameter to this function
     */
    public function getOrCreateMeeting($id)
    {
        $results = $this->client->SearchMeeting([
            'with' => [
                'external_id' => $id
            ]
        ]);

        // Check for activity
        $resultCount = count($results['results']);

        if ($resultCount == 1) {
            return ($results['results'][0]['id']);
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
        // Create instance of Activities to get activity information
        $Activities = TableRegistry::get('Activities');
        $activity = $Activities->get($id, [
            'contain' => [
                'Registers.Users'
            ]
        ]);

        // Create activity

        try {
            $meeting = $this->client->CreateMeeting([
                'name' => $activity->title,
                'external_id' => $activity->id,
                'date' => $activity->start->i18nFormat('yyyy-MM-dd'),
                'start_time' => $activity->start->i18nFormat('HH:mm'),
                'end_time' => $activity->end->i18nFormat('HH:mm')
            ]);

            Log::info('New activity created in pathable', $activity->title);

            // @todo add activity members
            foreach ($activity->registers as $register) {
                $userID = $this->getOrCreateUser($register->user->email);
                $this->client->AddaUserToMeeting([
                    'group_id' => $meeting['id'],
                    'user_id' => $userID
                ]);
            }
        } catch (Exception $e) {
            Log::info('New meeting could not be created in pathable', $activity);
        }
        return ($meeting['id']);
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