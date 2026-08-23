PLUGIN_FILE := bulletin-upload-wordpress-plugin.php
PLUGIN_DIR := $(notdir $(CURDIR))
VERSION := $(shell sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' $(PLUGIN_FILE) | head -n 1)
ZIP_NAME := church-bulletin-publisher-$(VERSION).zip
ZIP_PATH := ../$(ZIP_NAME)

.PHONY: all zip clean info

all: zip

info:
	@echo "Plugin directory: $(PLUGIN_DIR)"
	@echo "Version:          $(VERSION)"
	@echo "Output:           $(ZIP_PATH)"

zip:
	@test -n "$(VERSION)" || (echo "ERROR: Could not read plugin version from $(PLUGIN_FILE)" >&2; exit 1)
	@command -v zip >/dev/null 2>&1 || (echo "ERROR: zip is not installed. On Ubuntu: sudo apt install zip" >&2; exit 1)
	@rm -f "$(ZIP_PATH)"
	@cd .. && zip -r "$(ZIP_NAME)" "$(PLUGIN_DIR)" \
		-x "$(PLUGIN_DIR)/.git/*" \
		   "$(PLUGIN_DIR)/.github/*" \
		   "$(PLUGIN_DIR)/*.zip" \
		   "$(PLUGIN_DIR)/.DS_Store" \
		   "$(PLUGIN_DIR)/Thumbs.db"
	@echo
	@echo "Built: $(ZIP_PATH)"

clean:
	@rm -f "$(ZIP_PATH)"
	@echo "Removed: $(ZIP_PATH)"
