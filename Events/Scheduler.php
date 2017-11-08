<?php
/**
 * because the loop function is in the event ext.so we should Integrate scheduler into event.
 * when the socket is ready.wo should get the connectid.so that wo can get the task too.
 */
namespace Workerman\Events;

use Workerman\lib\Task;

trait Scheduler
{
    /**
     * id=>task
     * or you can use it like id=>func.but i suggest you use old function(addEvent)
     *
     * @var array
     */
    public $taskMap = [];


    /**
     * when the socket is ready,exec this function.
     * @param $fd
     * @param $id
     */
    public function commonFunc($fd,$what,$params){
        $connect_id = isset($params[0]) ? $params[0] : 0;
        $task = isset($this->taskMap[$connect_id]) ? $this->taskMap[$connect_id] : NULL;
        $func = isset($params[1]) ? $params[1] : NULL;

        if($func && is_callable($func)){
            call_user_func($func,$fd);
        }
        if($task && $task instanceof Task){
            $task->exec();
            if($task->isFinished()){
                unset($this->taskMap[$connect_id]);
            }
        }
    }

}