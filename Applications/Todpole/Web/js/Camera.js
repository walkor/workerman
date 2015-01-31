var Camera = function(aCanvas, aContext, x, y) {
	var camera = this;
	
	var canvas = aCanvas;
	var context = aContext;
	
	this.x = x;
	this.y = y;
	
	this.minZoom = 1.3;
	this.maxZoom = 1.8;
	this.zoom = this.minZoom;
	
	var backgroundColor = Math.random()*360;
	
	this.setupContext = function() {
		var translateX = canvas.width / 2 - camera.x * camera.zoom;
		var translateY = canvas.height / 2 - camera.y * camera.zoom;
		
		// Reset transform matrix
		context.setTransform(1,0,0,1,0,0);
		context.fillStyle = 'hsl('+backgroundColor+',50%,10%)';
		context.fillRect(0,0,canvas.width, canvas.height);
		
		context.translate(translateX, translateY);
		context.scale(camera.zoom, camera.zoom);
		
		if(debug) {
			drawDebug();
		}
	};
	
	this.update = function(model) {
		backgroundColor += 0.08;
		backgroundColor = backgroundColor > 360 ? 0 : backgroundColor;
		
		var targetZoom = (model.camera.maxZoom + (model.camera.minZoom - model.camera.maxZoom) * Math.min(model.userTadpole.momentum, model.userTadpole.maxMomentum) / model.userTadpole.maxMomentum);
		model.camera.zoom += (targetZoom - model.camera.zoom) / 60;
		
		var delta = {
			x: (model.userTadpole.x - model.camera.x) / 30,
			y: (model.userTadpole.y - model.camera.y) / 30
		}
		
		if(Math.abs(delta.x) + Math.abs(delta.y) > 0.1) {
			model.camera.x += delta.x;
			model.camera.y += delta.y;
			
			for(var i = 0, len = model.waterParticles.length; i < len; i++) {
				var wp = model.waterParticles[i];
				wp.x -= (wp.z - 1) * delta.x;
				wp.y -= (wp.z - 1) * delta.y;
			}
		}
	};
	
	// Gets bounds of current zoom level of current position
	this.getBounds = function() {
		return [
			{x: camera.x - canvas.width / 2 / camera.zoom, y: camera.y - canvas.height / 2 / camera.zoom},
			{x: camera.x + canvas.width / 2 / camera.zoom, y: camera.y + canvas.height / 2 / camera.zoom}
		];
	};
	
	// Gets bounds of minimum zoom level of current position
	this.getOuterBounds = function() {
		return [
			{x: camera.x - canvas.width / 2 / camera.minZoom, y: camera.y - canvas.height / 2 / camera.minZoom},
			{x: camera.x + canvas.width / 2 / camera.minZoom, y: camera.y + canvas.height / 2 / camera.minZoom}
		];
	};
	
	// Gets bounds of maximum zoom level of current position
	this.getInnerBounds = function() {
		return [
			{x: camera.x - canvas.width / 2 / camera.maxZoom, y: camera.y - canvas.height / 2 / camera.maxZoom},
			{x: camera.x + canvas.width / 2 / camera.maxZoom, y: camera.y + canvas.height / 2 / camera.maxZoom}
		];
	};
	
	this.startUILayer = function() {
		context.setTransform(1,0,0,1,0,0);
	}
	
	var debugBounds = function(bounds, text) {
		context.strokeStyle   = '#fff';
		context.beginPath();
		context.moveTo(bounds[0].x, bounds[0].y);
		context.lineTo(bounds[0].x, bounds[1].y);
		context.lineTo(bounds[1].x, bounds[1].y);
		context.lineTo(bounds[1].x, bounds[0].y);
		context.closePath();
		context.stroke();
		context.fillText(text, bounds[0].x + 10, bounds[0].y + 10);
	};
	
	var drawDebug = function() {
		debugBounds(camera.getInnerBounds(), 'Maximum zoom camera bounds');
		debugBounds(camera.getOuterBounds(), 'Minimum zoom camera bounds');
		debugBounds(camera.getBounds(), 'Current zoom camera bounds');
	};
};