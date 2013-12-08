<?php 
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'Protocols/SimpleHttp.php';

/**
 * 
 * 统计中心对外服务进程 查询日志 查询接口调用量 延迟 成功率等
 * 采用http协议对外服务 使用http:://Server_ip:20202 地址查询统计结果
 * 
* @author walkor <worker-man@qq.com>
 */

class StatisticService extends WORKERMAN\Core\SocketWorker
{
    
    /**
     * 判断包是否都到达
     * @see Worker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return \WORKERMAN\Protocols\SimpleHttp::input($recv_str);
    }
    
    /**
     * 处理业务逻辑 查询log 查询统计信息
     * @see Worker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        \WORKERMAN\Protocols\SimpleHttp::decode($recv_str);
        $module = isset($_GET['module']) ? trim($_GET['module']) : '';
        $interface = isset($_GET['interface']) ? trim($_GET['interface']) : '';
        $start_time = isset($_GET['start_time']) ? trim($_GET['start_time']) : '';
        $end_time = isset($_GET['end_time']) ? trim($_GET['end_time']) : '';
        
        if(0 === strpos($_SERVER['REQUEST_URI'], '/graph'))
        {
            if(!extension_loaded('gd'))
            {
                return $this->sendToClient("not suport gd\n");
            }
            $type_map = array('request','time');
            $type = isset($_GET['type']) && in_array($_GET['type'], $type_map) ?  $_GET['type'] : 'request';
            $this->displayGraph($module, $interface, $type, $start_time);
        }
        // 日志
        elseif(0 === strpos($_SERVER['REQUEST_URI'], '/log'))
        {
            $right_str = '';
            $code = isset($_GET['code']) ? $_GET['code'] : '';
            $msg = isset($_GET['msg']) ? $_GET['msg'] : '';
            $pointer = isset($_GET['pointer']) ? $_GET['pointer'] : '';
            $count = isset($_GET['count']) ? $_GET['count'] : 100;
            $log_data = $this->getStasticLog($module, $interface , $start_time , $end_time, $code, $msg , $pointer, $count);
            
            if($log_data['pointer'] == 0)
            {
                return $this->display($log_data['data']);
            }
            else
            {
                $_GET['pointer'] = $log_data['pointer'];
                unset($_GET['end_time']);
                $next_page_url = http_build_query($_GET);
                $log_data['data'] .= "</br><center><a href='/log/?$next_page_url'>下一页</a></center>";
                return $this->display(nl2br($log_data['data']));
            }
            
        }
        // 统计
        else
        {
            // 首页
            if(empty($module))
            {
                return $this->home();
            }
            else
            {
                if($interface)
                {
                    return $this->displayInterface($module, $interface, $start_time, $end_time);
                }
                else
                {
                    return $this->display();
                }
            }
            
        }
        
        return $this->display();
    }
    
    /**
     * 统计主页
     * @return void
     */
    protected function home()
    {
        $data = '';
        $address = '127.0.0.1:10101';
        $sock = stream_socket_client($address);
        if(!$sock)
        {
            return $this->display();
        }
        fwrite($sock, 'status');
        $read_fds = array($sock);
        $write_fds = $except_fds = array();
        $time_start = time();
        while(1)
        {
            $ret = @stream_select($read_fds, $write_fds, $except_fds, 1);
            if(!$ret)
            {
                if(time() - $time_start >= 1)
                {
                    break;
                }
                continue;
            }
            foreach($read_fds as $fd)
            {
                if($ret_str = fread($fd, 8192))
                {
                    $data .= $ret_str;
                }
                else
                {
                    break;
                }
            }
            if(time() - $time_start >= 1)
            {
                break;
            }
        }
        
        $data = '<pre>'.$data.'</pre>';
        
        return $this->display($data);
    }
    
