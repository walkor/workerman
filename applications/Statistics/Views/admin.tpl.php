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
			<form action="/?fn=admin&act=save_server_list" method="post">
			<textarea rows="100" cols="30">
			<?php echo $ip_list_str;?>
			</textarea>
			</form>
		</div>
	</div>
</div>
