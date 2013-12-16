<?php

interface IProtocol
{
    public static function input($data);
    public static function decode($data);
    public static function encode($data);
}
