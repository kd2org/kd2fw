(function () {
	function inherit(proto) {
		function F() {}
  		F.prototype = proto
  		return new F
	}

	String.prototype.repeat = function(num)
	{
	    return new Array(num + 1).join(this);
	}

	window.codeEditor = function (id)
	{
		textEditor.call(this, id);

		this.nb_lines = 0;
		this.current_line = 0;
		this.search_str = null;
		this.search_pos = 0;
		this.params = {
			'indent_size': 4,
			'tab_size': 8,
			'lang': {
				'search': "Text to search?\n(regexps allowed, begin them with '/')",
				'replace': "Text for replacement?\n(use $1, $2... for regexp replacement)",
				'search_selection': "Text to replace in selection?\n(regexps allowed, begin them with '/')",
				'replace_result': "%d occurence found and replaced.",
				'goto': "Line to go to:",
				'no_search_result': "No search result found."
			}
		};

		that = this;

		this.init();
		this.textarea.spellcheck = false;

		this.shortcuts.push({shift: true, key: 'tab', callback: this.indent});
		this.shortcuts.push({key: 'tab', callback: this.indent});
		this.shortcuts.push({ctrl: true, key: 'f', callback: this.search});
		this.shortcuts.push({ctrl: true, key: 'h', callback: this.searchAndReplace});
		this.shortcuts.push({ctrl: true, key: 'g', callback: this.goToLine});
		this.shortcuts.push({key: 'F3', callback: this.searchNext});
		this.shortcuts.push({key: 'backspace', callback: this.backspace});
		this.shortcuts.push({key: 'enter', callback: this.enter});
		this.shortcuts.push({key: '"', callback: this.insertBrackets});
		this.shortcuts.push({key: '\'', callback: this.insertBrackets});
		this.shortcuts.push({key: '[', callback: this.insertBrackets});
		this.shortcuts.push({key: '{', callback: this.insertBrackets});
		this.shortcuts.push({key: '(', callback: this.insertBrackets});

		this.textarea.addEventListener('keypress', this.keyEvent, true);
		this.textarea.addEventListener('keydown', this.keyEvent, true);
	};

	// Extends textEditor
	codeEditor.prototype = inherit(textEditor.prototype);

	codeEditor.prototype.init = function () {
		var that = this;

		this.nb_lines = this.countLines();

		this.parent = document.createElement('div');
		this.parent.className = 'codeEditor';

		this.lineCounter = document.createElement('span');
		this.lineCounter.className = 'lineCount';

		for (i = 1; i <= this.nb_lines; i++)
		{
			this.lineCounter.innerHTML += '<b>' + i + '</b>';
		}

		this.lineCounter.innerHTML += '<i>---</i>';

		this.parent.appendChild(this.lineCounter);

		// This is to avoid a CSS-spec 'bug' http://snook.ca/archives/html_and_css/absolute-position-textarea
		var container = document.createElement('div');
		container.className = 'container';
		container.appendChild(this.textarea.cloneNode(true));
		this.parent.appendChild(container);

		var pnode = this.textarea.parentNode;
		pnode.appendChild(this.parent);
		pnode.removeChild(this.textarea);

		this.textarea = this.parent.getElementsByTagName('textarea')[0];
		this.textarea.wrap = 'off';

		this.textarea.onclick = function () {
			that.update();
		};

		this.textarea.onfocus = function () {
			that.update();
		};

		this.textarea.addEventListener('keyup', function () {
			that.update();
		});

		this.textarea.onscroll = function () {
			that.lineCounter.scrollTop = that.textarea.scrollTop;
		}
	};

	codeEditor.prototype.update = function () {
		var selection = this.getSelection();
		var line = this.getLineNumberFromPosition(selection);
		var nb_lines = this.countLines();
		this.search_pos = selection.end;

		if (nb_lines != this.nb_lines)
		{
			var lines = this.lineCounter.getElementsByTagName('b');

			for (var i = this.nb_lines; i > nb_lines; i--)
			{
				this.lineCounter.removeChild(lines[i-1]);
			}

			var delim = this.lineCounter.lastChild;

			for (var i = lines.length; i < nb_lines; i++)
			{
				var b = document.createElement('b');
				b.innerHTML = i+1;
				this.lineCounter.insertBefore(b, delim);
			}

			this.nb_lines = nb_lines;
		}

		if (line != this.current_line)
		{
			var lines = this.lineCounter.getElementsByTagName('b');

			for (var i = 0; i < this.nb_lines; i++)
			{
				lines[i].className = '';
			}

			lines[line-1].className = 'current';
			this.current_line = line;
		}
	};

	codeEditor.prototype.countLines = function()
	{
		var match = this.textarea.value.match(/(\r?\n)/g);
		return match ? match.length + 1 : 1;
	};

	codeEditor.prototype.getLineNumberFromPosition = function(s)
	{
		var s = s || this.getSelection();

		if (s.start == 0)
		{
			return 1;
		}

		var match = this.textarea.value.substr(0, s.start).match(/(\r?\n)/g)
		return match ? match.length + 1 : 1;
	};

	codeEditor.prototype.getLines = function ()
	{
		return this.textarea.value.split("\n");
	};

	codeEditor.prototype.getLinePosition = function (lines, line)
	{
		var start = 0;

		for (i = 0; i < lines.length; i++)
		{
			if (i == line - 1)
			{
				return {start: start + i, end: start + lines[i].length, length: lines[i].length, text: lines[i]};
			}

			start += lines[i].length;
		}

		return false;
	};

	codeEditor.prototype.goToLine = function (e)
	{
		var line = window.prompt(that.params.lang.goto);
		if (!line) return;

		var l = this.textarea.value.split("\n", parseInt(line, 10)).join("\n").length;
		this.scrollToSelection(this.setSelection(l, l));

		return true;
	};

	codeEditor.prototype.indent = function (e, key)
	{
		var s = this.getSelection();
		var unindent = e.shiftKey;

		var lines = this.getLines();
		var line = this.getLineNumberFromPosition(s);
		var line_sel = this.getLinePosition(lines, line);
		var line_start = line_sel.text.substr(line_sel.start, s.start - line_sel.start);
		
		if (!line_start.match(/^\s*$/))
		{
			if (!unindent)
			{
				this.insertAtPosition(s.start, "\t");
			}

			return true;
		}

		var previousIndentation = '';

		if (s.length == 0)
		{
			s.end = s.start + this.textarea.value.substr(s.start).indexOf("\n");
		}

		var txt = this.textarea.value.substr(s.start, (s.end - s.start));
		var lines = txt.split("\n");

		if (unindent)
		{
			var r = new RegExp('^[ ]{1,'+this.params.indent_size+'}');

			for (var i = 0; i < lines.length; i++)
			{
				lines[i] = lines[i].replace(/\t/g, ' '.repeat(this.params.tab_size));
				lines[i] = lines[i].replace(r, '');
			}
		}
		else
		{
			for (var i = 0; i < lines.length; i++)
			{
				lines[i] = ' '.repeat(this.params.indent_size) + lines[i];
			}
		}

		txt = lines.join("\n");
		this.replaceSelection(s, txt);
		return true;
	};

	codeEditor.prototype.search = function()
	{
		if (!(this.search_str = window.prompt(this.params.lang.search, this.search_str)))
			return;

		this.search_pos = 0;
		return this.searchNext();
	};

	codeEditor.prototype.searchNext = function()
	{
		if (!this.search_str) return true;

		var s = this.getSelection();

		var pos = s.end >= this.search_pos ? this.search_pos : s.start;
		var txt = this.textarea.value.substr(pos);

		var r = this.getSearchRegexp(this.search_str);
		var found = txt.search(r);

		if (found == -1)
		{
			return window.alert(this.params.lang.no_search_result);
		}

		var match = txt.match(r);

		s.start = pos + found;
		s.end = s.start + match[0].length;
		s.length = match[0].length;
		s.text = match[0];

		this.setSelection(s.start, s.end);
		this.search_pos = s.end;
		this.scrollToSelection(s);

		return true;
	};

	codeEditor.prototype.getSearchRegexp = function(str, global)
	{
		var r, m;

		if (str.substr(0, 1) == '/')
		{
			var pos = str.lastIndexOf("/");
			r = str.substr(1, pos-1);
			m = str.substr(pos+1).replace(/g/, '');
		}
		else
		{
			r = str.replace(/([\/$^.?()[\]{}\\])/, '\\$1');
			m = 'i';
		}

		if (global)
		{
			m += 'g';
		}

		return new RegExp(r, m);
	};

	codeEditor.prototype.searchAndReplace = function(e)
	{
		var selection = this.getSelection();
		var search_prompt = selection.length != 0 ? this.params.lang.search_selection : this.params.lang.search;

		if (!(s = window.prompt(search_prompt, this.search_str))
			|| !(r = window.prompt(that.params.lang.replace)))
		{
			return true;
		}

		var regexp = this.getSearchRegexp(s, true);

		if (selection.length == 0)
		{
			var nb = this.textarea.value.match(regexp).length;
			this.textarea.value = this.textarea.value.replace(regexp, r);
		}
		else
		{
			var nb = selection.text.match(regexp).length;
			this.replaceSelection(selection, selection.text.replace(regexp, r));
		}

		window.alert(this.params.lang.replace_result.replace(/%d/g, nb));

		return true;
	};

	codeEditor.prototype.enter = function (e)
	{
		var selection = this.getSelection();
		var line = this.getLineNumberFromPosition(selection);
		line = this.getLines()[line-1];

		var match = line.match(/^(\s+)/);

		if (!match)
			return false;

		this.insertAtPosition(selection.start, "\n" + match[1]);
		return true;
	};

	codeEditor.prototype.backspace = function(e)
	{
		var s = this.getSelection();

		if (s.length > 0)
		{
			return false;
		}

		var txt = this.textarea.value.substr(s.start - 2, 2);

		if (txt == '""' || txt == "''" || txt == '{}' || txt == '()' || txt == '[]')
		{
			s.start -= 2;
			this.replaceSelection(s, '');
			return true;
		}

		// Unindent
		var txt = this.textarea.value.substr(s.start - 20, 20);

		if ((pos = txt.search(/\n(\s+)$/m)) != -1)
		{
			s.start -= (20 - pos) - 1;
			this.replaceSelection(s, '');
			return true;
		}

		return false;
	};

	codeEditor.prototype.insertBrackets = function(e, key)
	{
		var s = this.getSelection();
		var o = key;
		var c = o;

		switch (o)
		{
			case '(': c = ')'; break;
			case '[': c = ']'; break;
			case '{': c = '}'; break;
		}

		if (s.length == 0)
		{
			this.insertAtPosition(s.start, o+c, s.start+1);
		}
		else
		{
			this.wrapSelection(s, o, c);
		}

		return true;
	};
}());