    /**
     * 接口统计信息
     * @param string $module
     * @param string $interface
     * @param int $start_time
     * @param int $end_time
     * @return void
     */
    protected function displayInterface($module ,$interface, $start_time, $end_time)
    {
        $data = $this->getStatistic($module, $interface, $start_time, $end_time);
        $suport_gd = extension_loaded('gd');
        $right_str = '
        <center>模块:'.$module.' &nbsp; 接口:'.$interface.'</center>
        </br>
        '.($suport_gd ? '
        <img src="/graph/?module='.$module.'&interface='.$interface.'&type=request&start_time='.$start_time.'"/>' : '未安装gd库，图形无法展示') .'
        <center>请求量</center>
        </br>
        '.($suport_gd ? '
        <img src="/graph/?module='.$module.'&interface='.$interface.'&type=time&start_time='.$start_time.'"/>' : '未安装gd库，图形无法展示') .'
        <center>延迟单位:秒</center>
        </br>';
        
        $right_str .= '<center>';
        
        $date_array = $this->getAvailableStDate($module, $interface);
        $current_key = strtotime(date('Y-m-d', $start_time ? $start_time : time()));
        if(!isset($date_array[$current_key]))
        {
            $date_array[$current_key] = date('Y-m-d', $current_key);
        }
        unset($_GET['start_time']);
        $st_url = http_build_query($_GET);
        $date_array_chunk = array_chunk($date_array, 7, true);
        if($date_array_chunk)
        {
            foreach($date_array_chunk as $date_array)
            {
                foreach($date_array as $time_stamp => $date)
                {
                    $right_str .= ($current_key == $time_stamp) ? ('<a href=/st/?'.$st_url.'&start_time='.$time_stamp.'><b>'.$date.'</b></a>&nbsp;&nbsp;') : ('<a href=/st/?'.$st_url.'&start_time='.$time_stamp.'>'.$date.'</a>&nbsp;&nbsp;');
                }
                $right_str .= "<br>";
            }
        }
        
        $right_str .='<br><br></center>';
        
        $right_str .='<table>
        <tr align="center">
        <th >时间</th><th>调用总数</th><th>平均耗时</th><th>成功调用总数</th><th>成功平均耗时</th><th>失败调用总数</th><th>失败平均耗时</th><th>成功率</th>
        </tr>
        ';
        
        if($data)
        {
            foreach($data as $item)
            {
                $right_str .= "<tr align='center'><td>{$item['time']}</td><td>{$item['total_count']}</td><td>{$item['total_avg_time']}</td><td>{$item['suc_count']}</td><td>{$item['suc_avg_time']}</td><td>".($item['fail_count']>0?("<a href='/log/?module=$module&interface=$interface&start_time=".strtotime($item['time'])."&end_time=".(strtotime($item['time'])+300)."'>{$item['fail_count']}</a>"):$item['fail_count'])."</td><td>{$item['fail_avg_time']}</td><td>".($item['precent']<=98?'<font style="color:red">'.$item['precent'].'%</font>' : $item['precent'].'%')."</td></tr>\n"; 
            }
        }
        
        $right_str .= '</table>'; 
        
        return $this->display($right_str);
        
    }
    
    /**
     * 展示曲线图
     * @param string $module
     * @param string $interface
     * @param string $type
     * @param integer $start_time
     * @return void
     */
    protected function displayGraph($module ,$interface, $type = 'request', $start_time = '')
    {
        $data = $this->getStatistic($module, $interface, $start_time);
        \WORKERMAN\Protocols\SimpleHttp::header("Content-type: image/jpeg");
        $gg=new buildGraph();
        $d2 = $d3 = array();
        $time_point = $start_time ? strtotime(date('Y-m-d',$start_time)) : strtotime(date('Y-m-d'));
        switch($type)
        {
            case 'time':
                for($i=0;$i<288;$i++)
                {
                    $time_point +=300;
                    $d2[$time_point] = isset($data[$time_point]['total_avg_time']) ? $data[$time_point]['total_avg_time'] : 0;
                    $d3[$time_point] = isset($data[$time_point]['fail_avg_time']) ? $data[$time_point]['fail_avg_time'] : 0;
                }
                break;
            default:
                for($i=0;$i<288;$i++)
                {
                    $time_point +=300;
                    $d2[$time_point] = isset($data[$time_point]['total_count']) ? $data[$time_point]['total_count'] : 0;
                    $d3[$time_point] = isset($data[$time_point]['fail_count']) ? $data[$time_point]['fail_count'] : 0;
                }
        }
        
        $d2 = array_values($d2);
        $d3 = array_values($d3);
        
        $gg->addData($d2);
        $gg->addData($d3);
        $gg->setColors("088A08,b40404");
        ob_start();
        // 生成曲线图
        $gg->build("line",0);      // 参数0表示显示所有曲线，1为显示第一条，依次类推 
        return $this->sendToClient(\WORKERMAN\Protocols\SimpleHttp::encode(ob_get_clean()));
    }
    
