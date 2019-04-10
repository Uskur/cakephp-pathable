<?php
namespace Uskur\CakePHPPathable\Utility;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;
use Http\Client\Exception;
use Uskur\PathableClient\PathableClient;
use Cake\Routing\Router;

class PathableUtility
{
    
    public $client = null;
    
    function __construct()
    {
        $this->initClient();
    }
    
    public function syncUser($id, $userOnly = false)
    {
        // get egprn user information to create new user in pathable
        $Event = TableRegistry::get('Events');
        $currentEvent = $Event->getCurrentEvent();
        
        $Users = TableRegistry::get('Users');
        $user = $Users->get($id, [
            'contain' => [
                'Responses',
                'UserGroups',
                'Registers' => [
                    'Activities.Events',
                    'conditions' => [
                        'Registers.event_id' => $currentEvent->id,
                        'Registers.registration_date IS NOT NULL'
                    ]
                ]
            ]
        ]);
        if (empty($user)) {
            Log::error('User does not exist', $id);
            return false;
        }
        foreach ($user->responses as $response) {
            // current designation question id is: 7f381399-d336-4e2d-9136-84b6485b3308
            if ($response->question_id === '7f381399-d336-4e2d-9136-84b6485b3308') {
                $userDesignaion = $response->response;
            }
        }
        $pathableUserData = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'primary_email' => $user->email,
            'organization_name' => $user->institute,
            'credentials' => $userDesignaion,
            'title' => $user->title,
            'master_external_id' => $user->id,
            'event_external_id' => isset($user->registers[0]->id) ? $user->registers[0]->id : '',
            'address' => $user->full_address,
            'allowed_mails' => ['custom','daily_update','private_notices','system'],
            'allowed_sms' => '',
            'bio' => '',
            'enabled_for_email' => false,
            'enabled_for_sms' => false,
            'evaluator_id' => '',
            'photo_deferred_url' => !empty($user->avatar)?Router::url([
                'prefix' => false,
                'plugin' => 'Uskur/Attachments',
                'controller' => 'Attachments',
                'action' => 'image',
                $user->avatar
            ], true):null
        ];
        $pUser = $this->getUser($user->email, true);
        // no need to sync user if not registered
        if ($pUser === false && empty($user->registers)) {
            Log::error("User {$user->email} does not exist in pathable and not registered locally. No need to send to pathable.", $id);
            return false;
        }
        if ($pUser === false && ! empty($user->registers)) {
            try {
                $createResult = $this->client->CreateUser($pathableUserData);
            } catch (Exception $e) {
                Log::error("New user {$user->email} could not be created pathable");
            }
            $pUser = $this->getUser($user->email, true);
            Log::info("New user {$user->email} created in pathable", $pathableUserData);
        } else {
            $pathableUserData['user_id'] = $pUser['id'];
            $this->client->UpdateUser($pathableUserData);
            Log::info("Existing user {$user->email} updated in pathable", $pathableUserData);
        }
        
        $responseToAnswer = [
            'acbc7ce1-1315-44d7-9a6a-ad032b15433d' => 4940,
            'e8f1d636-2a48-4218-9e77-210ce810ca21' => 4939,
            '2f635e1a-c3e4-4538-8185-518f4eb31974' => 4937
        ];
        foreach ($user->responses as $response) {
            if (isset($responseToAnswer[$response->question_id])) {
                $answered = false;
                if (! empty($pUser['answers'])) {
                    foreach ($pUser['answers'] as $answer) {
                        if ($answer['question_id'] === $responseToAnswer[$response->question_id]) {
                            $this->client->UpdateQuestion([
                                'question_id' => $responseToAnswer[$response->question_id],
                                'user_id' => $pUser['id'],
                                'answer_id' => $answer['id'],
                                'answer' => $response->response
                            ]);
                            $answered = true;
                        }
                    }
                }
                if (! $answered) {
                    $this->client->AnswerQuestion([
                        'question_id' => $responseToAnswer[$response->question_id],
                        'user_id' => $pUser['id'],
                        'answer' => $response->response
                    ]);
                }
            }
        }
        
        // handle user groups
        $userGroups = implode(Hash::extract($user->user_groups, '{n}.name'), ' ,');
        $answered = false;
        if (! empty($pUser['answers'])) {
            foreach ($pUser['answers'] as $answer) {
                if ($answer['question_id'] === 4938) {
                    $this->client->UpdateQuestion([
                        'question_id' => '4938',
                        'user_id' => $pUser['id'],
                        'answer_id' => $answer['id'],
                        'answer' => $userGroups
                    ]);
                    $answered = true;
                    break;
                }
            }
        }
        
