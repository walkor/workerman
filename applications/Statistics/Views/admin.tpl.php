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
					 <a href="#" data-toggle="dropdown" class="dropdown-toggle">其它<strong class="caret"></strong></a>
					<ul class="dropdown-menu">
						<li>
							<a href="/?fn=admin&act=detect_server">探测数据源</a>
						</li>
						<li>
							<a href="/?fn=admin">数据源管理</a>
						</li>
						<li>
							<a href="/?fn=setting">设置</a>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
	<div class="row clearfix">
		<div class="col-md-12 column">
			<ul class="breadcrumb">
				<li>
					<a href="/?fn=admin<?php echo $act == 'detect_server' ? '&act=detect_server' : '';?>"><?php echo $act == 'detect_server' ? '数据源探测' : '数据源管理';?></a> <span class="divider">/</span>
				</li>
				<li class="active">
					<?php if($act == 'home')echo '数据源列表';elseif($act == 'detect_server')echo '探测结果';elseif($act == 'add_to_server_list')echo '添加结果';elseif($act == 'save_server_list')echo '保存结果';?>
				</li>
			</ul>
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
			<?php }elseif($notice_msg){?>
			<div class="alert alert-dismissable alert-info">
				 <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
				<strong><?php echo $notice_msg;?></strong>
			</div>
			<?php }?>
		</div>
	</div>
	<div class="row clearfix">
		<div class="col-md-3 column">
		</div>
		<div class="col-md-6 column">
		<?php if($act!='add_to_server_list'){?>
			<form action="/?fn=admin&act=<?php echo $action;?>" method="post">
			<div class="form-group">
				<textarea rows="22" cols="30" name="ip_list"><?php echo $ip_list_str;?></textarea>
			</div>
			<div class="form-group">
				<div class="col-sm-offset-1 col-sm-11">
					<button type="submit" class="btn btn-default"><?php echo $act == 'detect_server' ? '添加到数据源列表' : '保存'?></button>
				</div>
			</div>
			</form>
			<?php }else{?>
			<a type="button" class="btn btn-default" href="/">返回主页</a>&nbsp;<a type="button" class="btn btn-default" href="/?fn=admin">继续添加</a>
			<?php }?>
		</div>
		<div class="col-md-3 column">
		</div>
	</div>
</div>
