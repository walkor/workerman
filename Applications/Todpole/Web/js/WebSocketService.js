var WebSocketService = function(model, webSocket) {
	var webSocketService = this;
	
	var webSocket = webSocket;
	var model = model;
	
	this.hasConnection = false;
	
	this.welcomeHandler = function(data) {
		webSocketService.hasConnection = true;
		
		model.userTadpole.id = data.id;
		model.tadpoles[data.id] = model.tadpoles[-1];
		delete model.tadpoles[-1];
		
		$('#chat').initChat();
		if($.cookie('todpole_name'))	{
			webSocketService.sendMessage('name:'+$.cookie('todpole_name'));
		}
	};
	
	this.updateHandler = function(data) {
		var newtp = false;
		
		if(!model.tadpoles[data.id]) {
			newtp = true;
			model.tadpoles[data.id] = new Tadpole();
			model.arrows[data.id] = new Arrow(model.tadpoles[data.id], model.camera);
		}
		
		var tadpole = model.tadpoles[data.id];
		
		if(tadpole.id == model.userTadpole.id) {			
			tadpole.name = data.name;
			return;
		} else {
			tadpole.name = data.name;
		}
		
		if(newtp) {
			tadpole.x = data.x;
			tadpole.y = data.y;
		} else {
			tadpole.targetX = data.x;
			tadpole.targetY = data.y;
		}
		
		tadpole.angle = data.angle;
		tadpole.momentum = data.momentum;
		
		tadpole.timeSinceLastServerUpdate = 0;
	}
	
	this.messageHandler = function(data) {
		var tadpole = model.tadpoles[data.id];
		if(!tadpole) {
			return;
		}
		tadpole.timeSinceLastServerUpdate = 0;
		tadpole.messages.push(new Message(data.message));
	}
	
	this.closedHandler = function(data) {
		if(model.tadpoles[data.id]) {
			delete model.tadpoles[data.id];
			delete model.arrows[data.id];
		}
	}
	
	this.redirectHandler = function(data) {
		if (data.url) {
			if (authWindow) {
				authWindow.document.location = data.url;
			} else {
				document.location = data.url;
			}			
		}
	}
	
	this.processMessage = function(data) {
		var fn = webSocketService[data.type + 'Handler'];
		if (fn) {
			fn(data);
		}
	}
	
	this.connectionClosed = function() {
		webSocketService.hasConnection = false;
		$('#cant-connect').fadeIn(300);
	};
	
	this.sendUpdate = function(tadpole) {
		var sendObj = {
			type: 'update',
			x: tadpole.x.toFixed(1),
			y: tadpole.y.toFixed(1),
			angle: tadpole.angle.toFixed(3),
			momentum: tadpole.momentum.toFixed(3)
		};
		
		if(tadpole.name) {
			sendObj['name'] = tadpole.name;
		}
		
		webSocket.send(JSON.stringify(sendObj));
	}
	
	this.sendMessage = function(msg) {
		var regexp = /name: ?(.+)/i;
		if(regexp.test(msg)) {
			model.userTadpole.name = msg.match(regexp)[1];
			$.cookie('todpole_name', model.userTadpole.name, {expires:14});
			return;
		}
		
		var sendObj = {
			type: 'message',
			message: msg
		};
		
		webSocket.send(JSON.stringify(sendObj));
	}
	
	this.authorize = function(token,verifier) {
		var sendObj = {
			type: 'authorize',
			token: token,
			verifier: verifier
		};
		
		webSocket.send(JSON.stringify(sendObj));
	}
}