    /**
     * 获取模块
     * @return array
     */
    public function getModules()
    {
        $st_dir = WORKERMAN_LOG_DIR . 'statistic/st/';
        return glob($st_dir."/*");
    }
    
    /**
     * 渲染页面
     * @param string $data
     * @return bool
     */
    protected function display($data=null)
    {
        $left_detail = '';
        $html_left = '<ul>';
        $current_module = empty($_GET['module']) ? '' : $_GET['module'];
        if($current_module)
        {
            $st_dir = WORKERMAN_LOG_DIR . 'statistic/st/'.$current_module.'/';
            $all_interface = array();
            foreach(glob($st_dir."*") as $file)
            {
                if(is_dir($file))
                {
                    continue;
                }
                $tmp = explode("|", basename($file));
                $interface = trim($tmp[0]);
                if(isset($all_interface[$interface]))
                {
                    continue;
                }
                $all_interface[$interface] = $interface;
                $left_detail .= '<li>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/st/?module='.$current_module.'&interface='.$interface.'">'.$interface.'</a></li>';
            }
            
        }
        
        $modules_name_array = $this->getModules();
        if($modules_name_array)
        {
            foreach($modules_name_array as $module_file)
            {
                $tmp = explode("/", $module_file);
                $module = end($tmp);
                $html_left .= '<li><a href="/st/?module='.$module.'">'.$module.'</a></li>';
                if($module == $current_module)
                {
                    $html_left .= $left_detail;
                }
            }
        }
        $display_str = <<<EOC
<html>
<head>
<title>WORKERMAN监控</title>
</head>
<table>
<tr valign='top'>
<td style="border-right:3px solid #dddddd">$html_left</td>
<td>$data</td>
</tr>
</table>
</html>    
EOC;
        return $this->sendToClient(\WORKERMAN\Protocols\SimpleHttp::encode($display_str));
    }
    
    /**
     * 日志二分查找法
     * @param int $start_point
     * @param int $end_point
     * @param int $time
     * @param fd $fd
     * @return int
     */
    protected function binarySearch($start_point, $end_point, $time, $fd)
    {
        // 计算中点
        $mid_point = (int)(($end_point+$start_point)/2);
        
        // 定位文件指针在中点
        fseek($fd, $mid_point);
        
        // 读第一行
        $line = fgets($fd);
        if(feof($fd) || false === $line)
        {
            return ftell($fd);
        }
        
        // 第一行可能数据不全，再读一行
        $line = fgets($fd);
        if(feof($fd) || false === $line || trim($line) == '')
        {
            return ftell($fd);
        }
        
        // 判断是否越界
        $current_point = ftell($fd);
        if($current_point>=$end_point)
        {
            return $end_point;
        }
        
        // 获得时间
        $tmp = explode("\t", $line);
        $tmp_time = strtotime($tmp[0]);
        
        // 判断时间，返回指针位置
        if($tmp_time > $time)
        {
            return $this->binarySearch($start_point, $current_point, $time, $fd);
        } 
        elseif($tmp_time < $time)
        {
            return $this->binarySearch($current_point, $end_point, $time, $fd);
        }
        else
        {
            return $current_point;
        }
    }
    
