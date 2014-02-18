<?php
namespace Statistics\Web;
class Config
{
    // StatisticProvider 监听的端口，会向这个端口发送udp广播获取ip，然后从这个端口以tcp协议获取统计信息
    public static $ProviderPort = 55858;
}