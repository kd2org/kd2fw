(function () {
	var canvas = document.createElement('canvas');
	var URL = (window.URL || window.webkitURL);

	// Required JS objects
	if (!FileReader || !File || !document.querySelector || !FormData || !XMLHttpRequest || !JSON || !canvas.toBlob || !DataView || !URL)
	{
		return false;
	}

	delete canvas;

	function getByteSize(size, bytes)
	{
		if (size < 1024)
			return size + ' ' + bytes;
		else if (size < 1024*1024)
			return Math.round(size / 1024) + ' K' + bytes;
		else
			return (Math.round(size / 1024 / 1024 * 100) / 100) + ' M' + bytes;
	}

	// Source: https://stackoverflow.com/questions/7584794/accessing-jpeg-exif-rotation-data-in-javascript-on-the-client-side/32490603#32490603
	function getExifOrientation(file, callback) {
		var reader = new FileReader();

		reader.onload = function(e)
		{
			var view = new DataView(e.target.result);

			if (view.getUint16(0, false) != 0xFFD8)
				return callback(-2);

			var length = view.byteLength, offset = 2;

			while (offset < length)
			{
				var marker = view.getUint16(offset, false);
				offset += 2;

				if (marker == 0xFFE1)
				{
					if (view.getUint32(offset += 2, false) != 0x45786966) 
						return callback(-1);

					var little = view.getUint16(offset += 6, false) == 0x4949;
					offset += view.getUint32(offset + 4, little);
					var tags = view.getUint16(offset, little);
					offset += 2;

					for (var i = 0; i < tags; i++)
					{
						if (view.getUint16(offset + (i * 12), little) == 0x0112)
						{
							return callback(view.getUint16(offset + (i * 12) + 8, little));
						}
					}
				}
				else if ((marker & 0xFF00) != 0xFF00)
					break;
				else
					offset += view.getUint16(offset, false);
			}
			return callback(-1);
		};

		reader.readAsArrayBuffer(file);
	}

	window.uploadHelper = function (source, options) {
		var rusha = new Rusha();

		var form = source.form;

		var files = [];
		
		var upload_queue = false;
		var hash_queue = false;

		var progress_status = false;
		var progress_bar = false;

		var options = options || {};

		options.width = options.width || false;
		options.jpeg_quality = options.jpeg_quality || 0.87;
		options.thumb_width = options.thumb_width || 250;
		options.thumb_quality = options.thumb_quality || 0.80;
		options.thumb_upload = !!options.thumb_upload;
		options.resize = (options.width && options.resize) ? true : false;
		options.bytes = options.bytes || 'B';
		options.check_hash = options.check_hash || false;
		options.edit_name_field = options.edit_name_field || false;
		options.size_error_msg = options.size_error_msg 
			|| 'The file %file has a size of %size, more than the allowed %max_size allowed.';

		var max_size = null;

		// Get the maximum file size, the standard way
		if (i = form.querySelector('input[name=MAX_FILE_SIZE]'))
		{
			var max_size = i.value;
		}

		var div = document.createElement('div');
		div.classList.toggle('uploadHelper');

		var file_list = document.createElement('table');

		var fileInput = source.cloneNode(true);
		fileInput.required = false;
		div.appendChild(fileInput);
		div.appendChild(file_list);

		source.parentNode.replaceChild(div, source);

		function appendFile(file)
		{
			files.push(file);
			var tr = document.createElement('tr');

			var preview = document.createElement('td');
			preview.className = 'preview';

			makePreview(file, preview);

			var name = document.createElement('th');

			var input = document.createElement('input');
			input.type = 'text';
			input.value = file.name.replace(/\.\w+$/, '');
			input.disabled = !options.edit_name_field;

			name.appendChild(input);

			var actions = document.createElement('td');
			var deleteBtn = document.createElement('input');
			deleteBtn.type = 'button';
			deleteBtn.value = '🗙';
			deleteBtn.title = 'Delete';
			deleteBtn.onclick = deleteFile;
			actions.appendChild(deleteBtn);

			tr.appendChild(preview);
			tr.appendChild(name);
			tr.appendChild(actions);
			file_list.appendChild(tr);
		}

		function deleteFile(e)
		{
			var row = e.target.parentNode.parentNode;
			var idx = row.rowIndex;
			files.splice(idx, 1);
			row.className = 'deleted';

			window.setTimeout(function () { row.parentNode.removeChild(row); }, 750);
		}

		function makePreview(file, parent)
		{
			if (file.type.match(/^image\/(jpe?g|gif|svg|png)$/))
			{
				resize(file, options.thumb_width, options.thumb_height, function(blob) {
					file.url = URL.createObjectURL(blob);
					var img = new Image;
					img.src = file.url;
					parent.appendChild(img);
				});
			}
			else
			{
				var css_class = file.type.replace(/^(\w+)\/.*$/g, 'type_$1');
				css_class += ' type_' + file.type.replace(/[^\w]$/g, '_');
				var icn = document.createElement('span');
				icn.className = css_class;
				icn.innerHTML = file.type;
				parent.appendChild(icn);
			}
		}

		fileInput.addEventListener('change', function ()
		{
			hash_queue = [];

			// No files selected, nothing to do
			if (this.files.length < 1)
			{
				return false;
			}

			// The input is not multiple but multiple files were selected, wtf!
			if (!this.multiple && this.files.length > 1)
			{
				this.value = '';
				return false;
			}


			var l = this.files.length;
			for (var i = 0; i < l; i++)
			{
				var file = this.files[i];

				// Check file size
				if (file.size > max_size && (!options.resize || !file.type.match(/^image\/jpe?g/)))
				{
					this.value = '';
					var args = {
						name: file.name, 
						size: getByteSize(file.size, options.bytes), 
						max_size: getByteSize(max_size, options.bytes)
					};
					var msg = options.size_error_msg.replace(/%([a-z_]+)/g, function (match, name) {
						return args[name];
					});
					return !alert(msg);
				}

				hash_queue.push(file);
				appendFile(file);
			}

			this.value = '';
			runHashQueue();
		}, false);

		form.addEventListener('submit', function (e) {
			// No files to upload, just send the form
			if (files.length == 0)
			{
				return true;
			}

			e.preventDefault();

			// Someone clicked the submit button, but the upload queue is already running
			if (upload_queue !== false)
			{
				return false;
			}

			// Nothing left to upload, just send the form
			if (upload_queue.length == 0)
			{
				return true;
			}

			// Convert FileList object to an array
			upload_queue = files;

			// Make sure the file input isn't sent
			fileInput.disabled = true;

			// Make all form elements read-only
			var list = form.elements;
			for (var i = 0; i < list.length; i++)
			{
				if (list[i].type != 'hidden')
				{
					list[i].readOnly = true;
				}
			}

			var progress_container = document.createElement('div');
			progress_container.className = 'uploadHelper_progress';
			
			progress_bar = document.createElement('progress');
			
			progress_container.appendChild(progress_bar);
			fileInput.parentNode.insertBefore(progress_container, fileInput.nextSibling);

			if (options.check_hash)
			{
				var http = new XMLHttpRequest();
				var data = new FormData();
				var count = 0;

				for (var i = 0; i < upload_queue.length; i++)
				{
					if (upload_queue[i].hash)
					{
						data.append('uploadHelper_hashCheck[]', upload_queue[i].hash);
						count++;
					}
				}

				if (count > 0)
				{
					http.onreadystatechange = function ()
					{
						if (http.readyState != 4) return;

						if (http.status == 200)
						{
							var result = http.responseText;
							result = window.JSON.parse(result);

							upload_queue = upload_queue.filter(function (file) {
								if (!file.hash) return true;

								if (file.hash in result) file.noUpload = true;
								return true;
							});
						}

						runUploadQueue();
					};

					http.open('POST', form.action, true);
					http.send(data);
					return false;
				}
			}

			runUploadQueue();

			return false;
		}, false);

		function runHashQueue()
		{
			if (hash_queue.length == 0)
				return false;

			var file = hash_queue.shift();
			var fr = new FileReader;
			fr.file = file;

			fr.onloadend = function () {
				if (this.error) return false;
				fr.file.hash = rusha.digest(fr.result);
				delete fr;
				runHashQueue();
			};

			fr.readAsArrayBuffer(file);
		}

		function runUploadQueue()
		{
			if (upload_queue.length == 0)
			{
				// File upload is finished, some reasons this may happen:
				// - the server replied 'next' while there was nothing in the queue
				// - after hash check the upload queue is empty and no redirect URL was given
				return false;
			}

			var file = upload_queue.shift();
			progress_status = upload_queue.length;

			var fileIndex = files.length - 1 - upload_queue.length;

			if (options.resize && file.type.match(/^image\/jpe?g/) && !file.noUpload)
			{
				var row = file_list.rows[fileIndex];
				row.className = 'resizing';
				progress_bar.removeAttribute('max');
				progress_bar.removeAttribute('value');
				resize(file, options.width, options.height, function (resizedBlob) {
					// Check file size
					if (resizedBlob.size > max_size)
					{
						this.value = '';
						var args = {
							name: file.name, 
							size: getByteSize(resizedBlob.size, options.bytes), 
							max_size: getByteSize(max_size, options.bytes)
						};

						var msg = options.size_error_msg.replace(/%([a-z_]+)/g, function (match, name) {
							return args[name];
						});

						abortUpload();
						return !alert(msg);
					}

					var callback = function (blob) {
						uploadFile(file, fileIndex, resizedBlob, blob);
					};

					if (options.upload_thumb)
					{
						var img = row.querySelector('img');
						var canvas = document.createElement('canvas');
						canvas.width = img.width;
						canvas.height = img.height;
						canvas.getContext('2d').drawImage(img, 0, 0);
						canvas.toBlob(callback,  'image/jpeg', options.thumb_quality);
					}
					else
					{
						callback(false);
					}
				});
			}
			else
			{
				uploadFile(file, fileIndex, false);
			}
		}

		function uploadFile(file, fileIndex, resizedBlob, thumbnailBlob)
		{
			var http = new XMLHttpRequest();
			var data = new FormData(form);

			var row = file_list.rows[fileIndex];
			row.className = 'uploading';

			if (options.edit_name_field)
			{
				file.alias = row.querySelector('input[type=text]').value;
			}

			if (file.noUpload)
			{
				data.append('uploadHelper_mode', 'hash_only');
				data.append('uploadHelper_fileName', file.name);
			}
			else if (resizedBlob)
			{
				data.append('uploadHelper_mode', 'upload');
				data.append(fileInput.getAttribute('name'), resizedBlob, file.alias || file.name);
			}
			else
			{
				data.append('uploadHelper_mode', 'upload');
				data.append(fileInput.getAttribute('name'), file);
			}

			if (thumbnailBlob)
			{
				data.append('uploadHelper_thumbnail', thumbnailBlob, file.alias || file.name);
			}

			if (file.hash)
			{
				data.append('uploadHelper_fileHash', file.hash);
			}

			data.append('uploadHelper_status', progress_status);

			http.onprogress = function (e) {
				progress_bar.max = e.total;

				if (e.lengthComputable)
				{
					progress_bar.value = e.loaded;
					progress_bar.innerHTML = Math.round(e.loaded / e.total) + '%';
				}
				else
				{
					progress_bar.innerHTML = e.loaded;
				}
			};

			http.onreadystatechange = function ()
			{
				if (http.readyState != 4) return;

				if (http.status == 200)
				{
					row.className = 'uploaded';

					try {
						var result = window.JSON.parse(http.responseText);
					}
					catch (e)
					{
						var result = {error: 'Server replied with invalid JSON ('+e.message+'): ' + http.responseText};
					}

					try {
						if (result.callback && window[result.callback](result))
						{
							return false;
						}
						else if (result.redirect)
						{
							location.href = result.redirect;
							return false;
						}
						else if (result.next)
						{
							runUploadQueue();
							return false;
						}
					}
					catch (e) {
						var result = {error: 'Server error: ' + e.message};
					}
				}
				else
				{
					var result = {error: 'Server response error. HTTP code: ' + http.status};
				}

				alert(result.error);

				abortUpload();
				delete http;
			};

			http.open('POST', form.action, true);
			http.send(data);
			delete data;

			// Don't send form fields after the first upload
			if (upload_queue.length + 1 == files.length)
			{
				var list = form.elements;
				for (var i = 0; i < list.length; i++)
				{
					if (list[i].type != 'hidden')
					{
						list[i].disabled = true;
					}
				}
			}
		}

		function abortUpload()
		{
			var list = form.elements;
			for (var i = 0; i < list.length; i++)
			{
				if (list[i].type != 'hidden')
				{
					list[i].disabled = false;
					list[i].readOnly = false;
				}
			}

			progress_status = 'error';
			progress_bar.parentNode.parentNode.removeChild(progress_bar.parentNode);

			upload_queue = false;
		}

		function resize(file, max_width, max_height, callback, orientation = null)
		{
			if (null === orientation)
			{
				return getExifOrientation(file, function (orientation) {
					return resize(file, max_width, max_height, callback, orientation);
				});
			}

			max_height = max_height || max_width;

			// Create image using temporary URL
			var img = new Image;
			img.src = (window.URL || window.webkitURL).createObjectURL(file);

			img.onload = function() {
				// Flip/rotate following orientation
				if (orientation && orientation <= 8)
				{
					var canvas1 = document.createElement("canvas");
					console.log('rotate/flip');

					if ([5,6,7,8].indexOf(orientation) > -1)
					{
						canvas1.width = img.height;
						canvas1.height = img.width;
					}
					else
					{
						canvas1.width = img.width;
						canvas1.height = img.height;
					}

					var ctx = canvas1.getContext('2d');
					
					switch (orientation) {
						case 2: ctx.transform(-1, 0, 0, 1, img.width, 0); break;
						case 3: ctx.transform(-1, 0, 0, -1, img.width, img.height); break;
						case 4: ctx.transform(1, 0, 0, -1, 0, img.height); break;
						case 5: ctx.transform(0, 1, 1, 0, 0, 0); break;
						case 6: ctx.transform(0, 1, -1, 0, img.height , 0); break;
						case 7: ctx.transform(0, -1, -1, 0, img.height, img.width); break;
						case 8: ctx.transform(0, -1, 1, 0, 0, img.width); break;
						default: ctx.transform(1, 0, 0, 1, 0, 0);
					}

					ctx.drawImage(img, 0, 0);
					console.log(canvas1 || img);
				}

				var width = max_width,
					height = max_height;

				in_ratio = (canvas1 || img).width / (canvas1 || img).height;
				out_ratio = max_width / max_height;

				if (in_ratio >= out_ratio)
				{
					height = max_width / in_ratio;
				}
				else
				{
					width = max_height * in_ratio;
				}

				width = Math.abs(width);
				height = Math.abs(height);

				console.log([img.width, img.height], [canvas1.width, canvas1.height], [width, height]);

				// Two-step downscaling for better quality
				var canvas2 = document.createElement("canvas");
				var factor = 1;

				if (width < (canvas1 || img).width || height < (canvas1 || img).height)
				{
					factor = 2;
				}

				console.log('factor', 2);

				canvas2.width = width*factor;
				canvas2.height = height*factor;

				canvas2.getContext('2d').drawImage(
					(canvas1 || img), // original image
					0, // starting x point
					0, // starting y point
					(canvas1 || img).width, // image width
					(canvas1 || img).height, // image height
					0, // destination x point
					0, // destination y point
					width*factor, // destination width
					height*factor // destination height
				);

				(window.URL || window.webkitURL).revokeObjectURL(img.src);
				delete img, canvas1;

				// Second step down scaling
				if (factor > 1)
				{
					var canvas3 = document.createElement("canvas");

					canvas3.width = width;
					canvas3.height = height;

					canvas3.getContext('2d').drawImage(
						canvas2, // original image
						0, // starting x point
						0, // starting y point
						canvas2.width, // image width
						canvas2.height, // image height
						0, // destination x point
						0, // destination y point
						width, // destination width
						height // destination height
					);
				}

				(canvas3 || canvas2).toBlob(callback, 'image/jpeg', options.jpeg_quality);

				delete canvas2;
				delete canvas3;
			};
		}
	};

	/*! rusha 2017-07-20, removed useless parts before minimizing */
	!function(){function n(r){"use strict";for(var e={fill:0},a=function(n){for(n+=9;n%64>0;n+=1);return n},t=function(n,r){var e=new Uint8Array(n.buffer),a=r%4,t=r-a;switch(a){case 0:e[t+3]=0;case 1:e[t+2]=0;case 2:e[t+1]=0;case 3:e[t+0]=0}for(var f=1+(r>>2);f<n.length;f++)n[f]=0},f=function(n,r,e){n[r>>2]|=128<<24-(r%4<<3),n[14+(2+(r>>2)&-16)]=e/(1<<29)|0,n[15+(2+(r>>2)&-16)]=e<<3},u=function(n,r,e,a,t){var f,u=this,i=t%4,o=(a+i)%4,h=a-o;switch(i){case 0:n[t]=u[e+3];case 1:n[t+1-(i<<1)|0]=u[e+2];case 2:n[t+2-(i<<1)|0]=u[e+1];case 3:n[t+3-(i<<1)|0]=u[e]}if(!(a<o+i)){for(f=4-i;f<h;f=f+4|0)r[t+f>>2|0]=u[e+f]<<24|u[e+f+1]<<16|u[e+f+2]<<8|u[e+f+3];switch(o){case 3:n[t+h+1|0]=u[e+h+2];case 2:n[t+h+2|0]=u[e+h+1];case 1:n[t+h+3|0]=u[e+h]}}},i=function(n){return u.bind(new Uint8Array(n))},o=new Array(256),h=0;h<256;h++)o[h]=(h<16?"0":"")+h.toString(16);var c=function(n){for(var r=new Uint8Array(n),e=new Array(n.byteLength),a=0;a<e.length;a++)e[a]=o[r[a]];return e.join("")},s=function(n){var r;if(n<=65536)return 65536;if(n<16777216)for(r=1;r<n;r<<=1);else for(r=16777216;r<n;r+=16777216);return r};!function(r){if(r%64>0)throw new Error("Chunk size must be a multiple of 128 bit");e.offset=0,e.maxChunkLen=r,e.padMaxChunkLen=a(r),e.heap=new ArrayBuffer(s(e.padMaxChunkLen+320+20)),e.h32=new Int32Array(e.heap),e.h8=new Int8Array(e.heap),e.core=new n._core({Int32Array:Int32Array,DataView:DataView},{},e.heap),e.buffer=null}(r||65536);var w=function(n,r){e.offset=0;var a=new Int32Array(n,r+320,5);a[0]=1732584193,a[1]=-271733879,a[2]=-1732584194,a[3]=271733878,a[4]=-1009589776},v=function(n,r){var u=a(n),i=new Int32Array(e.heap,0,u>>2);return t(i,n),f(i,n,r),u},p=function(n,r,a,t){i(n)(e.h8,e.h32,r,a,t||0)},y=function(n,r,a,t,f){var u=a;p(n,r,a),f&&(u=v(a,t)),e.core.hash(u,e.padMaxChunkLen)},A=function(n,r){var e=new Int32Array(n,r+320,5),a=new Int32Array(5),t=new DataView(a.buffer);return t.setInt32(0,e[0],!1),t.setInt32(4,e[1],!1),t.setInt32(8,e[2],!1),t.setInt32(12,e[3],!1),t.setInt32(16,e[4],!1),a},I=this.rawDigest=function(n){var r=n.byteLength||n.length||n.size||0;w(e.heap,e.padMaxChunkLen);var a=0,t=e.maxChunkLen;for(a=0;r>a+t;a+=t)y(n,a,t,r,!1);return y(n,a,r-a,r,!0),A(e.heap,e.padMaxChunkLen)};this.digest=function(n){return c(I(n).buffer)};var d=this.rawEnd=function(){var n=e.offset,r=n%e.maxChunkLen,a=v(r,n);e.core.hash(a,e.padMaxChunkLen);var t=A(e.heap,e.padMaxChunkLen);return w(e.heap,e.padMaxChunkLen),t};this.end=function(){return c(d().buffer)}}n._core=function(n,r,e){"use asm";var a=new n.Int32Array(e);function t(n,r){n=n|0;r=r|0;var e=0,t=0,f=0,u=0,i=0,o=0,h=0,c=0,s=0,w=0,v=0,p=0,y=0,A=0;f=a[r+320>>2]|0;i=a[r+324>>2]|0;h=a[r+328>>2]|0;s=a[r+332>>2]|0;v=a[r+336>>2]|0;for(e=0;(e|0)<(n|0);e=e+64|0){u=f;o=i;c=h;w=s;p=v;for(t=0;(t|0)<64;t=t+4|0){A=a[e+t>>2]|0;y=((f<<5|f>>>27)+(i&h|~i&s)|0)+((A+v|0)+1518500249|0)|0;v=s;s=h;h=i<<30|i>>>2;i=f;f=y;a[n+t>>2]=A}for(t=n+64|0;(t|0)<(n+80|0);t=t+4|0){A=(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])<<1|(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])>>>31;y=((f<<5|f>>>27)+(i&h|~i&s)|0)+((A+v|0)+1518500249|0)|0;v=s;s=h;h=i<<30|i>>>2;i=f;f=y;a[t>>2]=A}for(t=n+80|0;(t|0)<(n+160|0);t=t+4|0){A=(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])<<1|(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])>>>31;y=((f<<5|f>>>27)+(i^h^s)|0)+((A+v|0)+1859775393|0)|0;v=s;s=h;h=i<<30|i>>>2;i=f;f=y;a[t>>2]=A}for(t=n+160|0;(t|0)<(n+240|0);t=t+4|0){A=(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])<<1|(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])>>>31;y=((f<<5|f>>>27)+(i&h|i&s|h&s)|0)+((A+v|0)-1894007588|0)|0;v=s;s=h;h=i<<30|i>>>2;i=f;f=y;a[t>>2]=A}for(t=n+240|0;(t|0)<(n+320|0);t=t+4|0){A=(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])<<1|(a[t-12>>2]^a[t-32>>2]^a[t-56>>2]^a[t-64>>2])>>>31;y=((f<<5|f>>>27)+(i^h^s)|0)+((A+v|0)-899497514|0)|0;v=s;s=h;h=i<<30|i>>>2;i=f;f=y;a[t>>2]=A}f=f+u|0;i=i+o|0;h=h+c|0;s=s+w|0;v=v+p|0}a[r+320>>2]=f;a[r+324>>2]=i;a[r+328>>2]=h;a[r+332>>2]=s;a[r+336>>2]=v}return{hash:t}},window.Rusha=n}();
}());