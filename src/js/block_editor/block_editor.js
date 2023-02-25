window.blockEditor = class
{
	L10N = {
		'Add': 'Ajouter', // New block
		'Delete': 'Supprimer',
		'Delete columns': 'Supprimer colonnes',
		'Move down': 'Vers le bas',
		'Move up': 'Vers le haut',
		'No block has been selected': 'Aucun bloc n\'est sélectionné',
		'Delete this block?': 'Supprimer ce bloc ?',
		'Select a block type': 'Choisir un type de bloc',
		'Delete all columns on this line?': 'Supprimer toutes les colonnes sur cette ligne ?',
		'Columns': 'Colonnes',
		'Heading': 'Titre',
		'Text': 'Texte',
		'Select a columns template': 'Sélectionner un modèle de colonnes',
		'Change type': 'Changer de type',
		'Caption:': 'Légende :'
	};

	_(str) {
		return str in this.L10N ? this.L10N[str] : str;
	}

	constructor(textarea) {
		this.textarea = textarea;
		this.types = {};
		this.block_separator = "\n\n====\n\n";
		this.editor = null;
		this.blocks = null;
		this.focused = null;
		this.toolbar = null;
	}

	init () {
		this.textarea.style.visibility = 'hidden';
		this.textarea.style.position = 'absolute';
		this.editor = document.createElement('div');
		this.editor.className = 'block-editor';

		this.toolbar = this.buildToolbar();
		this.editor.appendChild(this.toolbar);

		this.blocks = document.createElement('div');
		this.blocks.className = 'editor';
		this.editor.appendChild(this.blocks);

		let blocks = this.parse(this.textarea.value);

		this.textarea.parentNode.insertBefore(this.editor, this.textarea)

		blocks.forEach((block) => {
			this.addBlock(block.type, null, block.meta, block.content);
		});

	}

	/**
	 * Parse a string into an object
	 * See blocks string format for details
	 */
	parse (text) {
		text = text.replace(/\r\n?/g, "\n");
		let parts = text.split(this.block_separator);
		let blocks = [];

		for (var i = 0; i < parts.length; i++) {
			let pos = parts[i].indexOf("\n\n")

			// No double line break: this block does not have content
			if (pos == -1) {
				pos = parts[i].length;
			}

			let header = parts[i].substr(0, pos).split("\n");
			let content = parts[i].length == pos ? '' : parts[i].substr(pos + 2);
			let meta = {}

			for (var j = 0; j < header.length; j++) {
				let pos = header[j].indexOf(":");
				let key = header[j].substr(0, pos).trim().toLowerCase();
				let value = header[j].substr(pos + 1).trim();
				meta[key] = value;
			}

			if (!meta.type) {
				throw Error('block #' + i + ': no type was supplied');
			}

			blocks.push({'type': meta.type, 'meta': meta, 'content': content.length ? content : null});
		}

		return blocks;
	}

	/**
	 * Export blocks as flat-text
	 */
	export() {
		let src = this.blocks.querySelectorAll('div[data-block]');
		let blocks = [];

		for (var i = 0; i < src.length; i++) {
			let block = src[i].block.export();
			let content = block.content ? "\n\n" + block.content : '';
			let header = ['Type: ' + src[i].dataset.type];

			if (block.meta) {
				Object.entries(block.meta).forEach((e) => {
					const [k, v] = e;
					header.push(k.substr(0, 1).toUpperCase() + k.substr(1) + ': ' + v);
				});
			}

			blocks.push(header.join("\n") + content);
		}

		return blocks.join(this.block_separator);
	}

	openDialog (class_name, content) {
		let dialog = document.createElement('dialog');
		dialog.className = class_name;
		dialog.open = true;
		dialog.appendChild(content);

		this.editor.appendChild(dialog);
	}

	closeDialogs () {
		this.editor.querySelectorAll('dialog').forEach((e) => e.remove());
	}

	/**
	 * Prompts the user to choose a block type to insert
	 */
	newBlockPrompt (after_block, callback) {
		let prompt = document.createElement('div');

		let label = document.createElement('h3');
		label.innerText = this._('Select a block type');
		prompt.appendChild(label);

		// Create a button for each block type
		for (const t in this.types) {
			if (!this.types[t].getAddButtonHTML) {
				continue;
			}

			let btn = document.createElement('button');
			btn.type = 'button';
			btn.onclick = () => {
				this.closeDialogs();

				if (this.types[t].newBlockPrompt) {
					this.types[t].newBlockPrompt(this, after_block);
				}
				else {
					let n = this.addBlock(t, after_block);

					if (callback) {
						callback(n);
					}
				}

				return false;
			};
			btn.innerHTML = this.types[t].getAddButtonHTML(this);
			prompt.appendChild(btn);
		}

		this.openDialog('new-block', prompt);
	}

	/**
	 * Add a new block of type 'type' after 'after_block'
	 */
	addBlock (type, after_block, meta, content) {
		if (!(type in this.types)) {
			throw Error(type + ': unknown block type');
		}

		let container = document.createElement('div');
		container.className = 'block '  + type;
		container.setAttribute('data-type', type);
		container.setAttribute('data-block', 1);

		let block = new this.types[type](meta, container, this);
		block.type = type;

		// Useful cross-references between DOM and block object
		block.container = container;
		container.block = block;

		if (content) {
			block.setContent(content);
		}

		let parent, after_element;
		let parent_column, parent_grid;

		// Find parent grid and parent column
		if (after_block && after_block.type == 'grid') {
			parent_grid = after_block;
		}
		else if (after_block && after_block.type == 'column') {
			parent_column = after_block;
		}
		else if (after_block) {
			parent_column = after_block.container.parentNode.block;
		}

		if (parent_column) {
			parent_grid = parent_column.container.parentNode.block;
		}

		// Always add grids to root element
		if (type == 'grid') {
			parent = this.blocks;
			after_element = parent_grid ? parent_grid.container.nextElementSibling : null;
		}
		// Always add columns to a grid
		else if (type == 'column') {
			parent = parent_grid ? parent_grid.container : this.blocks.lastElementChild;
			after_element = parent_column ? parent_column.container.nextElementSibling : null;
		}
		// Other blocks can be added in other places
		else if (after_block) {
			parent = parent_column.container;
			after_element = after_block.type == 'column' ? null : after_block.container.nextElementSibling;
		}
		// No positioning: just append to last element
		else {
			let last_column = this.blocks.querySelector('[data-type="column"]:last-child');
			parent = last_column;
			after_element = null;
		}

		parent.insertBefore(container, after_element);

		if (block.onload) {
			block.onload();
		}

		return block;
	}

	/**
	 * Delete a block
	 */
	deleteBlock (block) {
		let prev_focus;

		this.blocks.querySelectorAll('div[data-block]').forEach((e) => {
			if (e.block === block) {
				return;
			}

			if (e.block.focus) {
				prev_focus = e.block;
			}
		});

		if (this.focused === block) {
			this.focused = null;
		}

		block.container.remove();

		if (prev_focus) {
			prev_focus.focus();
		}
	}

	deleteGrid (block) {
		let e = block;

		while (e = e.container.parentNode.block) {
			if (e.type != 'grid') {
				continue;
			}

			e.container.remove();
			return;
		}
	}

	moveBlockDown (block) {
		if (block.container.nextElementSibling) {
			block.container.parentNode.insertBefore(block.container.nextElementSibling, block.container);
		}

		this.focus(block);
	}

	moveBlockUp (block) {
		if (block.container.previousElementSibling) {
			block.container.parentNode.insertBefore(block.container, block.container.previousElementSibling);
		}

		this.focus(block);
	}

	moveBlockTo (block, position) {
		// FIXME
	}

	duplicateBlock (block) {
		// FIXME
	}

	/**
	 * Change a block type
	 */
	toggleTypePrompt (block) {
		this.newBlockPrompt(block, (n) => {
			n.setContent(block.getContent());
			this.deleteBlock(block);
		});
	}

	focus(block) {
		if (this.focused) {
			this.focused.container.classList.remove('focus');
		}

		this.focused = block;
		block.container.classList.add('focus');

		if (block.focus) {
			block.focus();
		}
	}

	buildToolbar () {
		let t = document.createElement('div');
		t.className = 'toolbar';

		let createBtn = function (class_name, icon, label, callback) {
			let btn = document.createElement('button');
			btn.type = 'button';
			btn.onclick = callback;
			btn.className = class_name;
			//btn.setAttribute('data-icn', icon);
			btn.innerHTML = `<b data-icn="${icon}"></b> ${label}`;
			return btn;
		};

		t.appendChild(createBtn('new-block', '➕', this._('Add'), () => this.newBlockPrompt(this.focused)));
		t.appendChild(createBtn('change-type', '🗘', this._('Change type'), () => this.toggleTypePrompt(this.focused)));
		t.appendChild(createBtn('delete-block', '✘', this._('Delete'), () => {
			if (!this.focused) return !alert(this._('No block has been selected'));
			if (!window.confirm(this._('Delete this block?'))) return false;
			this.deleteBlock(this.focused);
		}));
		t.appendChild(createBtn('delete-grid', '🮔', this._('Delete columns'), () => {
			if (!this.focused) return !alert(this._('No block has been selected'));
			if (!window.confirm(this._('Delete all columns on this line?'))) return false;
			this.deleteGrid(this.focused);
		}));
		t.appendChild(createBtn('move-down', '↓', this._('Move down'), () => this.moveBlockDown(this.focused)));
		t.appendChild(createBtn('move-up', '↑', this._('Move up'), () => this.moveBlockUp(this.focused)));
		//t.appendChild(createBtn('move-left', '←', this._('Move left'), () => this.moveBlockLeft(this.focused)));
		//t.appendChild(createBtn('move-right', '→', this._('Move right'), () => this.moveBlockRight(this.focused)));

		return t;
	}
}

/**
 * Block class model
 */
window.blockEditor.block = class {
	/**
	 * Build the block editor, events, etc.
	 */
	constructor (meta, content, container, editor) {
		// Build the block
	}

	/**
	 * Return an object containing a meta object, and content string
	 * Example: {'meta': {'align': 'left'}, 'content': 'https://.../image.png'}
	 */
	export () {
		return {};
	}

	/**
	 * Will change content of editor
	 */
	//setContent(str) {}

	/**
	 * Called when the block needs to be focused (eg. if the previous block was deleted, or if just created)
	 */
	// focus() {}

	/**
	 * Modify the "new block" button supplied (eg. to set the icon and label)
	 * If this method does not exist, the block type won't be listed in the new block dialog
	 */
	// static getAddButton(button)

	/**
	 * Called after the HTML has been added to the editor
	 */
	//onload () {}

	/**
	 * If this method exists, it will be called after the click on the new block button is pressed for this type
	 * At this stage the block will not be created or added to the editor, this method should do it.
	 * This is useful for example for displaying a prompt or dialog, eg. choose an image to upload etc.
	 */
	//static newBlockPrompt(editor, after_block) {}
}