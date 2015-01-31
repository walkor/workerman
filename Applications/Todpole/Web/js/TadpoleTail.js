var TadpoleTail = function(tadpole) {
	var tail = this;
	tail.joints = [];
	
	var tadpole = tadpole;
	var jointSpacing = 1.4;
	var animationRate = 0;
	
	
	tail.update = function() {
		animationRate += (.2 + tadpole.momentum / 10);
		
		for(var i = 0, len = tail.joints.length; i < len; i++) {
			var tailJoint = tail.joints[i];
			var parentJoint = tail.joints[i-1] || tadpole;
			var anglediff = (parentJoint.angle - tailJoint.angle);
			
			while(anglediff < -Math.PI) {
				anglediff += Math.PI * 2;
			}
			while(anglediff > Math.PI) {
				anglediff -= Math.PI * 2;
			}
			
			tailJoint.angle += anglediff * (jointSpacing * 3 + (Math.min(tadpole.momentum / 2, Math.PI * 1.8))) / 8;
			tailJoint.angle += Math.cos(animationRate - (i / 3)) * ((tadpole.momentum + .3) / 40);
			
			if(i == 0) {
				tailJoint.x = parentJoint.x + Math.cos(tailJoint.angle + Math.PI) * 5;
				tailJoint.y = parentJoint.y + Math.sin(tailJoint.angle + Math.PI) * 5;
			} else {
				tailJoint.x = parentJoint.x + Math.cos(tailJoint.angle + Math.PI) * jointSpacing;
				tailJoint.y = parentJoint.y + Math.sin(tailJoint.angle + Math.PI) * jointSpacing;
			}
		}
	};
	
	tail.draw = function(context) {
		var path = [[],[]];
		
		for(var i = 0, len = tail.joints.length; i < len; i++) {
			var tailJoint = tail.joints[i];
			
			var falloff = (tail.joints.length - i) / tail.joints.length;
			var jointSize =  (tadpole.size - 1.8) * falloff;
			
			var x1 = tailJoint.x + Math.cos(tailJoint.angle + Math.PI * 1.5) * jointSize;
			var y1 = tailJoint.y + Math.sin(tailJoint.angle + Math.PI * 1.5) * jointSize;
			
			var x2 = tailJoint.x + Math.cos(tailJoint.angle + Math.PI / 2) * jointSize;
			var y2 = tailJoint.y + Math.sin(tailJoint.angle + Math.PI / 2) * jointSize;
			
			path[0].push({x: x1, y: y1});
			path[1].push({x: x2, y: y2});
		}
		
		for(var i = 0; i < path[0].length; i++) {
			context.lineTo(path[0][i].x, path[0][i].y);
		}
		path[1].reverse();
		for(var i = 0; i < path[1].length; i++) {
			context.lineTo(path[1][i].x, path[1][i].y);
		}
	};
	
	(function() {
		for(var i = 0; i < 15; i++) {
			tail.joints.push({
				x: 0,
				y: 0,
				angle: Math.PI*2,
			})
		}
	})();
}