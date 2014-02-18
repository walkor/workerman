<div class="container">
	<div class="row clearfix">
		<div class="col-md-12 column">
			<ul class="nav nav-tabs">
				<li>
					<a href="/">概述</a>
				</li>
				<li>
					<a href="/?fn=statistic">监控</a>
				</li>
				<li>
					<a href="/?fn=logger">日志</a>
				</li>
				<li class="disabled">
					<a href="#">告警</a>
				</li>
				<li class="dropdown pull-right">
					 <a href="#" data-toggle="dropdown" class="dropdown-toggle">探测节点<strong class="caret"></strong></a>
					<ul class="dropdown-menu">
						<li>
							<a href="/?fn=admin">节点管理</a>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
	<div class="row clearfix">
		<div class="col-md-12 column">
		<?php if($suc_msg){?>
				<div class="alert alert-dismissable alert-success">
				 <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
				 <strong><?php echo $suc_msg;?></strong> 
				</div>
		<?php }elseif($err_msg){?>
			<div class="alert alert-dismissable alert-danger">
				 <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
				<strong><?php echo $err_msg;?></strong> 
			</div>
		<?php }elseif($notie_msg){?>
			<div class="alert alert-dismissable alert-info">
				 <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
				<strong><?php echo $notie_msg;?></strong>
			</div>
		<?php }?>
		
			<form action="/?fn=admin&act=<?php echo $action;?>" method="post">
			<textarea rows="22" cols="30" name="ip_list"><?php echo $ip_list_str;?></textarea>
			<button type="submit" class="btn btn-default">保存</button>
			</form>
		</div>
	</div>
</div>