        if (! $answered) {
            $this->client->AnswerQuestion([
                'question_id' => 4938,
                'user_id' => $pUser['id'],
                'answer' => $userGroups
            ]);
        }
        if (! $userOnly) {
            
            $membershipsToDelete = Hash::combine($pUser['memberships'], "{n}.id", "{n}.group_id");
            if (! empty($user->registers)) {
                /*
                 * For system integrity, get user's registered activities from egprn, then;
                 * if activity does not exist in pathable, creates activity, then registers user to the activity,
                 * else, registers user to the activity
                 */
                
                foreach ($user->registers[0]->activities as $activity) {
                    $meetingId = $this->getMeeting($activity->id);
                    if ($meetingId === false) {
                        $meetingId = $this->syncMeeting($activity->id, true);
                        if ($meetingId === false) {
                            continue;
                        }
                    }
                    $exists = false;
                    foreach ($pUser['memberships'] as $membership) {
                        if ($membership['group']['id'] === $meetingId) {
                            $exists = true;
                            unset($membershipsToDelete[$membership['id']]);
                            break;
                        }
                    }
                    
                    if (! $exists) {
                        $this->client->AddaUserToMeeting([
                            'group_id' => $meetingId,
                            'user_id' => $pUser['id']
                        ]);
                    }
                }
            }
            
            foreach ($pUser['memberships'] as $membership) {
                // private meetings don't get an external_id
                if (isset($membershipsToDelete[$membership['id']]) && $membership['class_name'] == "Groups::Meetings::Attendance") {
                    $result = $this->client->DeleteMembership([
                        'group_id' => $membership['group']['id'],
                        'id' => $membership['id']
                    ]);
                }
            }
        }
        
