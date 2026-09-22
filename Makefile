PLUGIN_FILE := bulletin-upload-wordpress-plugin.php
PLUGIN_DIR := $(notdir $(CURDIR))
VERSION := $(shell sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' $(PLUGIN_FILE) | head -n 1)
ZIP_NAME := church-bulletin-publisher-$(VERSION).zip
ZIP_PATH := ../$(ZIP_NAME)
PARISH_PLUGIN_FILE := parish-forms/parish-forms.php
PARISH_VERSION := $(shell sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' $(PARISH_PLUGIN_FILE) | head -n 1)
PARISH_ZIP_NAME := parish-forms-$(PARISH_VERSION).zip
PARISH_ZIP_PATH := ../$(PARISH_ZIP_NAME)

.PHONY: all zip parish-forms plugins test-parish-forms clean info

all: plugins

plugins: zip parish-forms
	@echo
	@echo "Built both WordPress plugins."

info:
	@echo "Plugin directory: $(PLUGIN_DIR)"
	@echo "Version:          $(VERSION)"
	@echo "Output:           $(ZIP_PATH)"
	@echo "Parish Forms:     $(PARISH_ZIP_PATH)"

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

parish-forms:
	@test -n "$(PARISH_VERSION)" || (echo "ERROR: Could not read plugin version from $(PARISH_PLUGIN_FILE)" >&2; exit 1)
	@command -v zip >/dev/null 2>&1 || (echo "ERROR: zip is not installed. On Ubuntu: sudo apt install zip" >&2; exit 1)
	@rm -f "$(PARISH_ZIP_PATH)"
	@cd . && zip -r "$(PARISH_ZIP_PATH)" parish-forms \
		-x "parish-forms/*.zip" \
		   "parish-forms/tests/*" \
		   "parish-forms/.DS_Store" \
		   "parish-forms/Thumbs.db"
	@echo
	@echo "Built: $(PARISH_ZIP_PATH)"

test-parish-forms:
	@command -v php >/dev/null 2>&1 || (echo "ERROR: php is not installed" >&2; exit 1)
	@find parish-forms -name '*.php' -print0 | xargs -0 -n1 php -l
	@php parish-forms/tests/test-validation.php
	@command -v node >/dev/null 2>&1 && node --check parish-forms/assets/frontend.js || true

clean:
	@rm -f "$(ZIP_PATH)"
	@rm -f "$(PARISH_ZIP_PATH)"
	@echo "Removed: $(ZIP_PATH) and $(PARISH_ZIP_PATH)"
