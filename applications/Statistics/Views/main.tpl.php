<div class="container">
	<div class="row clearfix">
		<div class="col-md-12 column">
			<ul class="nav nav-tabs">
				<li class="active">
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
			<?php if($err_msg){?>
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
			<div class="row clearfix">
				<div class="col-md-12 column text-center">
					<?php echo $date_btn_str;?>
				</div>
			</div>
			<div class="row clearfix">
				<div class="col-md-6 column height-400" id="suc-pie">
				</div>
				<div class="col-md-6 column height-400" id="code-pie">
				</div>
			</div>
			<div class="row clearfix">
				<div class="col-md-12 column height-400" id="req-container" >
				</div>
			</div>
			<div class="row clearfix">
				<div class="col-md-12 column height-400" id="time-container" >
				</div>
			</div>
			<script>
Highcharts.setOptions({
	global: {
		useUTC: false
	}
});
	$('#suc-pie').highcharts({
		chart: {
			plotBackgroundColor: null,
			plotBorderWidth: null,
			plotShadow: false
		},
		title: {
			text: '<?php echo $date;?> 可用性'
		},
		tooltip: {
			pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
		},
		plotOptions: {
			pie: {
				allowPointSelect: true,
				cursor: 'pointer',
				dataLabels: {
					enabled: true,
					color: '#000000',
					connectorColor: '#000000',
					format: '<b>{point.name}</b>: {point.percentage:.1f} %'
				}
			}
		},
		credits: {
			enabled: false,
		},
		series: [{
			type: 'pie',
			name: '可用性',
			data: [
				{
					name: '可用',
					y: <?php echo $global_rate;?>,
					sliced: true,
					selected: true,
					color: '#2f7ed8'
				},
				{
					name: '不可用',
					y: <?php echo (100-$global_rate);?>,
					sliced: true,
					selected: true,
					color: '#910000'
				}
			]
		}]
	});
	$('#code-pie').highcharts({
		chart: {
			plotBackgroundColor: null,
			plotBorderWidth: null,
			plotShadow: false
		},
		title: {
			text: '<?php echo $date;?> 返回码分布'
		},
		tooltip: {
			pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
		},
		plotOptions: {
			pie: {
				allowPointSelect: true,
				cursor: 'pointer',
				dataLabels: {
					enabled: true,
					color: '#000000',
					connectorColor: '#000000',
					format: '<b>{point.name}</b>: {point.percentage:.1f} %'
				}
			}
		},
		credits: {
			enabled: false,
		},
		series: [{
			type: 'pie',
			name: '返回码分布',
			data: [
				<?php echo $code_pie_data;?>
			]
		}]
	});
	$('#req-container').highcharts({
		chart: {
			type: 'spline'
		},
		title: {
			text: '<?php echo "$date $interface_name";?>  请求量曲线'
		},
		subtitle: {
			text: ''
		},
		xAxis: {
			type: 'datetime',
			dateTimeLabelFormats: { 
				hour: '%H:%M'
			}
		},
		yAxis: {
			title: {
				text: '请求量(次/5分钟)'
			},
			min: 0
		},
		tooltip: {
			formatter: function() {
				return '<p style="color:'+this.series.color+';font-weight:bold;">'
				 + this.series.name + 
				 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
				 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">数量：'+ this.y + '</p>';
			}
		},
		credits: {
			enabled: false,
		},
		series: [		{
			name: '成功曲线',
			data: [
				<?php echo $success_series_data;?>
			],
			lineWidth: 2,
			marker:{
				radius: 1
			},
			
			pointInterval: 300*1000
		},
		{
			name: '失败曲线',
			data: [
				<?php echo $fail_series_data;?>
			],
			lineWidth: 2,
			marker:{
				radius: 1
			},
			pointInterval: 300*1000,
			color : '#9C0D0D'
		}]
	});
	$('#time-container').highcharts({
		chart: {
			type: 'spline'
		},
		title: {
			text: '<?php echo "$date $interface_name";?>  请求耗时曲线'
		},
		subtitle: {
			text: ''
		},
		xAxis: {
			type: 'datetime',
			dateTimeLabelFormats: { 
				hour: '%H:%M'
			}
		},
		yAxis: {
			title: {
				text: '平均耗时(单位：秒)'
			},
			min: 0
		},
		tooltip: {
			formatter: function() {
				return '<p style="color:'+this.series.color+';font-weight:bold;">'
				 + this.series.name + 
				 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">时间：' + Highcharts.dateFormat('%m月%d日 %H:%M', this.x) + 
				 '</p><br /><p style="color:'+this.series.color+';font-weight:bold;">平均耗时：'+ this.y + '</p>';
			}
		},
		credits: {
			enabled: false,
		},
		series: [		{
			name: '成功曲线',
			data: [
				<?php echo $success_time_series_data;?>
			],
			lineWidth: 2,
			marker:{
				radius: 1
			},
			pointInterval: 300*1000
		},
		{
			name: '失败曲线',
			data: [
				   <?php echo $fail_time_series_data;?>
			],
			lineWidth: 2,
			marker:{
				radius: 1
			},
			pointInterval: 300*1000,
			color : '#9C0D0D'
		}			]
	});
</script>
			<table class="table table-hover table-condensed table-bordered">
				<thead>
					<tr>
						<th>时间</th><th>调用总数</th><th>平均耗时</th><th>成功调用总数</th><th>成功平均耗时</th><th>失败调用总数</th><th>失败平均耗时</th><th>成功率</th>
					</tr>
				</thead>
				<tbody>
				<?php echo $table_data;?>
				</tbody>
			</table>
		</div>
	</div>
</div>
