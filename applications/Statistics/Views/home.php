<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Bootstrap 3, from LayoutIt!</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="">
  <meta name="author" content="">

	<!--link rel="stylesheet/less" href="less/bootstrap.less" type="text/css" /-->
	<!--link rel="stylesheet/less" href="less/responsive.less" type="text/css" /-->
	<!--script src="js/less-1.3.3.min.js"></script-->
	<!--append ‘#!watch’ to the browser URL, then refresh the page. -->
	
	<link href="/css/bootstrap.min.css" rel="stylesheet">
	<link href="/css/style.css" rel="stylesheet">

  <!-- HTML5 shim, for IE6-8 support of HTML5 elements -->
  <!--[if lt IE 9]>
    <script src="js/html5shiv.js"></script>
  <![endif]-->

  <!-- Fav and touch icons -->
  <link rel="apple-touch-icon-precomposed" sizes="144x144" href="img/apple-touch-icon-144-precomposed.png">
  <link rel="apple-touch-icon-precomposed" sizes="114x114" href="img/apple-touch-icon-114-precomposed.png">
  <link rel="apple-touch-icon-precomposed" sizes="72x72" href="img/apple-touch-icon-72-precomposed.png">
  <link rel="apple-touch-icon-precomposed" href="img/apple-touch-icon-57-precomposed.png">
  <link rel="shortcut icon" href="img/favicon.png">
  
	<script type="text/javascript" src="/js/jquery.min.js"></script>
	<script type="text/javascript" src="/js/bootstrap.min.js"></script>
	<script type="text/javascript" src="/js/scripts.js"></script>
	 <script type="text/javascript" src="/js/jquery.min.js"></script>
        <script type="text/javascript" src="/js/highcharts.js"></script>
        <script type="text/javascript">
$(function () {
        $('#container').highcharts({
            chart: {
                type: 'area'
            },
            title: {
                text: 'US and USSR nuclear stockpiles'
            },
            subtitle: {
                text: 'Source: <a href="http://thebulletin.metapress.com/content/c4120650912x74k7/fulltext.pdf">'+
                    'thebulletin.metapress.com</a>'
            },
            xAxis: {
                labels: {
                    formatter: function() {
                        return this.value; // clean, unformatted number for year
                    }
                }
            },
            yAxis: {
                title: {
                    text: 'Nuclear weapon states'
                },
                labels: {
                    formatter: function() {
                        return this.value / 1000 +'k';
                    }
                }
            },
            tooltip: {
                pointFormat: '{series.name} produced <b>{point.y:,.0f}</b><br/>warheads in {point.x}'
            },
            plotOptions: {
                area: {
                    pointStart: 1940,
                    marker: {
                        enabled: false,
                        symbol: 'circle',
                        radius: 2,
                        states: {
                            hover: {
                                enabled: true
                            }
                        }
                    }
                }
            },
            series: [{
                name: 'USA',
                data: [null, null, null, null, null, 6 , 11, 32, 110, 235, 369, 640,
                    1005, 1436, 2063, 3057, 4618, 6444, 9822, 15468, 20434, 24126,
                    27387, 29459, 31056, 31982, 32040, 31233, 29224, 27342, 26662,
                    26956, 27912, 28999, 28965, 27826, 25579, 25722, 24826, 24605,
                    24304, 23464, 23708, 24099, 24357, 24237, 24401, 24344, 23586,
                    22380, 21004, 17287, 14747, 13076, 12555, 12144, 11009, 10950,
                    10871, 10824, 10577, 10527, 10475, 10421, 10358, 10295, 10104 ]
            }, {
                name: 'USSR/Russia',
                data: [null, null, null, null, null, null, null , null , null ,null,
                5, 25, 50, 120, 150, 200, 426, 660, 869, 1060, 1605, 2471, 3322,
                4238, 5221, 6129, 7089, 8339, 9399, 10538, 11643, 13092, 14478,
                15915, 17385, 19055, 21205, 23044, 25393, 27935, 30062, 32049,
                33952, 35804, 37431, 39197, 45000, 43000, 41000, 39000, 37000,
                35000, 33000, 31000, 29000, 27000, 25000, 24000, 23000, 22000,
                21000, 20000, 19000, 18000, 18000, 17000, 16000]
            }]
        });
    });
    

        </script>
</head>

<body>
<div class="container">
	<div class="row clearfix">
		<div class="col-md-12 column">
			<ul class="nav nav-tabs">
				<li class="active">
					<a href="#">概述</a>
				</li>
				<li>
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
			<div id="container" style="min-width: 310px; height: 400px; margin: 0 auto"></div>
			<h3 class="text-danger text-center">
				{$module}::{$interface}耗时曲线
			</h3>
			<div id="container" style="min-width: 310px; height: 400px; margin: 0 auto"></div>
			<h3 class="text-primary text-center">
				{$module}::{$interface}请求量曲线
			</h3>
			<div id="container" style="min-width: 310px; height: 400px; margin: 0 auto"></div>
			<h3 class="text-danger text-center">
				{$module}::{$interface}耗时曲线
			</h3> 
			<div id="container" style="min-width: 310px; height: 400px; margin: 0 auto"></div>
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
</body>
</html>
