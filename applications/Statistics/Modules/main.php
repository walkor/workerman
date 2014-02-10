<?php
function main($module, $interface, $date,$start_time, $offset)
{
    $module = 'WorkerMan';
    $interface = 'Statistics';
    multiRequestStAndModules($module, $interface, $date);
    $all_st_str = '';
    if(is_array(Cache::$statisticDataCache['statistic']))
    {
        foreach(Cache::$statisticDataCache['statistic'] as $ip=>$st_str)
        {
            $all_st_str .= $st_str;
        }
    }
    $data = formatSt($all_st_str, $date);
    $interface_name = '整体';
    $success_series_data = $fail_series_data = $success_time_series_data = $fail_time_series_data = array();
    foreach($data as $time_point=>$item)
    {
        if($item['total_count'])
        {
            $success_series_data[] = "[".($time_point*1000).",{$item['total_count']}]";
        }
        $fail_series_data[] = "[".($time_point*1000).",{$item['fail_count']}]";
        if($item['total_avg_time'])
        {
            $success_time_series_data[] = "[".($time_point*1000).",{$item['total_avg_time']}]";
        }
        $fail_time_series_data[] = "[".($time_point*1000).",{$item['fail_avg_time']}]";
    }
    $success_series_data = implode(',', $success_series_data);
    $fail_series_data = implode(',', $fail_series_data);
    $success_time_series_data = implode(',', $success_time_series_data);
    $fail_time_series_data = implode(',', $fail_time_series_data);
    $date = $start_time ? date('Y年m月d日', $start_time) : date('Y年m月d日');
    
    include ST_ROOT . '/Views/header.tpl.php';
    include ST_ROOT . '/Views/main.tpl.php';
    include ST_ROOT . '/Views/footer.tpl.php';
}

function multiRequestStAndModules($module, $interface, $date)
{
    Cache::$statisticDataCache['statistic'] = '';
    $buffer = json_encode(array('module'=>$module, 'interface'=>$interface, 'date'=>$date))."\n";
    $ip_list = (!empty($_GET['server_ip']) && is_array($_GET['server_ip'])) ? $_GET['server_ip'] : Cache::$ServerIpList;
    $reqest_buffer_array = array();
    $port = \Man\Core\Lib\Config::get('StatisticWorker.port') ? \Man\Core\Lib\Config::get('StatisticWorker.port') : 55656;
    foreach($ip_list as $ip)
    {
        $reqest_buffer_array["$ip:$port"] = $buffer;
    }
    $read_buffer_array = multiRequest($reqest_buffer_array);
    foreach($read_buffer_array as $address => $buf)
    {
        list($ip, $port) = explode(':',$address);
        $body_data = json_decode(trim($buf), true);
        $statistic_data = isset($body_data['statistic']) ? $body_data['statistic'] : '';
        $modules_data = isset($body_data['modules']) ? $body_data['modules'] : array();
        // 整理modules
        foreach($modules_data as $mod => $interfaces)
        {
            if(!isset(Cache::$modulesDataCache[$mod]))
            {
                Cache::$modulesDataCache[$mod] = array();
            }
            foreach($interfaces as $if)
            {
                Cache::$modulesDataCache[$mod][$if] = $if;
            }
        }
        Cache::$statisticDataCache['statistic'][$ip] = $statistic_data;
    }
}

function formatSt($str, $date)
{
    // time:[suc_count:xx,suc_cost_time:xx,fail_count:xx,fail_cost_time:xx]
    $st_data = array();
    $st_explode = explode("\n", $str);
    // 汇总计算
    foreach($st_explode as $line)
    {
        // line = IP time suc_count suc_cost_time fail_count fail_cost_time code_json
        $line_data = explode("\t", $line);
        if(!isset($line_data[5]))
        {
            continue;
        }
        $time_line = $line_data[1];
        $suc_count = $line_data[2];
        $suc_cost_time = $line_data[3];
        $fail_count = $line_data[4];
        $fail_cost_time = $line_data[5];
        if(!isset($st_data[$time_line]))
        {
            $st_data[$time_line] = array('suc_count'=>0, 'suc_cost_time'=>0, 'fail_count'=>0, 'fail_cost_time'=>0);
        }
        $st_data[$time_line]['suc_count'] += $suc_count;
        $st_data[$time_line]['suc_cost_time'] += $suc_cost_time;
        $st_data[$time_line]['fail_count'] += $fail_count;
        $st_data[$time_line]['fail_cost_time'] += $fail_cost_time;
    }
    // 按照时间排序
    ksort($st_data);
    // time => [total_count:xx,suc_count:xx,suc_avg_time:xx,fail_count:xx,fail_avg_time:xx,percent:xx]
    $data = array();
    // 计算成功率 耗时
    foreach($st_data as $time_line=>$item)
    {
        $data[$time_line] = array(
                'time'          => date('Y-m-d H:i:s', $time_line),
                'total_count'   => $item['suc_count']+$item['fail_count'],
                'total_avg_time'=> $item['suc_count']+$item['fail_count'] == 0 ? 0 : round(($item['suc_cost_time']+$item['fail_cost_time'])/($item['suc_count']+$item['fail_count']), 4),
                'suc_count'     => $item['suc_count'],
                'suc_avg_time'  => $item['suc_count'] == 0 ? $item['suc_count'] : round($item['suc_cost_time']/$item['suc_count'], 4),
                'fail_count'    => $item['fail_count'],
                'fail_avg_time' => $item['fail_count'] == 0 ? 0 : round($item['fail_cost_time']/$item['fail_count'], 4),
                'precent'       => $item['suc_count']+$item['fail_count'] == 0 ? 0 : round(($item['suc_count']*100/($item['suc_count']+$item['fail_count'])), 4),
        );
    }
    $time_point =  strtotime($date);
    for($i=0;$i<288;$i++)
    {
    $data[$time_point] = isset($data[$time_point]) ? $data[$time_point] :
    array(
            'time' => date('Y-m-d H:i:s', $time_point),
            'total_count'   => 0,
            'total_avg_time'=> 0,
            'suc_count'     => 0,
            'suc_avg_time'  => 0,
            'fail_count'    => 0,
            'fail_avg_time' => 0,
            'precent'       => 100,
            );
            $time_point +=300;
    }
    ksort($data);
    return $data;
}
