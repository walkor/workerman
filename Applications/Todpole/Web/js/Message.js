var Message = function(msg) {
	var message = this;
	
	this.age = 1;
	this.maxAge = 300;
	
	this.message = msg;
	
	this.update = function() {
		this.age++;
	}
	
	this.draw = function(context,x,y,i) {
		var fontsize = 8;
		context.font = fontsize + "px 'proxima-nova-1','proxima-nova-2', arial, sans-serif";
		context.textBaseline = 'hanging';
		
		var paddingH = 3;
		var paddingW = 6;
		
		var messageBox = {
			width: context.measureText(message.message).width + paddingW * 2,
			height: fontsize + paddingH * 2,
			x: x,
			y: (y - i * (fontsize + paddingH * 2 +1))-20
		}
		
		var fadeDuration = 20;
		
		var opacity = (message.maxAge - message.age) / fadeDuration;
		opacity = opacity < 1 ? opacity : 1;
		
		context.fillStyle = 'rgba(255,255,255,'+opacity/20+')';
		drawRoundedRectangle(context, messageBox.x, messageBox.y, messageBox.width, messageBox.height, 10);
		context.fillStyle = 'rgba(255,255,255,'+opacity+')';
		context.fillText(message.message, messageBox.x + paddingW, messageBox.y + paddingH, 100);
	}
	
	var drawRoundedRectangle = function(ctx,x,y,w,h,r) {
		var r = r / 2;
		ctx.beginPath();
		ctx.moveTo(x, y+r);
		ctx.lineTo(x, y+h-r);
		ctx.quadraticCurveTo(x, y+h, x+r, y+h);
		ctx.lineTo(x+w-r, y+h);
		ctx.quadraticCurveTo(x+w, y+h, x+w, y+h-r);
		ctx.lineTo(x+w, y+r);
		ctx.quadraticCurveTo(x+w, y, x+w-r, y);
		ctx.lineTo(x+r, y);
		ctx.quadraticCurveTo(x, y, x, y+r);
		ctx.closePath();
		ctx.fill();
	}
}