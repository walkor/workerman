<?php
require_once  __DIR__.'/_init.php';
require_once ROOT_DIR . '/Lib/Gateway.php';
require_once ROOT_DIR . '/Protocols/JsonProtocol.php';

$tip = '';
// 获取web提交来的命令
$cmd = isset($_POST['cmd']) ? htmlspecialchars($_POST['cmd']) : '';
if(!empty($cmd))
{
    // 向各个终端发送命令
    Gateway::sendToAll(JsonProtocol::encode(array('from_uid'=>'SYSTEM', 'to_uid'=>'all', 'message'=>'get cmd:'.$cmd)));
    $tip = '已经向各个终端发送了命令: ' . $cmd;
}
?>
<html><head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>消息推送器</title>
  <script type="text/javascript">
  </script>
  <link href="/css/bootstrap.min.css" rel="stylesheet">
  <link href="/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container">
	    <div class="row clearfix">
	        <div class="col-md-1 column">
	        </div>
	        <div class="col-md-6 column">
	        <br>
	        <?php if($tip){?>
				<div class="alert alert-dismissable alert-success">
				 <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
				 <strong><?php echo $tip;?></strong> 
				</div>
			<?php }?>
	        <h3>要发送的控制消息</h3>
	           <form action="" method="POST" >
                    <textarea class="textarea thumbnail" id="textarea" name="cmd"><?php echo $cmd;?></textarea>
                    <div class="say-btn"><input type="submit" class="btn btn-default" value="发送" /></div>
               </form>
               <p class="cp">Powered by <a href="http://www.workerman.net/" target="_blank">workerman</a></p>
	        </div>
	        <div class="col-md-3 column">
	        </div>
	    </div>
    </div>
</body>
</html>
