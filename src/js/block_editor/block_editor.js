window.blockEditor = class
{
	constructor(textarea) {
		this.textarea = textarea;
		this.types = {};
		this.block_separator = "\n\n====\n\n";
		this.editor = null;
		this.blocks = null;
	}

	init () {
		this.textarea.style.visibility = 'hidden';
		this.textarea.style.position = 'absolute';
		this.editor = document.createElement('div');
		this.editor.className = 'block-editor';
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
	newBlockPrompt (after_block) {
		let prompt = document.createElement('div');

		let label = document.createElement('h3');
		label.innerText = 'Sélectionner un type de bloc';
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
					this.addBlock(t, after_block);
				}

				return false;
			};
			btn.innerHTML = this.types[t].getAddButtonHTML();
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

		let block = new this.types[type](meta, content, container, this);
		block.type = type;

		// Useful cross-references between DOM and block object
		block.container = container;
		container.block = block;

		let html = block.html();

		if (null !== html) {
			container.appendChild(html);
		}

		let parent;
		let after_element = null;

		if (after_block) {
			if (after_block.type == 'column' && after_block.container.lastElementChild) {
				parent = after_block.container;
				after_element = parent.lastElementChild.nextElementSibling;
			}
			else if (after_block.type == 'column') {
				parent = after_block.container;
			}
			else if (after_block.type == 'grid') {
				// Append columns to grid
				if (type == 'column') {
					parent = after_block.container;
					after_element = parent.lastElementChild;
				}
				else {
					parent = this.blocks;
					after_element = after_block.container.nextElementSibling;
				}
			}
			else {
				// Just insert inside block
				after_element = after_block.container.nextElementSibling;
				parent = after_block.container.parentNode;
			}
		}
		else if (this.blocks.lastElementChild && this.blocks.lastElementChild.block.type == 'grid') {
			if (block.type == 'column') {
				// Append column to grid
				parent = this.blocks.lastElementChild;
			}
			else {
				// Append to column
				parent = this.blocks.lastElementChild.lastElementChild;
			}
		}
		else {
			parent = this.blocks;
		}

		parent.insertBefore(container, after_element);

		if (block.onload) {
			block.onload();
		}

		return block;
	}

	nextBlock (block) {
		let list = this.blocks.querySelectorAll('div[data-block]');

		for (var i = 0; i < list.length; i++) {
			if (list[i] == block) {
				if (list[i+1]) {
					return list[i+1];
				}

				return null;
			}
		}

		return null;
	}

	prevBlock (block) {
		let list = this.blocks.querySelectorAll('div[data-block]');

		for (var i = 0; i < list.length; i++) {
			if (list[i] == block) {
				if (list[i-1]) {
					return list[i-1];
				}

				return null;
			}
		}

		return null;
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

		block.container.remove();

		if (prev_focus) {
			prev_focus.focus();
		}
	}

	moveBlockDown (block) {
		if (!block.nextElementSibling) {
			return;
		}

		block.parentNode.insertBefore(block.nextElementSibling, block);
	}

	moveBlockUp (block) {
		if (!block.previousElementSibling) {
			return;
		}

		block.parentNode.insertBefore(block, block.previousElementSibling);
	}

	moveBlockTo (block, position) {
		// FIXME
	}

	duplicateBlock (block) {
		this.addBlock(block.type, block, block.meta, block.content);
	}

	/**
	 * Change a block type
	 */
	toggleTypePrompt (block) {
	}

	/**
	 * Change a block type
	 */
	toggleType (block, new_type) {
		this.addBlock(new_type, block, {}, block.content);
		this.deleteBlock(block);
	}

	getFocusedBlock() {
		if (!document.activeElement) {
			return null;
		}

		if (!(document.activeElement in this.editor)) {
			return null;
		}

		let p = document.activeElement;

		while (p && !p.block) {
			if (!(p in this.editor)) {
				return null;
			}

			p = p.parentNode;
		}

		return p;
	}

	focus() {
		console.log(this.getFocusedBlock());
	}

	toolbar () {
		
	}
}
