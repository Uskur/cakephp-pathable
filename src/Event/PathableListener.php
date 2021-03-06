<?php
namespace Uskur\CakePHPPathable\Event;

use Cake\Cache\Cache;
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
use Uskur\CakePHPPathable\Utility\PathableUtility;

/**
 *
 * @property \Uskur\PathableClient\PathableClient $client
 * @author burak
 *        
 */
class PathableListener implements EventListenerInterface
{

    public $pathableUtility;

    public function implementedEvents()
    {
        $this->pathableUtility = new PathableUtility();
        return [
            'Model.Register.newRegistration' => 'newRegistration',
            'Model.Register.editRegistration' => 'newRegistration',
            'Model.Register.deleteRegistration' => 'deleteRegistration',
            'Model.Activity.newActivity' => 'newActivity',
            'Model.Activity.editActivity' => 'editActivity',
            'Model.Activity.deleteActivity' => 'deleteActivity',
            'Users.Component.UsersAuth.beforeLogin' => 'beforeLogin',
            'Users.Component.UsersAuth.afterLogin' => 'userLogin',
            'Users.Component.UsersAuth.beforeLogout' => 'beforeLogout',
            'Users.Component.UsersAuth.afterLogout' => 'userLogout',
            'Model.User.editUser' => 'editUser',
            'Model.User.deleteUser' => 'deleteUser'
        ];
    }

    public function newRegistration($event, $register)
    {
        TableRegistry::get('Queue.QueuedJobs')->createJob('Pathable', [
            'user' => $register->user_id
        ]);
    }

    public function deleteRegistration($event, $register)
    {
        $users = TableRegistry::get('Users');
        $user = $users->get($register->user_id);

        $this->pathableUtility->deleteUser($user->email);
    }

    public function newActivity($event, $activity)
    {
        TableRegistry::get('Queue.QueuedJobs')->createJob('Pathable', [
            'activity' => $activity->id
        ]);
    }

    public function editActivity($event, $activity)
    {
        TableRegistry::get('Queue.QueuedJobs')->createJob('Pathable', [
            'activity' => $activity->id
        ]);
    }

    /**
     * This function deleting an activity from Pathable when the activity is deleted in egprn.
     * If Pathable has multiple events
     * with the same external id, they will all be deleted and the new activity will be created for system integrity.
     */
    public function deleteActivity($event, $activity)
    {
        TableRegistry::get('Queue.QueuedJobs')->createJob('Pathable', [
            'deleteActivity' => $activity->id
        ]);
    }
    
    public function beforeLogin($event)
    {
        if($event->subject()->request->getQuery('mode') == 'native') {
            $event->subject()->request->session()->write('Pathable.mode', 'native');
        }
        elseif($event->subject()->request->getQuery('mode') == 'web') {
            $event->subject()->request->session()->write('Pathable.mode', 'web');
        }
    }

    /**
     * This function gets session information from Pathable for simultaneous login.
     * Since Pathable accepts email only for authentication token,
     * we require table instances of Events and Users and current event information of egprn in order to get current login user email.
     */
    public function userLogin($event, $user)
    {
        // Table instances in egprn to get user email
        $Event = TableRegistry::get('Events');
        $currentEvent = $Event->getCurrentEvent();
        // get egprn user information to create new user in pathable
        $Users = TableRegistry::get('Users');
        $user = $Users->find('all', [
            'conditions' => [
                'email' => $user['email']
            ]
        ])
            ->contain([
            'Registers' => [
                'conditions' => [
                    'Registers.event_id' => $currentEvent->id,
                    'Registers.registration_date IS NOT NULL'
                ]
            ]
        ])
            ->first();

        if (! empty($user->registers)) {
            try {
                $pathableUser = $this->pathableUtility->getUser($user->email);
                if ($pathableUser === false) {
                    $pathableUser = $this->pathableUtility->syncUser($user->id, true);
                }
            } catch (Exception $e) {
                Log::error('User could not be login in pathable', $user->email);
            }
            // Pathable session information of user
            $Session = $this->pathableUtility->client->GetSessionbyEmail([
                'primary_email' => $user['email']
            ]);
            $authenticationUrl = $Session['authentication_url'];
            $dest = Router::url($event->subject()->Auth->redirectUrl(), true);
            $mode = $event->subject()->request->getQuery('mode')?$event->subject()->request->getQuery('mode'):($event->subject()->request->session()->read('Pathable.mode')?$event->subject()->request->session()->read('Pathable.mode'):null);
            if($mode == 'native') {
                $url = explode('session?', $authenticationUrl);
                $dest = "{$url[0]}native";
            }
            elseif($mode == 'web') {
                $url = explode('session?', $authenticationUrl);
                $dest = $url[0];
            }
            elseif(!empty($event->subject()->request->getQuery('return_url'))) {
                $dest = $event->subject()->request->getQuery('return_url');
            }

            $event->subject()->request->session()->write('Pathable.loggedin', true);

            return $event->subject()->redirect("$authenticationUrl&dest=$dest");
        }
    }
    
    public function beforeLogout($event)
    {
        //save logged in status before session gets destroyed
        $event->subject()->pathableLoggedIn = $event->subject()->request->session()->read('Pathable.loggedin');
    }

    public function userLogout($event, $user)
    {
        if ($event->subject()->pathableLoggedIn || $event->subject()->request->getQuery('from') == 'pathable') {
            $Session = $this->pathableUtility->client->GetSessionbyEmail([
                'primary_email' => $user['email']
            ]);
            $authenticationUrl = $Session['authentication_url'];
            $dest = Router::url($event->subject()->Auth->redirectUrl(), true);
            $authenticationDestroy = str_replace('/session', '/session/destroy', $authenticationUrl);
            return $event->subject()->redirect("$authenticationDestroy&dest=$dest");
        }
    }

    public function editUser($event, $user)
    {
        TableRegistry::get('Queue.QueuedJobs')->createJob('Pathable', [
            'user' => $user->id
        ]);
    }

    public function deleteUser($event, $user)
    {
        $this->pathableUtility->deleteUser($user->email);
    }
}