    /**
     * 获取指定日志
     * @return array
     */
    protected function getStasticLog($module, $interface , $start_time = '', $end_time = '', $code = '', $msg = '', $pointer='', $count=100)
    {
        // log文件
        $log_file = WORKERMAN_LOG_DIR . 'statistic/log/'. ($start_time === '' ? date('Y-m-d') : date('Y-m-d', $start_time));
        if(!is_readable($log_file))
        {
            return array('pointer'=>0, 'data'=>$log_file . 'not exists or not readable');
        }
        // 读文件
        $h = fopen($log_file, 'r');
        
        // 如果有时间，则进行二分查找，加速查询
        if($start_time && $pointer === '' && ($file_size = filesize($log_file) > 5000))
        {
            $pointer = $this->binarySearch(0, $file_size, $start_time-1, $h);
            $pointer = $pointer < 1000 ? 0 : $pointer - 1000; 
        }
        
        // 正则表达式
        $pattern = "/^([\d: \-]+)\t";
        
        if($module)
        {
            $pattern .= $module."::";
        }
        else
        {
            $pattern .= ".*::";
        }
        
        if($interface)
        {
            $pattern .= $interface."\t";
        }
        else
        {
            $pattern .= ".*\t";
        }
        
        if($code !== '')
        {
            $pattern .= "code:$code\t";
        }
        else 
        {
            $pattern .= "code:\d+\t";
        }
        
        if($msg)
        {
            $pattern .= "msg:$msg";
        }
       
        $pattern .= '/';
        
        // 指定偏移位置
        if($pointer >= 0)
        {
            fseek($h, (int)$pointer);
        }
        
        // 查找符合条件的数据
        $now_count = 0;
        $log_buffer = '';
        
        while(1)
        {
            if(feof($h))
            {
                break;
            }
            // 读1行
            $line = fgets($h);
            if(preg_match($pattern, $line, $match))
            {
                // 判断时间是否符合要求
                $time = strtotime($match[1]);
                if($start_time)
                {
                    if($time<$start_time)
                    {
                        continue;
                    }
                }
                if($end_time)
                {
                    if($time>$end_time)
                    {
                        break;
                    }
                }                                    
                // 收集符合条件的log
                $log_buffer .= $line;
                if(++$now_count >= $count)
                {
                    break;
                }
            }
        }
        // 记录偏移位置
        $pointer = ftell($h);
        return array('pointer'=>$pointer, 'data'=>$log_buffer);
    }
    
    
    /**
     * 获取统计数据
     * @param string $module
     * @param string $interface
     * @param integer $start_time
     * @param integer $end_time
     * @return array
     */
    protected function getStatistic($module, $interface, $start_time='',$end_time='')
    {
        
        // 正则表达式
        $need_preg_match =  $start_time || $end_time;
        $pattern = '';
        if($need_preg_match)
        {
            $pattern .= "/^[\d\.]+\t(\d+)\t/";
        }
        
        // log文件
        $log_file = WORKERMAN_LOG_DIR . "statistic/st/{$module}/{$interface}|". ($start_time === '' ? date('Y-m-d') : date('Y-m-d', $start_time));
        if(!is_readable($log_file))
        {
            return false;
        }
        
        // 读文件
        $h = fopen($log_file, 'r');
        
        // time:[suc_count:xx,suc_cost_time:xx,fail_count:xx,fail_cost_time:xx]
        $st_data = array();
        // 汇总计算
        while(1)
        {
            if(feof($h))
            {
                break;
            }
            // 读1行
            $line = fgets($h);
            if(empty($line))
            {
                continue;
            }
            if($need_preg_match && preg_match($pattern, $line, $match))
            {
                // 判断时间是否符合要求
                $time = $match[1];
                if($start_time)
                {
                    if($time<=$start_time)
                    {
                        continue;
                    }
                }
                if($end_time)
                {
                    if($time>=$end_time)
                    {
                        continue;
                    }
                }
                
            }
            // line = IP time suc_count suc_cost_time fail_count fail_cost_time code_json
            $line_data = explode("\t", $line);
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
        $max_time_line = $time_line;
        $time_point = $start_time ? strtotime(date('Y-m-d', $start_time)) : strtotime(date('Y-m-d'))+300;
        for($i=0;$i<288,$time_point<=$max_time_line;$i++)
        {
            $data[$time_point] = isset($data[$time_point]) ? $data[$time_point] : 
            array(
                    'time'          => date('Y-m-d H:i:s', $time_point),
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
    
    /**
     * 获取能展示统计数据的日期
     * @param string $module
     * @param string $interface
     * @return array
     */
    protected function getAvailableStDate($module, $interface)
    {
        $date_array = array();
        $st_dir = WORKERMAN_LOG_DIR . 'statistic/st/'.$module.'/';
        foreach(glob($st_dir."$interface|*") as $stFile)
        {
            $base_name = basename($stFile);
            $tmp = explode('|', $base_name);
            $date_array[strtotime($tmp[1])] = $tmp[1];
        }
        ksort($date_array);
        return $date_array;
    }
    
    /**
     * 缓冲页面输出(non-PHPdoc)
     * @see SocketWorker::onStart()
     */
    public function onStart()
    {
        ob_start();
    }
    
    /**
     * 获取缓冲(non-PHPdoc)
     * @see SocketWorker::onAlarm()
     */
    public function onAlarm()
    {
        $ob_content = ob_get_contents();
        if($ob_content)
        {
            \WORKERMAN\Core\Lib\Log::add('StatisticService:ob_content:'.$ob_content);
            ob_clean();
        }
    }
    
}


/**
 * 
 * 画图的一个类
 *
 */
class buildGraph {
    protected $graphwidth=800;
    protected $graphheight=300;
    protected $width_num=0;          // 宽分多少等分
    protected $height_num=10;          // 高分多少等分，默认为10
    protected $height_var=0;          // 高度增量（用户数据平均数）
    protected $width_var=0;          // 宽度增量（用户数据平均数）
    protected $height_max=0;          // 最大数据值
    protected $array_data=array();      // 用户待分析的数据的二维数组
    protected $array_error=array();      // 收集错误信息

    protected $colorBg=array(255,255,255);  // 图形背景-白色
    protected $colorGrey=array(192,192,192);  // 灰色画框
    protected $colorBlue=array(0,0,255);     // 蓝色
    protected $colorRed=array(255,0,0);    // 红色（点）
    protected $colorDarkBlue=array(0, 0, 255);  // 深色
    protected $colorBlack=array(0,0,0);
    protected $colorLightBlue=array(200,200,255);     // 浅色

    protected $array_color;          // 曲线着色（存储十六进制数）
    protected $image;              // 我们的图像


    /**
     * 方法：接受用户数据
     */
    function addData($array_user_data){
        if(!is_array($array_user_data) or empty($array_user_data)){
            $this->array_error['addData']="没有可供分析的数据";
            return false;
        }
        $i=count($this->array_data);
        $this->array_data[$i]=$array_user_data;
    }

    /**
     * 方法：定义画布宽和长
     */
    function setImg($img_width,$img_height){
        $this->graphwidth=$img_width;
        $this->graphheight=$img_height;
    }

    /**
     * 设定Y轴的增量等分，默认为10份
     */
    function setHeightNum($var_y){
        $this->height_num=$var_y;
    }

    /**
     * 定义各图形各部分色彩
     */
    function getRgb($color){        // 得到十进制色彩
        $R=($color>>16) &0xff;
        $G=($color>>8) &0xff;
        $B=($color) & 0xff;
        return(array($R,$G,$B));
    }
    
    /**
     * 定义背景色
     * @param unknown_type $c1
     * @param unknown_type $c2
     * @param unknown_type $c3
     */
    function setColorBg($c1,$c2,$c3){
        $this->colorBg=array($c1,$c2,$c3);
    }
    
    /**
     * 定义画框色
     */
    function setColorGrey($c1,$c2,$c3){
        $this->colorGrey=array($c1,$c2,$c3);
    }
    
    /**
     * 定义蓝色
     * @param unknown_type $c1
     * @param unknown_type $c2
     * @param unknown_type $c3
     */
    function setColorBlue($c1,$c2,$c3){
        $this->colorBlue=array($c1,$c2,$c3);
    }
    
    /**
     * 定义色Red
     */
    function setColorRed($c1,$c2,$c3){
        $this->colorRed=array($c1,$c2,$c3);
    }
    
    /**
     * 定义深色
     * @param unknown_type $c1
     * @param unknown_type $c2
     * @param unknown_type $c3
     */
    function setColorDarkBlue($c1,$c2,$c3){
        $this->colorDarkBlue=array($c1,$c2,$c3);
    }
    
    /**
     * 定义浅色
     * @param unknown_type $c1
     * @param unknown_type $c2
     * @param unknown_type $c3
     */
    function setColorLightBlue($c1,$c2,$c3){
        $this->colorLightBlue=array($c1,$c2,$c3);
    }
    
    /**
     * 方法:由用户数据将画布分成若干等份宽
     * 并计算出每份多少像素
     */
    function getWidthNum(){
        $this->width_num=count($this->array_data[0]);
    }
    
    /**
     * 
     * @return mixed
     */
    function getMaxHeight(){
        // 获得用户数据的最大值
        $tmpvar=array();
        foreach($this->array_data as$tmp_value){
            $tmpvar[]=max($tmp_value);
        }
        $this->height_max=max($tmpvar);
        return max($tmpvar);
    }
    
    /**
     * 
     * @return number
     */
    function getHeightLength(){
        // 计算出每格的增量长度(用户数据，而不是图形的像素值)
        $max_var=$this->getMaxHeight();
        $max_var=ceil($max_var/$this->height_num);
            $first_num=substr($max_var,0,1);
            if(substr($max_var,1,1)){
            if(substr($max_var,1,1)>=5)
            $first_num+=1;
        }
        for($i=1;$i<strlen($max_var);$i++){
        $first_num.="0";
        }
        return (int)$first_num;
    }
    
    /**
     * 
     */
    function getVarWh(){      // 得到高和宽的增量
        $this->getWidthNum();
        // 得到高度增量和宽度增量
        $this->height_var=$this->getHeightLength();
        $this->width_var=$this->graphwidth/$this->width_num;
    }
    
    /**
     * 
     * @param unknown_type $str_colors
     */
    function setColors($str_colors){
        // 用于多条曲线的不同着色，如$str_colors="ee00ff,dd0000,cccccc"
        $this->array_color=explode(",",$str_colors);
    }
    
    /**
     * 
     * @param unknown_type $var_num
     */
    function buildLine($var_num){
        if(!empty($var_num)){            // 如果用户只选择显示一条曲线
            $array_tmp[0]=$this->array_data[$var_num-1];
            $this->array_data=$array_tmp;
        }
        
        for($j=0;$j<count($this->array_data);$j++){
            list($R,$G,$B)=$this->getRgb(hexdec($this->array_color[$j]));
            $colorBlue=imagecolorallocate($this->image,$R,$G,$B);
        
            for($i=0;$i<$this->width_num-1;$i++){
                $height_pix=$this->height_max == 0 ? 0 : round(($this->array_data[$j][$i]/$this->height_max)*$this->graphheight);
                $height_next_pix= $this->height_max*$this->graphheight == 0 ? 0 : round($this->array_data[$j][$i+1]/$this->height_max*$this->graphheight);
                imageline($this->image,$this->width_var*$i,$this->graphheight-$height_pix,$this->width_var*($i+1),$this->graphheight-$height_next_pix,$colorBlue);
            }
        }
    }
    
    /**
     * 
     * @param unknown_type $select_gra
     */
    function buildRectangle($select_gra){
        if(!empty($select_gra)){            // 用户选择显示一个矩形
            $select_gra-=1;
        }
        // 画矩形
        // 配色
        $colorDarkBlue=imagecolorallocate($this->image,$this->colorDarkBlue[0], $this->colorDarkBlue[1],$this->colorDarkBlue[2]);
        $colorLightBlue=imagecolorallocate($this->image,$this->colorLightBlue[0], $this->colorLightBlue[1],$this->colorLightBlue[2]);
    
        if(empty($select_gra))
            $select_gra=0;
        
        for($i=0; $i<$this->width_num; $i++){
            $height_pix = $this->height_max == 0 ? 0 : round(($this->array_data[$select_gra][$i]/$this->height_max)*$this->graphheight);
            imagefilledrectangle($this->image,$this->width_var*$i,$this->graphheight-$height_pix,$this->width_var*($i+1),$this->graphheight,$colorDarkBlue);
            imagefilledrectangle($this->image,($i*$this->width_var)+1,($this->graphheight-$height_pix)+1,$this->width_var*($i+1)-5,$this->graphheight-2,$colorLightBlue);
        }
    }
    
    /**
     * 
     */
    function createCloths(){
        // 创建画布
        $this->image=imagecreate($this->graphwidth+20,$this->graphheight+20);
    }
    
    /**
     * 
     */
    function  createFrame(){
        // 创建画框
        $this->getVarWh();
        // 配色
        $colorBg=imagecolorallocate($this->image,$this->colorBg[0], $this->colorBg[1],$this->colorBg[2]);
        $colorGrey=imagecolorallocate($this->image,$this->colorGrey[0], $this->colorGrey[1],$this->colorGrey[2]);
        // 创建图像周围的框
        imageline($this->image, 0, 0, 0,$this->graphheight,$colorGrey);
        imageline($this->image, 0, 0,$this->graphwidth, 0,$colorGrey);
        imageline($this->image,($this->graphwidth-1),0,($this->graphwidth-1),($this->graphheight-1),$colorGrey);
        imageline($this->image,0,($this->graphheight-1),($this->graphwidth-1),($this->graphheight-1),$colorGrey);
    }
    
    /**
     * 
     */
    function  createLine(){
        // 创建网格。
        $this->getVarWh();
        $colorBg=imagecolorallocate($this->image,$this->colorBg[0], $this->colorBg[1],$this->colorBg[2]);
        $colorGrey=imagecolorallocate($this->image,$this->colorGrey[0], $this->colorGrey[1],$this->colorGrey[2]);
        $colorRed=imagecolorallocate($this->image,$this->colorRed[0], $this->colorRed[1],$this->colorRed[2]);
        $colorBlack=imagecolorallocate($this->image,$this->colorBlack[0], $this->colorBlack[1],$this->colorBlack[2]);
        for($j=0;$j<$this->width_num;$j++){
             if($j%12 == 0){
                 // 画竖线
                 imageline($this->image,$j*$this->width_var,0,$j*$this->width_var,$this->graphheight,$colorGrey);
                 // 标出数字
                 imagestring($this->image,2,$this->width_var*$j,$this->graphheight,$j/12,$colorBlack);
           }
         }
        
        for($i=1;$i<=$this->height_num;$i++){
            // 画横线
            imageline($this->image,0,$this->graphheight-($this->height_max*$this->graphheight == 0 ? 0 : ($this->height_var/$this->height_max*$this->graphheight)*$i),$this->graphwidth,$this->graphheight - ($this->height_max*$this->graphheight == 0 ? 0 : ($this->height_var/$this->height_max*$this->graphheight)*$i),$colorGrey);
            // 标出数字
            imagestring($this->image,2,0,$this->graphheight-($this->height_max*$this->graphheight == 0 ? 0 : ($this->height_var/$this->height_max*$this->graphheight)*$i),$this->height_var*$i,$colorBlack);
        }
    }
    
    /**
     * 
     * @param unknown_type $graph
     * @param unknown_type $str_var
     */
    function build($graph,$str_var){
        // $graph是用户指定的图形种类,$str_var是生成哪个数据的图
        $this->createCloths();      // 先要有画布啊~~
        switch ($graph){
            case"line":
                $this->createFrame();      // 画个框先：）
                $this->createLine();      // 打上底格线
                $this->buildLine($str_var);      // 画曲线
                break;
            case"rectangle":
                $this->createFrame();            // 画个框先：）
                $this->buildRectangle($str_var);      // 画矩形
                $this->createLine();            // 打上底格线
            break;
        }
    
    
        // 输出图形并清除内存
        imagepng($this->image);
        imagedestroy($this->image);
    }
    
}
    