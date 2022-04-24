(function () {
	/**
	 * Grid block
	 * This is a special kind of block, as it cannot have any content and must have only columns inside
	 */
	be.types.grid = class {
		static templates = {
			'none': 1, // Number of columns
			'none / 1fr 1fr': 2,
			'none / 1fr 1fr 1fr': 3,
			'none / 1fr 1fr 1fr 1fr': 4,
			'none / .5fr 1fr': 2,
			'none / 1fr .5fr': 2,
		};

		constructor(meta, container, editor) {
			this.template = meta['grid-template'] ?? 'none';
			container.style = '--grid-template: ' + this.template;
		}
		export() {
			return {'meta': {'Grid-Template': this.template}};
		}
		html() {
			return null;
		}

		static getAddButtonHTML(editor) {
			return '<b class="icn">▚</b> ' + editor._('Columns');
		}

		/**
		 * Dialog for creating a new block
		 */
		static newBlockPrompt(editor, after_block) {
			let c = document.createElement('div');
			c.innerHTML = '<h3>' + editor._('Select a columns template') + '</h3><div class="buttons">';

			if (after_block && after_block.type == 'column') {
				after_block = after_block.container.parentNode.block;
			}

			// For each grid template, create a button with a preview
			Object.entries(this.templates).forEach((e) => {
				const [k, v] = e;
				let btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'grid';
				btn.style = '--grid-template: ' + k;

				btn.onclick = () => {
					let grid = editor.addBlock('grid', after_block, {'grid-template' : k});

					for (var i = 0; i < v; i++) {
						editor.addBlock('column', grid);
					}

					editor.closeDialogs();
				};

				// Add as many columns as needed by the grid template
				for (var i = 0; i < v; i++) {
					btn.appendChild(document.createElement('span'));
				}

				c.querySelector('.buttons').appendChild(btn);
			})

			editor.openDialog('new-grid', c);
		}
	};

	/**
	 * Column block
	 * This cannot have any metadata or content, and can only be inside a grid block
	 */
	be.types.column = class {
		constructor(meta, container, editor) {
			container.addEventListener('dblclick', (e) => {
				if (e.target.tagName.toLowerCase() != 'div') {
					// Do not prompt if clicked outside of the div
					return;
				}

				editor.newBlockPrompt(this);
				e.preventDefault();
				return false;
			});
		}
		export() {
			return {};
		}
		html() {
			return null;
		}
	};

	/**
	 * Heading
	 */
	be.types.heading = class {
		constructor(meta, container, editor, input) {
			if (input) {
				this.input = input;
			}
			else {
				this.input = document.createElement('input');
				this.input.type = 'text';
			}

			// Automatically delete block if empty
			this.input.addEventListener('keydown', (e) => {
				if (e.key == 'Backspace' && this.input.value == '') {
					e.preventDefault();
					editor.deleteBlock(this);
					return false;
				}
			});

			this.input.addEventListener('focus', () => editor.focus(this));
		}
		setContent(str) {
			this.input.value = str;
		}
		getContent() {
			return this.input.value;
		}
		export() {
			return {'content': this.getContent()};
		}
		html() {
			return this.input;
		}
		focus() {
			this.input.focus();
		}
		static getAddButtonHTML(editor) {
			return '<b class="icn">H</b> ' + editor._('Heading');
		}
	};

	/**
	 * Textarea
	 */
	be.types.text = class extends be.types.heading {
		constructor(meta, container, editor) {
			super(meta, container, editor, document.createElement('textarea'));
			this.input.cols = 50;
			this.input.rows = 5;
			this.input.addEventListener('keyup', (e) => this.autoResize(e));
		}

		onload() {
			this.autoResize();
		}

		autoResize(e) {
			this.input.style.height = '1px';
			this.input.style.height = Math.max(32, this.input.scrollHeight)+"px";
		}

		static getAddButtonHTML(editor) {
			return '<b class="icn">T</b> ' + editor._('Text');
		}
	};
}());