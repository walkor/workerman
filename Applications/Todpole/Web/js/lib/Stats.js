/*
 * stats.js r4
 * http://github.com/mrdoob/stats.js
 *
 * Released under MIT license:
 * http://www.opensource.org/licenses/mit-license.php
 *
 * How to use:
 *
 *  var stats = new Stats();
 *  parentElement.appendChild(stats.domElement);
 *
 *  setInterval(function () {
 *
 *  	stats.update();
 *
 *  }, 1000/60);
 *
 */

var Stats = function () {

	var _container, _mode = 'fps',
	_frames = 0, _time = new Date().getTime(), _timeLastFrame = _time, _timeLastSecond = _time,
	_fps = 0, _fpsMin = 1000, _fpsMax = 0, _fpsText, _fpsCanvas, _fpsContext, _fpsImageData,
	_ms = 0, _msMin = 1000, _msMax = 0, _msText, _msCanvas, _msContext, _msImageData;

	_container = document.createElement( 'div' );
	_container.style.fontFamily = 'Helvetica, Arial, sans-serif';
	_container.style.fontSize = '9px';
	_container.style.backgroundColor = '#000020';
	_container.style.opacity = '0.9';
	_container.style.width = '80px';
	_container.style.paddingTop = '2px';
	_container.style.cursor = 'pointer';
	_container.addEventListener( 'click', swapMode, false );

	_fpsText = document.createElement( 'div' );
	_fpsText.innerHTML = '<strong>FPS</strong>';
	_fpsText.style.color = '#00ffff';
	_fpsText.style.marginLeft = '3px';
	_fpsText.style.marginBottom = '3px';
	_container.appendChild(_fpsText);

	_fpsCanvas = document.createElement( 'canvas' );
	_fpsCanvas.width = 74;
	_fpsCanvas.height = 30;
	_fpsCanvas.style.display = 'block';
	_fpsCanvas.style.marginLeft = '3px';
	_fpsCanvas.style.marginBottom = '3px';
	_container.appendChild(_fpsCanvas);

	_fpsContext = _fpsCanvas.getContext( '2d' );
	_fpsContext.fillStyle = '#101030';
	_fpsContext.fillRect( 0, 0, _fpsCanvas.width, _fpsCanvas.height );

	_fpsImageData = _fpsContext.getImageData( 0, 0, _fpsCanvas.width, _fpsCanvas.height );

	_msText = document.createElement( 'div' );
	_msText.innerHTML = '<strong>MS</strong>';
	_msText.style.color = '#00ffff';
	_msText.style.marginLeft = '3px';
	_msText.style.marginBottom = '3px';
	_msText.style.display = 'none';
	_container.appendChild(_msText);

	_msCanvas = document.createElement( 'canvas' );
	_msCanvas.width = 74;
	_msCanvas.height = 30;
	_msCanvas.style.display = 'block';
	_msCanvas.style.marginLeft = '3px';
	_msCanvas.style.marginBottom = '3px';
	_msCanvas.style.display = 'none';
	_container.appendChild(_msCanvas);

	_msContext = _msCanvas.getContext( '2d' );
	_msContext.fillStyle = '#101030';
	_msContext.fillRect( 0, 0, _msCanvas.width, _msCanvas.height );

	_msImageData = _msContext.getImageData( 0, 0, _msCanvas.width, _msCanvas.height );

	function updateGraph( data, value ) {

		var x, y, index;

		for ( y = 0; y < 30; y++ ) {

			for ( x = 0; x < 73; x++ ) {

				index = (x + y * 74) * 4;

				data[ index ] = data[ index + 4 ];
				data[ index + 1 ] = data[ index + 5 ];
				data[ index + 2 ] = data[ index + 6 ];

			}

		}

		for ( y = 0; y < 30; y++ ) {

			index = (73 + y * 74) * 4;

			if ( y < value ) {

				data[ index ] = 16;
				data[ index + 1 ] = 16;
				data[ index + 2 ] = 48;

			} else {

				data[ index ] = 0;
				data[ index + 1 ] = 255;
				data[ index + 2 ] = 255;

			}

		}

	}

	function swapMode() {

		switch( _mode ) {

			case 'fps':

				_mode = 'ms';

				_fpsText.style.display = 'none';
				_fpsCanvas.style.display = 'none';
				_msText.style.display = 'block';
				_msCanvas.style.display = 'block';

				break;

			case 'ms':

				_mode = 'fps';

				_fpsText.style.display = 'block';
				_fpsCanvas.style.display = 'block';
				_msText.style.display = 'none';
				_msCanvas.style.display = 'none';

				break;

		}

	}

	return {

		domElement: _container,

		update: function () {

			_frames ++;

			_time = new Date().getTime();

			_ms = _time - _timeLastFrame;
			_msMin = Math.min( _msMin, _ms );
			_msMax = Math.max( _msMax, _ms );

			updateGraph( _msImageData.data, Math.min( 30, 30 - ( _ms / 200 ) * 30 ) );

			_msText.innerHTML = '<strong>' + _ms + ' MS</strong> (' + _msMin + '-' + _msMax + ')';
			_msContext.putImageData( _msImageData, 0, 0 );

			_timeLastFrame = _time;

			if ( _time > _timeLastSecond + 1000 ) {

				_fps = Math.round( ( _frames * 1000) / ( _time - _timeLastSecond ) );
				_fpsMin = Math.min( _fpsMin, _fps );
				_fpsMax = Math.max( _fpsMax, _fps );

				updateGraph( _fpsImageData.data, Math.min( 30, 30 - ( _fps / 100 ) * 30 ) );

				_fpsText.innerHTML = '<strong>' + _fps + ' FPS</strong> (' + _fpsMin + '-' + _fpsMax + ')';
				_fpsContext.putImageData( _fpsImageData, 0, 0 );

				_timeLastSecond = _time;
				_frames = 0;

			}

		}

	};

};