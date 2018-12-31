<?php

namespace Uskur\CakePHPPathable\Shell\Task;

use Queue\Shell\Task\QueueTask;
use Uskur\CakePHPPathable\Utility\PathableUtility;



class QueuePathableTask extends QueueTask {

	/**
	 * Timeout for run, after which the Task is reassigned to a new worker.
	 *
	 * @var int
	 */
	public $timeout = 10;

	/**
	 * Number of times a failed instance of this task should be restarted before giving up.
	 *
	 * @var int
	 */
	public $retries = 10;

	/**
	 * Example add functionality.
	 * Will create one example job in the queue, which later will be executed using run();
	 *
	 * @return void
	 */
	public function add() {
	    $this->out('Pathable Tasks');
	    $this->err('Please use the UI to create new tasks.');
	}

	/**
	 * Example run function.
	 * This function is executed, when a worker is executing a task.
	 * The return parameter will determine, if the task will be marked completed, or be requeued.
	 *
	 * @param array $data The array passed to QueuedJobsTable::createJob()
	 * @param int $jobId The id of the QueuedJob entity
	 * @return bool Success
	 */
	public function run(array $data, $jobId) {
		$Pathable = new PathableUtility();
		if(isset($data['user'])) {
		    $Pathable->syncUser($data['user']);
		}
		elseif(isset($data['activity'])) {
		    $Pathable->syncMeeting($data['activity']);
		}
		elseif(isset($data['deleteActivity'])) {
		    $Pathable->deleteMeeting($data['deleteActivity']);
		}
		return true;
	}

}
