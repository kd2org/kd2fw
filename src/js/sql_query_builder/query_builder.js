(function () {
	var qb = function (columns) {
		this.columns = columns;
	};

	function findAncestor (el, sel) {
		while ((el = el.parentElement) && !((el.matches || el.matchesSelector).call(el,sel)));
		return el;
	}

	window.SQLQueryBuilder = qb;

	qb.prototype.loadDefaultOperators = function () {
		// List of operators
		// ? is a binded value
		// ?? is a list of binded values (eg. IN(??) will be replaced by IN('42', '43'))
		// %?, ?% and %?% are for LIKE conditions, eg. %? will be replaced with '%query'
		// note that in those LIKE cases, % and _ characters will be escaped with a backslash
		this.operators = {
			"= ?": this.__("is equal to"),
			"!= ?": this.__("is not equal to"),
			"IN (??)": this.__("is equal to one of"),
			"NOT IN (??)": this.__("is not equal to one of"),
			"> ?": this.__("is greater than"),
			">= ?": this.__("is greater than or equal to"),
			"< ?": this.__("is less than"),
			"<= ?": this.__("is less than or equal to"),
			"BETWEEN ? AND ?": this.__("is between"),
			"NOT BETWEEN ? AND ?": this.__("is not between"),
			"IS NULL": this.__("is null"),
			"IS NOT NULL": this.__("is not null"),
			"LIKE ?%": this.__("begins with"),
			"NOT LIKE ?%": this.__("doesn't begin with"),
			"LIKE %?": this.__("ends with"),
			"NOT LIKE %?": this.__("doesn't end with"),
			"LIKE %?%": this.__("contains"),
			"NOT LIKE %?%": this.__("doesn't contain"),
			"&": this.__("matches one of"),
			"= 1": this.__("is true"),
			"= 0": this.__("is false"),
		};

		// Build list of operators per type
		this.types_operators = {
			"integer": ["= ?", "!= ?", "IN (??)", "NOT IN (??)", "> ?", ">= ?", "< ?", "<= ?", "BETWEEN ? AND ?", "NOT BETWEEN ? AND ?"],
			"enum": ["= ?", "!= ?", "IN (??)", "NOT IN (??)"],
			"boolean": ["= 1", "= 0"],
			"text": ["= ?", "!= ?", "IN (??)", "NOT IN (??)", "LIKE ?%", "NOT LIKE ?%", "LIKE %?", "NOT LIKE %?", "LIKE %?%", "NOT LIKE %?%"],
			"bitwise": ["&"],
		};

		// Build objects of operators per types
		for (var to in this.types_operators)
		{
			var list = {};
			for (var to_l in this.types_operators[to])
			{
				var op = this.types_operators[to][to_l];
				list[op] = this.operators[op];
			}
			this.types_operators[to] = list;
		}

		// Date and datetime are a bit special
		this.types_operators["date"] = JSON.parse(JSON.stringify(this.types_operators["integer"]));
		delete this.types_operators["date"]["<= ?"];
		delete this.types_operators["date"][">= ?"];
		this.types_operators["date"]["< ?"] = this.__("before");
		this.types_operators["date"]["> ?"] = this.__("after");
		this.types_operators["datetime"] = this.types_operators["date"];
	};

	/**
	 * Used to translate a string
	 * @param  string str Original string
	 * @return string Translated string
	 */
	qb.prototype.__ = function (str) {
		return str;
	};

	qb.prototype.init = function (targetElement) {
		this.parent = targetElement;
		var options = {'': '---'};
		for (column in this.columns)
		{
			options[column] = this.columns[column].label;
		}

		this.columnSelect = this.buildSelect(options);
	};

	qb.prototype.addGroup = function (targetParent, operator) {
		var f = document.createElement('fieldset');
		var l = document.createElement('legend');
		var s = this.buildSelect({
			"AND": this.__("Matches ALL of the following conditions:"),
			"OR": this.__("Matches ANY of the following conditions:"),
			"ADD": this.__("Add a new set of conditions below this one"),
			"DEL": this.__("Remove this set of conditions"),
		});
		s.onfocus = function () {
			this.oldValue = this.value;
		};
		s.value = operator;

		var _self = this;

		s.onchange = function () {
			if (this.value == 'DEL')
			{
				if (targetParent.childNodes.length == 1)
				{
					this.value = this.oldValue;
					return;
				}
				targetParent.removeChild(f);
			}
			else if (this.value == 'ADD')
			{
				var n = _self.addGroup(targetParent, 'AND');
				_self.addRow(n);
				this.value = this.oldValue;
			}
		};
		l.appendChild(s);
		f.appendChild(l);

		var table = document.createElement('table');
		f.appendChild(table);

		targetParent.appendChild(f);
		return f;
	};

	qb.prototype.addRow = function (targetGroup, after) {
		var targetTable = targetGroup.getElementsByTagName('table')[0];
		var row = document.createElement('tr');
		var cell = document.createElement('td');
		cell.className = 'buttons';

		var _self = this;

		var btn = this.buildInput('button', '+');
		btn.onclick = function () {
			_self.addRow(findAncestor(this, 'fieldset'), this.parentNode.parentNode);
		};

		cell.appendChild(btn);

		var btn = this.buildInput('button', '-');
		btn.onclick = function () {
			_self.deleteRow(this.parentNode.parentNode);
		};

		cell.appendChild(btn);
		row.appendChild(cell);

		var cell = document.createElement('td');
		cell.className = 'column';

		var select = this.columnSelect.cloneNode(true);
		select.onchange = function () { return _self.switchColumn(this); };
		cell.appendChild(select);
		row.appendChild(cell);

		var cell = document.createElement('td');
		cell.className = 'operator';
		row.appendChild(cell);

		var cell = document.createElement('td');
		cell.className = 'values';
		row.appendChild(cell);

		if (typeof after == 'undefined')
		{
			targetTable.appendChild(row);
		}
		else
		{
			targetTable.insertBefore(row, after.nextSibling);
		}

		return row;
	};

	qb.prototype.deleteRow = function (row) {
		if (row.parentNode.childNodes.length <= 1) return;
		row.parentNode.removeChild(row);
	}

	qb.prototype.switchColumn = function (columnSelect) {
		var row = columnSelect.parentNode.parentNode;
		row.childNodes[2].innerHTML = '';
		row.childNodes[3].innerHTML = '';
		this.addOperator(row, this.columns[columnSelect.value]);
	};

	qb.prototype.addOperator = function (targetRow, column) {
		var operators = this.types_operators[column.type];
		var options = {'': '---'};

		if (column.null)
		{
			operators["IS NULL"] = this.operators["IS NULL"];
			operators["IS NOT NULL"] = this.operators["IS NOT NULL"];
		}

		for (var o in operators)
		{
			options[o] = operators[o];
		}

		var select = this.buildSelect(options);

		var _self = this;
		select.onchange = function () { return _self.switchOperator(this); };
		targetRow.childNodes[2].appendChild(select);

		return select;
	};

	qb.prototype.switchOperator = function (operatorSelect, values) {
		var row = operatorSelect.parentNode.parentNode;

		// Clear the content, it's not the best for the user as if a text field is already
		// filled-in it will disappear if you change the operator, but it's a bit hard
		// to keep the first value, so for now we just clear everything (FIXME)
		row.childNodes[3].innerHTML = '';

		var parent = row.childNodes[3];
		var columnSelect = row.childNodes[1].firstChild;

		var operator = operatorSelect.value;
		var column = this.columns[columnSelect.value];

		if (!operator)
		{
			return;
		}

		var number = 1;
		var buttons = false;
		var prev = null;
		var params = operator.match(/\?/g);

		if (!params)
		{
			return;
		}
		else if (operator.match(/\?\?/))
		{
			number = values ? values.length : 3;
			buttons = true;
		}
		else if (params.length > 1)
		{
			number = params.length;
		}

		for (var i = 0; i < number; i++)
		{
			prev = this.addMatchField(parent, prev, column, operator);

			if (values)
			{
				prev.value = values[i];
			}
		}

		if (buttons)
		{
			// append add/remove values button
			var btn = this.buildInput('button', '-');
			btn.onclick = function () {
				if (this.parentNode.childNodes.length <= 3) return;
				this.parentNode.removeChild(this.previousSibling);
				this.parentNode.removeChild(this.previousSibling); // remove <br />
			};

			parent.appendChild(btn);

			var btn = this.buildInput('button', '+');
			var _self = this;

			btn.onclick = function () {
				_self.addMatchField(parent, this.previousSibling.previousSibling, column, operator);
			};

			parent.appendChild(btn);
		}
	};

	qb.prototype.addMatchField = function (targetParent, prev, column, operator) {
		if (column.type == 'enum')
		{
			var field = this.buildSelect(column.values);
		}
		else if (column.type == 'bitwise')
		{
			var field = document.createElement('span');

			for (var v in column.values)
			{
				var checkbox = this.buildInput('checkbox', v);
				var label = document.createElement('label');
				label.appendChild(checkbox);
				label.appendChild(document.createTextNode(' ' + column.values[v]));
				field.appendChild(label.cloneNode(true));
			}
		}
		else
		{
			var field = this.buildInput(column.type, '', column);
		}

		field = targetParent.insertBefore(field, prev ? prev.nextSibling : null);

		if (prev)
		{
			targetParent.insertBefore(document.createElement('br'), field);
		}

		return field;
	};

	qb.prototype.buildInput = function (type, label, column) {
		var i = document.createElement('input');
		i.type = type == 'integer' ? 'number' : type;
		i.value = label;
		return i;
	};

	qb.prototype.buildSelect = function (options) {
		var select = document.createElement('select');

		for (var i in options)
		{
			var option = document.createElement('option');
			option.value = i;
			option.innerHTML = options[i];
			select.appendChild(option);
		}

		return select;
	};

	qb.prototype.import = function (groups) {
		for (var g in groups)
		{
			if (groups[g].conditions.length == 0)
			{
				// Ignore empty groups
				continue;
			}

			var groupElement = this.addGroup(this.parent, groups[g].operator);

			for (var i in groups[g].conditions)
			{
				var condition = groups[g].conditions[i];
				var row = this.addRow(groupElement);
				row.childNodes[1].firstChild.value = condition.column;

				var operator = this.addOperator(row, this.columns[condition.column]);
				operator.value = condition.operator;

				this.switchOperator(operator, condition.values);
			}
		}
	};

	qb.prototype.export = function () {
		var source_groups = this.parent.querySelectorAll('table');
		var groups = [];

		for (var g in source_groups)
		{
			if (!source_groups.hasOwnProperty(g)) continue;

			g = source_groups[g];
			var rows = g.rows;
			var conditions = [];

			for (var i = 0; i < rows.length; i++)
			{
				var r = rows[i];
				if (!r.getElementsByTagName('select')[0].value)
				{
					// Ignore rows where the operator has not been selected
					continue;
				}

				// Fetch all values in an array
				var values = Array.prototype.slice.call(r.cells[3].querySelectorAll('input, select')).map(function (input) {
					if (input.type != 'button')
					{
						return input.value;
					}
				});

				var row = {
					"column": r.cells[1].firstChild.value,
					"operator": r.cells[2].firstChild.value,
					"values": values,
				};

				if (!row.operator)
				{
					continue;
				}

				if (row.operator.match(/\?\?/))
				{
					// Remove last two values, which are the +/- buttons
					row.values = row.values.slice(0, -2);
				}

				conditions.push(row);
			}

			groups.push({
				"operator": g.parentNode.firstChild.firstChild.value,
				"conditions": conditions,
			});
		}

		return groups;
	};
}());