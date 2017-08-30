release:
	cd lib && zip -r KD2-5.6.zip KD2
	fossil uv rm KD2-5.6.zip
	fossil uv sync # This is to avoid a bug in Fossil 2.3
	fossil uv add lib/KD2-5.6.zip --as KD2-5.6.zip
	fossil uv sync
	rm -f lib/KD2-5.6.zip