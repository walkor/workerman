<?php
namespace Statistics\Modules;
function main($module, $interface, $date, $start_time, $offset)
{
    $err_msg = $notice_msg=  '';
    $module = 'WorkerMan';
    $interface = 'Statistics';
    $today = date('Y-m-d');
    $time_now = time();
    multiRequestStAndModules($module, $interface, $date);
    $all_st_str = '';
    if(is_array(\Statistics\Lib\Cache::$statisticDataCache['statistic']))
    {
        foreach(\Statistics\Lib\Cache::$statisticDataCache['statistic'] as $ip=>$st_str)
        {
            $all_st_str .= $st_str;
        }
    }
    
    $code_map = array();
    $data = formatSt($all_st_str, $date, $code_map);
    $interface_name = '整体';
    $success_series_data = $fail_series_data = $success_time_series_data = $fail_time_series_data = array();
    $total_count = $fail_count = 0;
    foreach($data as $time_point=>$item)
    {
        if($item['total_count'])
        {
            $success_series_data[] = "[".($time_point*1000).",{$item['total_count']}]";
            $total_count += $item['total_count'];
        }
        $fail_series_data[] = "[".($time_point*1000).",{$item['fail_count']}]";
        $fail_count += $item['fail_count'];
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
    
    // 总体成功率
    $global_rate =  $total_count ? round((($total_count - $fail_count)/$total_count)*100, 4) : 100;
    // 返回码分布
    $code_pie_data = '';
    $code_pie_array = array(); 
    unset($code_map[0]);
    if(empty($code_map))
    {
        $code_map[0] = $total_count > 0 ? $total_count : 1;
    }
    if(is_array($code_map))
    {
        $total_item_count = array_sum($code_map);
        foreach($code_map as $code=>$count)
        {
            $code_pie_array[] = "[\"$code:{$count}个\", ".round($count*100/$total_item_count, 4)."]";
        }
        $code_pie_data = implode(',', $code_pie_array);
    }
    
    unset($_GET['start_time'], $_GET['end_time'], $_GET['date'], $_GET['fn']);
    $query = http_build_query($_GET);
    
    // 删除末尾0的记录
    if($today == $date)
    {
        while(!empty($data) && ($item = end($data)) && $item['total_count'] == 0 && ($key = key($data)) &&  $time_now < $key)
        {
            unset($data[$key]);
        }
    }
    
    $table_data = '';
    if($data)
    {
        $first_line = true;
        foreach($data as $item)
        {
            if($first_line)
            {
                $first_line = false;
                if($item['total_count'] == 0)
                {
                    continue;
                }
            }
            $html_class = 'class="danger"';
            if($item['total_count'] == 0)
            {
                $html_class = '';
            }
            elseif($item['precent']>=99.99)
            {
                $html_class = 'class="success"';
            }
            elseif($item['precent']>=99)
            {
                $html_class = '';
            }
            elseif($item['precent']>=98)
            {
                $html_class = 'class="warning"';
            }
            $table_data .= "\n<tr $html_class>
                       <td>{$item['time']}</td>
                       <td>{$item['total_count']}</td>
                        <td> {$item['total_avg_time']}</td>
                        <td>{$item['suc_count']}</td>
                        <td>{$item['suc_avg_time']}</td>
                        <td>".($item['fail_count']>0?("<a href='/?fn=logger&$query&start_time=".(strtotime($item['time'])-300)."&end_time=".(strtotime($item['time']))."'>{$item['fail_count']}</a>"):$item['fail_count'])."</td>
                        <td>{$item['fail_avg_time']}</td>
                        <td>{$item['precent']}%</td>
                    </tr>
            ";
        }
    }
    
    // date btn
    $date_btn_str = '';
    for($i=13;$i>=1;$i--)
    {
        $the_time = strtotime("-$i day");
        $the_date = date('Y-m-d',$the_time);
        $html_the_date = $date == $the_date ? "<b>$the_date</b>" : $the_date;
        $date_btn_str .= '<a href="/?date='."$the_date&$query".'" class="btn '.$html_class.'" type="button">'.$html_the_date.'</a>';
        if($i == 7)
        {
            $date_btn_str .= '</br>';
        }
    }
    $the_date = date('Y-m-d');
    $html_the_date = $date == $the_date ? "<b>$the_date</b>" : $the_date;
    $date_btn_str .=  '<a href="/?date='."$the_date&$query".'" class="btn" type="button">'.$html_the_date.'</a>';
    
    if( \Statistics\Lib\Cache::$lastFailedIpArray)
    {
        $err_msg = '<strong>无法从以下数据源获取数据:</strong>';
        foreach (\Statistics\Lib\Cache::$lastFailedIpArray as $ip)
        {
            $err_msg .= $ip.'::'.\Statistics\Config\Config::$ProviderPort . '&nbsp;';
        }
    }
    
    if(empty(\Statistics\Lib\Cache::$ServerIpList))
    {
        $notice_msg = <<<EOT
<h4>数据源为空</h4>
您可以 <a href="/?fn=admin&act=detect_server" class="btn" type="button"><strong>探测数据源</strong></a>或者<a href="/?fn=admin" class="btn" type="button"><strong>添加数据源</strong></a>
EOT;
    }

    include ST_ROOT . '/Views/header.tpl.php';
    include ST_ROOT . '/Views/main.tpl.php';
    include ST_ROOT . '/Views/footer.tpl.php';
}

function multiRequestStAndModules($module, $interface, $date)
{
    \Statistics\Lib\Cache::$statisticDataCache['statistic'] = '';
    $buffer = json_encode(array('cmd'=>'get_statistic','module'=>$module, 'interface'=>$interface, 'date'=>$date))."\n";
    $ip_list = (!empty($_GET['ip']) && is_array($_GET['ip'])) ? $_GET['ip'] : \Statistics\Lib\Cache::$ServerIpList;
    $reqest_buffer_array = array();
    $port = \Statistics\Config\Config::$ProviderPort;;
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
            if(!isset(\Statistics\Lib\Cache::$modulesDataCache[$mod]))
            {
                \Statistics\Lib\Cache::$modulesDataCache[$mod] = array();
            }
            foreach($interfaces as $if)
            {
                \Statistics\Lib\Cache::$modulesDataCache[$mod][$if] = $if;
            }
        }
        \Statistics\Lib\Cache::$statisticDataCache['statistic'][$ip] = $statistic_data;
    }
}