        // update cache
        $this->getUser($user->email, true);
        return $pUser;
    }
    
    /**
     * This function checks if the user exist in pathable to get its id, also handles the case of user not found in pathable;
     */
    public function getUser($userEmail, $skipCache = false)
    {
        $pathableUsers = Cache::read('Pathable.users');
        if (isset($pathableUsers[$userEmail]) && ! $skipCache) {
            Log::info("Pathable $userEmail fetched from cache");
            return $pathableUsers[$userEmail];
        }
        try {
            $pathableUser = $this->client->SearchUser([
                'with' => [
                    'emails.email' => $userEmail
                ]
            ]);
        } catch (Exception $e) {
            Log::error('User could not be found in pathable', $userEmail);
        }
        
        // pathable user existance check requires 2 step check, deleted user's information can still return,
        // in that case, visible must be equal to zero
        if ($pathableUser['results'] == null || $pathableUser['results'][0]['visible'] === 0) {
            Log::error("User $userEmail could not be found in pathable");
            return false;
        }
        
        $pUser = $this->client->GetUser([
            'id' => $pathableUser['results'][0]['id']
        ]);
        
        $pathableUsers[$userEmail] = $pUser;
        Cache::write('Pathable.users', $pathableUsers);
        
        return $pUser;
    }
    
    public function getMeeting($activityId, $skipCache = false)
    {
        $pathableMeetings = Cache::read('Pathable.meetings');
        if (isset($pathableMeetings[$activityId]) && ! $skipCache) {
            return $pathableMeetings[$activityId];
        }
        $results = $this->client->SearchMeeting([
            'with' => [
                'external_id' => $activityId
            ]
        ]);
        
        // Check for activity
        $resultCount = count($results['results']);
        
        if ($resultCount == 1) {
            $pathableMeetings[$activityId] = $results['results'][0]['id'];
            Cache::write('Pathable.meetings', $pathableMeetings);
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
        return false;
    }
    
    /**
     * This function requires egprn id or pathable external id as (id of the egprn is external_id of pathable) parameter,
     * checking pathable for corresponding activity and returns its id, it also handles the cases;
     * activity not found in pathable,
     * multiple activities with same "external id" has found
     *
     * Note that the existance of an activity on egprn is validated logically by sending egprn id as parameter to this function
     */
    public function syncMeeting($activityId, $meetingOnly = false)
    {
        $meetingId = $this->getMeeting($activityId, true);
        
        // Create instance of Activities to get activity information
        $Activities = TableRegistry::get('Activities');
        $activity = $Activities->get($activityId, [
            'contain' => [
                'Registers' => [
                    'conditions' => [
                        'Registers.registration_date IS NOT NULL'
                    ]
                ],
                'Registers.Users' => []
            ]
        ]);
        if (empty($activity)) {
            Log::error('Activity does not exist', $activityId);
            return false;
        }
        
        $meetingData = [
            'name' => $activity->title,
            'external_id' => $activity->id,
            'date' => $activity->start->i18nFormat('yyyy-MM-dd'),
            'start_time' => $activity->start->i18nFormat('HH:mm'),
            'end_time' => $activity->end->i18nFormat('HH:mm')
        ];
        if ($meetingId === false) {
            $pathableMeeting = $this->client->CreateMeeting($meetingData);
            $meetingId = $pathableMeeting['id'];
            Log::info('New activity created in pathable', $activity->title);
        } else {
            $meetingData['id'] = $meetingId;
            $pathableMeeting = $this->client->EditMeeting($meetingData);
            Log::info('Activity updated in pathable', $activity->title);
        }
        
        // Create activity
        if (! $meetingOnly) {
            try {
                $memberships = $this->client->GetMembership([
                    'id' => $meetingId
                ]);
                $membershipsToDelete = Hash::combine($memberships['results'], "{n}.id", "{n}.group.id");
                if (! empty($activity->registers)) {
                    foreach ($activity->registers as $register) {
                        $exists = false;
                        $pathableUser = $this->getUser($register->user->email);
                        if ($pathableUser === false) {
                            $pathableUser = $this->syncUser($register->user_id, true);
                            if ($pathableUser === false) {
                                // if not created no need for registration
                                continue;
                            }
                        }
                        foreach ($memberships['results'] as $membership) {
                            if ($pathableUser['id'] == $membership['user']['id']) {
                                $exists = true;
                                unset($membershipsToDelete[$membership['id']]);
                                break;
                            }
                        }
                        if (! $exists) {
                            $this->client->AddaUserToMeeting([
                                'group_id' => $meetingId,
                                'user_id' => $pathableUser['id']
                            ]);
                            Log::info("Pathable user {$pathableUser['id']} added to meeting {$meetingId}");
                        }
                    }
                }
                foreach ($memberships['results'] as $membership) {
                    // private meetings don't get an external_id
                    if (isset($membershipsToDelete[$membership['id']])) {
                        $result = $this->client->DeleteMembership([
                            'group_id' => $membership['group']['id'],
                            'id' => $membership['id']
                        ]);
                        Log::info("Pathable user {$pathableUser['id']} removed from meeting {$meetingId}");
                    }
                }
            } catch (Exception $e) {
                Log::info('New meeting could not be created in pathable', $activity);
            }
        }
        
        return $this->getMeeting($activityId, true);
    }
    
    public function deleteMeeting($activityId)
    {
        $meetingId = $this->getMeeting($activityId);
        if ($meetingId !== false) {
            $this->client->DeleteMeeting([
                'id' => $meetingId
            ]);
            Log::info('Successfully deleted meeting in pathable', $meetingId);
        }
        
        $pathableMeetings = Cache::read('Pathable.meetings');
        unset($pathableMeetings[$activityId]);
        Cache::write('Pathable.meetings', $pathableMeetings);
    }
    
    public function deleteUser($userEmail)
    {
        $pathableUser = $this->getUser($userEmail);
        if ($pathableUser !== false) {
            $this->client->DeleteUser([
                'id' => $pathableUser['id']
            ]);
        }
        
        $pathableUsers = Cache::read('Pathable.users');
        unset($pathableUsers[$userEmail]);
        Cache::write('Pathable.users', $pathableUsers);
    }
    
    public function syncAll()
    {
        $allMeetings = $this->client->SearchMeeting([
            'per_page' => 1000
        ]);
        $meetingsToDelete = Hash::combine($allMeetings['results'], "{n}.id", "{n}.name");
        
        // Create instance of Activities to get activity information
        $Activities = TableRegistry::get('Activities');
        $currentEvent = $Activities->Events->getCurrentEvent();
        $activities = $Activities->find('all')->where([
            'event_id' => $currentEvent->id
        ]);
        foreach ($activities as $activity) {
            $meetingId = $this->syncMeeting($activity->id, true);
            unset($meetingsToDelete[$meetingId]);
        }
        
        foreach ($meetingsToDelete as $meetingId => $meetingName) {
            $this->client->DeleteMeeting([
                'id' => $meetingId
            ]);
        }
        
        $allUsers = $this->client->SearchUser([
            'per_page' => 1000
        ]);
        $usersToDelete = Hash::combine($allUsers['results'], "{n}.id", "{n}.primary_email");
        $Events = TableRegistry::get('Events');
        $currentEvent = $Events->getCurrentEvent();
        $registers = $Events->Registers->find('all')
        ->where([
            'event_id' => $currentEvent->id
        ])
        ->contain([
            'Users.UserGroups',
            'Activities'
        ]);
        
        foreach ($registers as $register) {
            $user = $this->syncUser($register->user_id, true);
            unset($usersToDelete[$user['id']]);
        }
        return $usersToDelete;
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