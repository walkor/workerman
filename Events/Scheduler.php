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
    public function commonFunc($fd,$connect_id){
        $task = $func = $this->taskMap[$connect_id];
        if($task instanceof Task){
            $task->exec();
            if($task->isFinished()){
                unset($this->taskMap[$connect_id]);
            }
        }else{
            $func($fd,$connect_id);
        }
    }

    /**
     * add fd to react.event is best
     * @param $fd
     * @param $flag
     * @param $connect_id
     * @return bool
     */
    public function addTaskEvent($fd,$flag,$connect_id,$event = 'event',$func = NULL){
        //$this->taskMap[$connect_id] = $func; //you should use old function
        switch ($event){
            case 'event':
                $fd_key = (int)$fd;
                $real_flag = $flag === self::EV_READ ? \Event::READ | \Event::PERSIST : \Event::WRITE | \Event::PERSIST;
                $event = new \Event($this->_eventBase, $fd, $real_flag, array($this,'commonFunc'), array($fd,$connect_id));
                if (!$event||!$event->add()) {
                    return false;
                }
                $this->_allEvents[$fd_key][$flag] = $event;
                return true;
            case 'libevent':
                $fd_key    = (int)$fd;
                $real_flag = $flag === self::EV_READ ? EV_READ | EV_PERSIST : EV_WRITE | EV_PERSIST;
                $event = event_new();
                if (!event_set($event, $fd, $real_flag, array($this,'commonFunc'), array($fd,$connect_id))) {
                    return false;
                }
                if (!event_base_set($event, $this->_eventBase)) {
                    return false;
                }

                if (!event_add($event)) {
                    return false;
                }
                $this->_allEvents[$fd_key][$flag] = $event;

                return true;

        }

    }
}