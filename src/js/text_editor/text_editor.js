(function () {
	window.textEditor = function (id)
	{
		if (!document.getElementById(id))
		{
			throw new Error("Invalid ID parameter: " + id);
		}

		this.id = id;
		this.textarea = document.getElementById(id);
		this.shortcuts = [];

		this._key_map = {
			8: 'backspace', 9: 'tab', 13: 'enter', 16: 'shift', 17: 'ctrl', 18: 'alt',
			20: 'capslock', 27: 'esc', 32: 'space', 33: 'pageup', 34: 'pagedown',
			35: 'end', 36: 'home', 37: 'left', 38: 'up', 39: 'right', 40: 'down', 
			45: 'ins', 46: 'del', 91: 'meta', 93: 'meta', 224: 'meta',
            106: false, 107: false, 109: false, 110: false, 111 : false, 186: false,
			187: false, 188: false, 189: false, 190: false, 191: false, 192: false,
			219: false, 220: false, 221: false, 222: false
		};

		// F1-F20 function keys
		for (var i = 1; i < 20; ++i) {
			this._key_map[111 + i] = 'f' + i;
		}

		// Numeric keypad
		for (i = 0; i <= 9; ++i) {
			this._key_map[i + 96] = i;
		}

		this.preventKeyPress = false;
		var that = this;

		this.textarea.addEventListener('keydown', this.keyEvent, true);
		this.textarea.addEventListener('keypress', this.keyEvent, true);
	};

	textEditor.prototype.keyEvent = function (e) {
		var e = e || window.event;

		// Event propagation cancellation between keydown/keypress
		// Firefox/Gecko has a bug here where it's not stopping propagation
		// to keypress when keydown asks cancellation
		if (that.preventKeyPress && e.type == 'keypress')
		{
			that.preventKeyPress = false;
			return that.preventDefault(e);
		}

		for (var key in that.shortcuts)
		{
			var shortcut = that.shortcuts[key];

			if (e.metaKey)
				continue;

			if ((e.ctrlKey && !shortcut.ctrl) || (shortcut.ctrl && !e.ctrlKey))
				continue;

			if ((e.shiftKey && !shortcut.shift) || (shortcut.shift && !e.shiftKey))
				continue;

			if ((e.altKey && !shortcut.alt) || (shortcut.alt && !e.altKey))
				continue;

			if (!(key = that.matchKeyPress(shortcut.key, e)))
			{
				continue;
			}

			if (typeof shortcut.callback != 'function')
			{
				var key_name = (shortcut.ctrl ? 'Ctrl-' : '') + (shortcut.alt ? 'Alt-' : '')
				key_name += (shortcut.shift ? 'Shift-' : '') + shortcut;
				throw new Error("Invalid callback type for shortcut "+key_name);
			}

			var r = shortcut.callback.call(that, e, key);

			if (e.type == 'keydown' && r)
			{
				that.preventKeyPress = true;
			}

			return r ? that.preventDefault(e) : true;
		}

		return true;
	};

	textEditor.prototype.matchKeyPress = function (key, e)
	{
		e.key = (typeof e.which === 'number' && e.charCode) ? e.which : e.keyCode;
		key = key.toLowerCase();

		if (e.type == 'keypress' && e.which)
		{
			return (key == String.fromCharCode(e.key).toUpperCase()) ? key : false;
		}
		else if (this._key_map[e.key])
		{
			return (this._key_map[e.key] == key) ? key : false;
		}
		else 
		{
			return (String.fromCharCode(e.key).toLowerCase() == key) ? key : false;
		}
	};

	textEditor.prototype.preventDefault = function (e) {
       	if (e.preventDefault) e.preventDefault();
       	if (e.stopPropagation) e.stopPropagation();
      	e.returnValue = false;
      	e.cancelBubble = true;
		return false;
	};

	// Source: http://stackoverflow.com/questions/401593/textarea-selection
	textEditor.prototype.getSelection = function ()
	{
		var e = this.textarea;

		//Mozilla and DOM 3.0
		if('selectionStart' in e)
		{
			var l = e.selectionEnd - e.selectionStart;
			return { start: e.selectionStart, end: e.selectionEnd, length: l, text: e.value.substr(e.selectionStart, l) };
		}
		//IE
		else if(document.selection)
		{
			e.focus();
			var r = document.selection.createRange();
			var tr = e.createTextRange();
			var tr2 = tr.duplicate();
			tr2.moveToBookmark(r.getBookmark());
			tr.setEndPoint('EndToStart',tr2);
			if (r == null || tr == null) return { start: e.value.length, end: e.value.length, length: 0, text: '' };
			var text_part = r.text.replace(/[\r\n]/g,'.'); //for some reason IE doesn't always count the \n and \r in the length
			var text_whole = e.value.replace(/[\r\n]/g,'.');
			var the_start = text_whole.indexOf(text_part,tr.text.length);
			return { start: the_start, end: the_start + text_part.length, length: text_part.length, text: r.text };
		}
		//Browser not supported
		else return { start: e.value.length, end: e.value.length, length: 0, text: '' };
	};

	textEditor.prototype.replaceSelection = function (selection, replace_str)
	{
		var e = this.textarea;
		var start_pos = selection.start;
		var end_pos = start_pos + replace_str.length;
		e.value = e.value.substr(0, start_pos) + replace_str + e.value.substr(selection.end, e.value.length);
		this.setSelection(start_pos, end_pos);
		return {start: start_pos, end: end_pos, length: replace_str.length, text: replace_str};
	};

	textEditor.prototype.insertAtPosition = function (start_pos, str, new_pos)
	{
		var end_pos = start_pos + str.length;
		var e = this.textarea;
		e.value = e.value.substr(0, start_pos) + str + e.value.substr(start_pos, e.value.length - start_pos);
		if (!new_pos) new_pos = end_pos;
		return this.setSelection(new_pos, new_pos);
	};

	textEditor.prototype.setSelection = function (start_pos, end_pos)
	{
		var e = this.textarea;

		//Mozilla and DOM 3.0
		if('selectionStart' in e)
		{
			e.focus();
			e.selectionStart = start_pos;
			e.selectionEnd = end_pos;
		}
		//IE
		else if(document.selection)
		{
			e.focus();
			var tr = e.createTextRange();

			//Fix IE from counting the newline characters as two seperate characters
			var stop_it = start_pos;
			for (i=0; i < stop_it; i++) if( e.value[i].search(/[\r\n]/) != -1 ) start_pos = start_pos - .5;
			stop_it = end_pos;
			for (i=0; i < stop_it; i++) if( e.value[i].search(/[\r\n]/) != -1 ) end_pos = end_pos - .5;

			tr.moveEnd('textedit',-1);
			tr.moveStart('character',start_pos);
			tr.moveEnd('character',end_pos - start_pos);
			tr.select();
		}
		return this.getSelection();
	};

	textEditor.prototype.scrollToSelection = function (selection)
	{
		var e = this.textarea;
		var removed = e.value.substr(selection.end);
		e.value = e.value.substr(0, selection.end);
		e.scrollTop = 100000;
		var scroll = e.scrollTop;
		e.value += removed;
		e.scrollTop = scroll;
		this.setSelection(selection.start, selection.end);
	};

	textEditor.prototype.wrapSelection = function (selection, left_str, right_str)
	{
		var e = this.textarea;
		var scroll = e.scrollTop;
		var the_sel_text = selection.text;
		var selection =  this.replaceSelection(selection, left_str + the_sel_text + right_str );
		if(the_sel_text == '') selection = this.setSelection(selection.start + left_str.length, selection.start + left_str.length);
		e.scrollTop = scroll;
		return selection;
	};
}());