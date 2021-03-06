<?php
/**
 * Copyright 2010 - 2015, Cake Development Corporation (+1 702 425 5085) (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2010 - 2015, Cake Development Corporation (+1 702 425 5085) (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Uskur\CakePHPPathable\Event\PathableListener;
Configure::load('Uskur/CakePHPPathable.pathable');
$pathableListener = new PathableListener();
EventManager::instance()->on($pathableListener);