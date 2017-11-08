<?php
/**
 * abstract task from Generator yield
 */
namespace Workerman\Lib;
use \Generator;

class Task
{
    private $coroutine;
    private $beforeFirstYield = true;
    private $sendValue = null;


    public function __construct(Generator $coroutine)
    {
        $this->coroutine = $coroutine;
    }


    public function setSendValue($sendValue) {
        $this->sendValue = $sendValue;
    }

    public function exec(){
        if($this->beforeFirstYield){
            $this->beforeFirstYield = false;
            $res =  $this->coroutine->current();
            $this->sendValue = $res;
            return $res;
        }else{
            $retval =  $this->coroutine->send($this->sendValue);
            $this->sendValue = $retval;
            return $retval;
        }
    }

    public function isFinished() {
        return !$this->coroutine->valid();
    }

}