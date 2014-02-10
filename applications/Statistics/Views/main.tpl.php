<div class="container">
	<div class="row clearfix">
		<div class="col-md-12 column">
			<ul class="nav nav-tabs">
				<li>
					<a href="#">概述</a>
				</li>
				<li class="active">
					<a href="#">监控</a>
				</li>
				<li class="disabled">
					<a href="#">告警</a>
				</li>
				<li class="dropdown pull-right">
					 <a href="#" data-toggle="dropdown" class="dropdown-toggle">其它<strong class="caret"></strong></a>
					<ul class="dropdown-menu">
						<li>
							<a href="#">探测节点</a>
						</li>
						<li>
							<a href="#">节点管理</a>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
	<div class="row clearfix">
		<div class="col-md-3 column">
			<div class="list-group">
				 <a href="#" class="list-group-item active">HelloWorld</a>
				 <a href="#" class="list-group-item">sayHello</a>
				 <a href="#" class="list-group-item">sayHi</a>
				 <a href="#" class="list-group-item active">User</a>
				 <a href="#" class="list-group-item active">Blog</a>
			</div>
		</div>
		<div class="col-md-9 column">
			<div class="alert alert-dismissable alert-success">
				 <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
				<h4>
					Alert!
				</h4> <strong>Warning!</strong> Best check yo self, you're not looking too good. <a href="#" class="alert-link">alert link</a>
			</div>
			<div class="alert alert-dismissable alert-danger">
				 <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
				<h4>
					Alert!
				</h4> <strong>Warning!</strong> Best check yo self, you're not looking too good. <a href="#" class="alert-link">alert link</a>
			</div>
			<h3 class="text-primary text-center">
				{$module}::{$interface}请求量曲线
			</h3>
			<div id="req-container" style="min-width:1100px;height:400px"></div>
			<div id="time-container" style="min-width:1100px;height:400px"></div>
			<div class="text-center">
			<button class="btn btn-primary" type="button">分别统计</button>
			<button class="btn btn-primary" type="button">汇总统计</button>
			</div>
			<table class="table table-hover table-condensed table-bordered">
				<thead>
					<tr>
						<th>时间</th><th>调用总数</th><th>平均耗时</th><th>成功调用总数</th><th>成功平均耗时</th><th>失败调用总数</th><th>失败平均耗时</th><th>成功率</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>
							2014-02-09 00:05:00
						</td>
						<td>
							13500
						</td>
						<td>
							0.0268
						</td>
						<td>
							13500
						</td>
						<td>
							0.0268
						</td>
						<td>
							0
						</td>
						<td>
							0
						</td>
						<td>
							100%
						</td>
					</tr>
					<tr class="danger">
						<td>
							2014-02-09 00:10:00
						</td>
						<td>
							13500
						</td>
						<td>
							0.0268
						</td>
						<td>
							13400
						</td>
						<td>
							0.0268
						</td>
						<td>
							100
						</td>
						<td>
							0.0263
						</td>
						<td>
							98.1%
						</td>
					</tr>
					<tr>
						<td>
							2014-02-09 00:15:00
						</td>
						<td>
							13500
						</td>
						<td>
							0.0268
						</td>
						<td>
							13500
						</td>
						<td>
							0.0268
						</td>
						<td>
							0
						</td>
						<td>
							0
						</td>
						<td>
							100%
						</td>
					</tr>
					<tr>
						<td>
							2014-02-09 00:20:00
						</td>
						<td>
							13500
						</td>
						<td>
							0.0268
						</td>
						<td>
							13500
						</td>
						<td>
							0.0268
						</td>
						<td>
							0
						</td>
						<td>
							0
						</td>
						<td>
							100%
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</div>
<script>
Highcharts.setOptions({
    global: {
        useUTC: false
    }
});
$(function () {
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
        series: [        {
            name: '成功曲线',
            data: [
                <?php echo $req_suc_series;?>
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
                <?php echo $req_fail_series;?>
            ],
            lineWidth: 2,
            marker:{
                radius: 1
            },
            pointInterval: 300*1000,
            color : '#9C0D0D'
        }]
    });
});
$(function () {
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
            text: "jumei.com",
            href: "http://www.jumei.com"
        },
        series: [        {
            name: '成功曲线',
            data: [
                <?php echo $cost_suc_series;?>
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
                   <?php echo $cost_fail_series;?>
            ],
            lineWidth: 2,
            marker:{
                radius: 1
            },
            pointInterval: 300*1000,
            color : '#9C0D0D'
        }            ]
    });
});
</script>