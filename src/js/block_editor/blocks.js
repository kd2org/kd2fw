(function () {
	be.types.grid = class {
		static templates = {
			'none': 1, // Number of columns
			'none / 1fr 1fr': 2,
			'none / 1fr 1fr 1fr': 3,
			'none / 1fr 1fr 1fr 1fr': 4,
			'none / .5fr 1fr': 2,
			'none / 1fr .5fr': 2,
		};

		constructor(meta, content, container) {
			this.template = meta['grid-template'] ?? 'none';
			container.style = '--grid-template: ' + this.template;
		}
		export() {
			return {'meta': {'Grid-Template': this.template}};
		}
		html() {
			return null;
		}
		static newBlockPrompt(editor, after_block) {
			let c = document.createElement('div');
			c.innerHTML = `<h3>Sélectionner une grille</h3><div class="buttons">`;

			if (after_block.type == 'column') {
				after_block = after_block.container.parentNode.block;
			}

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

				for (var i = 0; i < v; i++) {
					btn.appendChild(document.createElement('span'));
				}

				c.querySelector('.buttons').appendChild(btn);
			})

			editor.openDialog('new-grid', c);
		}
		static getAddButtonHTML() {
			return '<b class="icn">O</b> Colonnes';
		}

	};

	be.types.column = class {
		constructor(meta, content, container, editor) {
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

	be.types.heading = class {
		constructor(meta, content, container, editor, input) {
			this.content = content;

			if (input) {
				this.input = input;
			}
			else {
				this.input = document.createElement('input');
				this.input.type = 'text';
			}

			this.input.value = this.content || '';

			// Automatically delete block if empty
			this.input.addEventListener('keydown', (e) => {
				if (e.key == 'Backspace' && this.input.value == '') {
					e.preventDefault();
					editor.deleteBlock(this);
					return false;
				}
			});
		}
		export() {
			return {'content': this.input.value};
		}
		html() {
			return this.input;
		}
		focus() {
			this.input.focus();
		}
		static getAddButtonHTML() {
			return '<b class="icn">H</b> Titre';
		}
	};

	be.types.text = class extends be.types.heading {
		constructor(meta, content, container, editor) {
			super(meta, content, container, editor, document.createElement('textarea'));
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

		static getAddButtonHTML() {
			return '<b class="icn">T</b> Texte';
		}
	};

}());