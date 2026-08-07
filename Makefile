# Makefile — walk the project's build history.
#
# Thin wrapper over scripts/step.sh: the step table and every guard live there,
# so `make next` and `./scripts/step.sh next` cannot drift apart.
#
#   make              this help
#   make list         every step, current one marked
#   make next         forward one step
#   make prev         back one step
#   make first        jump to the first step
#   make final        back to the tip of main
#   make step N=2     jump to step 2
#   make step-2       same thing
#   make status       where am I
#   make diff         what the current step changed
#
# Add MIGRATE=1 to rebuild the database after switching (destroys its data):
#   make next MIGRATE=1
#
# Add FORCE=1 to switch with uncommitted changes (git may still refuse):
#   make prev FORCE=1

STEP := ./scripts/step.sh

# Translated to flags rather than passed through, so the Makefile's interface
# stays make-ish (VAR=1) while the script keeps its own (--flag).
FLAGS :=
ifeq ($(MIGRATE),1)
FLAGS += --migrate
endif
ifeq ($(FORCE),1)
FLAGS += --force
endif

.DEFAULT_GOAL := help

.PHONY: help list next prev first final status diff test

# Prints the header comment above, stopping at the first line that is not a
# comment. A hardcoded line range silently starts printing code the moment
# anyone edits the header.
help:
	@awk '/^#/ { sub(/^# ?/, ""); print; next } { exit }' $(MAKEFILE_LIST)

list:
	@$(STEP) list

next:
	@$(STEP) next $(FLAGS)

prev:
	@$(STEP) prev $(FLAGS)

first:
	@$(STEP) first $(FLAGS)

final:
	@$(STEP) final $(FLAGS)

status:
	@$(STEP) status

diff:
	@$(STEP) diff

# `make step N=2`. N is required — without it make would silently jump to the
# script's default command and land somewhere the caller did not ask for.
.PHONY: step
step:
ifndef N
	@echo "Usage: make step N=<number>   (or: make step-<number>)" >&2
	@echo "Run 'make list' to see the steps." >&2
	@exit 1
else
	@$(STEP) $(N) $(FLAGS)
endif

# `make step-2`. Pattern rule rather than a catch-all `%:`, which would swallow
# every mistyped target and try to check it out as a step.
.PHONY: step-%
step-%:
	@$(STEP) $* $(FLAGS)