function formatSt($str, $date, &$code_map)
{
    // time:[suc_count:xx,suc_cost_time:xx,fail_count:xx,fail_cost_time:xx]
    $st_data = $code_map = array();
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
        $time_line = ceil($time_line/300)*300;
        $suc_count = $line_data[2];
        $suc_cost_time = $line_data[3];
        $fail_count = $line_data[4];
        $fail_cost_time = $line_data[5];
        $tmp_code_map = json_decode($line_data[6], true);
        if(!isset($st_data[$time_line]))
        {
            $st_data[$time_line] = array('suc_count'=>0, 'suc_cost_time'=>0, 'fail_count'=>0, 'fail_cost_time'=>0);
        }
        $st_data[$time_line]['suc_count'] += $suc_count;
        $st_data[$time_line]['suc_cost_time'] += $suc_cost_time;
        $st_data[$time_line]['fail_count'] += $fail_count;
        $st_data[$time_line]['fail_cost_time'] += $fail_cost_time;
        
        if(is_array($tmp_code_map))
        {
            foreach($tmp_code_map as $code=>$count)
            {
                if(!isset($code_map[$code]))
                {
                    $code_map[$code] = 0;
                }
                $code_map[$code] += $count;
            }
        }
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
                'total_avg_time'=> $item['suc_count']+$item['fail_count'] == 0 ? 0 : round(($item['suc_cost_time']+$item['fail_cost_time'])/($item['suc_count']+$item['fail_count']), 6),
                'suc_count'     => $item['suc_count'],
                'suc_avg_time'  => $item['suc_count'] == 0 ? $item['suc_count'] : round($item['suc_cost_time']/$item['suc_count'], 6),
                'fail_count'    => $item['fail_count'],
                'fail_avg_time' => $item['fail_count'] == 0 ? 0 : round($item['fail_cost_time']/$item['fail_count'], 6),
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
